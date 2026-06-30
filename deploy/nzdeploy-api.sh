#!/bin/bash
# 哪吒后端部署: 干净ref发布 + 原子切换 + 健康门 + 自动回滚
# 用法: node nz.js run "bash /www/wwwroot/api-deploy/nzdeploy-api.sh"
# 只部署已 commit+push 到 origin/main 的代码(后端存盘不再即上线)。
set -u
DEPLOY=/www/wwwroot/api-deploy
SHARED=$DEPLOY/shared
SRC=/www/wwwroot/api.nezha.am
FPMPID=$(cat /www/server/php/82/var/run/php-fpm.pid)
LOCK=/tmp/nezha_apideploy.lock
LOG=/tmp/nezha_apideploy_last.log
KEEP=5
exec 9>"$LOCK" || { echo "[deploy] lock fail"; exit 1; }
flock -w 300 9 || { echo "[deploy] wait-lock timeout, deploy in progress"; exit 1; }
: > "$LOG"

code(){ curl -s -o /dev/null -m8 -w '%{http_code}' "https://api.nezha.am$1"; }
healthy(){ [ "$(code /api/v1/config)" = 200 ] && [ "$(code /api/v1/zone/list)" = 200 ] && [ "$(code /login/restaurant)" = 200 ]; }

echo "[deploy] $(date '+%H:%M:%S') fetch origin/main"
git -C "$SRC" fetch origin main >>"$LOG" 2>&1
SHA=$(git -C "$SRC" rev-parse --short origin/main)
REL="$DEPLOY/releases/$(date +%Y%m%d-%H%M%S)-$SHA"
echo "[deploy] release=$REL (origin/main=$SHA)"
mkdir -p "$REL"
git -C "$SRC" archive "$SHA" | tar -x -C "$REL"
mkdir -p "$REL/bootstrap/cache"
rm -rf "$REL/storage"; ln -s "$SHARED/storage" "$REL/storage"
rm -f "$REL/.env"; ln -s "$SHARED/.env" "$REL/.env"
ln -sfn "$REL/storage/app/public" "$REL/public/storage"

CUR=$(readlink "$DEPLOY/current" 2>/dev/null)
if [ -n "$CUR" ] && [ -f "$CUR/composer.lock" ] && [ -d "$CUR/vendor" ] && cmp -s "$REL/composer.lock" "$CUR/composer.lock" && cp -al "$CUR/vendor" "$REL/vendor"; then
    VMODE="vendor-hardlink-reuse"
else
    # 哪吒[防 vendor 级联 2026-06-24]: 上个 release 无 vendor/ 或硬链失败时不留空 vendor, 回退 composer install。
    rm -rf "$REL/vendor"
    VMODE="composer-install"
fi
chown -R www:www "$REL"
[ "$VMODE" = "composer-install" ] && (cd "$REL" && sudo -u www -H composer install --no-dev --optimize-autoloader >>"$LOG" 2>&1)
echo "[deploy] $VMODE"

# 哪吒[vendor 断言 2026-06-24]: 无论哪种模式 autoload 必须就位, 否则整个 release 不可用→worker 回收即全站500。fail-fast 不切 current, 线上仍跑旧 release。
if [ ! -f "$REL/vendor/autoload.php" ]; then
    echo "[deploy] FATAL: $REL/vendor/autoload.php 缺失 (VMODE=$VMODE) -- 终止部署, 不切换 current, 线上仍跑旧 release"
    exit 1
fi

(cd "$REL" && sudo -u www -H php artisan package:discover --ansi >>"$LOG" 2>&1)
(cd "$REL" && sudo -u www -H php artisan migrate --force >>"$LOG" 2>&1)
(cd "$REL" && sudo -u www -H php artisan generate:admin-route >>"$LOG" 2>&1; sudo -u www -H php artisan generate:restaurant-route >>"$LOG" 2>&1)

# 哪吒[blade 编译探针 2026-06-26]: 切 current 前, 对本次相对上个 release 改动的 blade 做真实编译检查。
# @php 误编译 / 源码多括号 typo 等畸形 php -l 测不出, FPM include 编译产物时才 fatal → 整页 500。
# 失败则不切 current, 线上仍跑旧 release(零 500 窗口)。git/PREV_SHA 异常时降级放行(2>/dev/null→空)。详见 nzcheck-blade.php。
if [ -n "$CUR" ]; then
    PREV_SHA=$(basename "$CUR" | sed 's/.*-//')
    CHANGED_BLADE=$(git -C "$SRC" diff --name-only "$PREV_SHA" "$SHA" -- '*.blade.php' 2>/dev/null)
    if [ -n "$CHANGED_BLADE" ]; then
        if ! sudo -u www -H php "$DEPLOY/nzcheck-blade.php" "$REL" $CHANGED_BLADE >>"$LOG" 2>&1; then
            echo "[deploy] FATAL: blade 编译探针失败 -- 不切 current, 线上仍跑旧 release:"
            grep 'blade-probe' "$LOG" | tail -8
            exit 1
        fi
        echo "[deploy] blade-probe OK ($(echo "$CHANGED_BLADE" | wc -l) changed blade)"
    fi
fi

PREV_TARGET="$CUR"
ln -sfn "$REL" "$DEPLOY/current"
[ -n "$PREV_TARGET" ] && ln -sfn "$PREV_TARGET" "$DEPLOY/previous"
kill -USR2 "$FPMPID"
sleep 2

if healthy; then
    echo "[deploy] OK $SHA  config=$(code /api/v1/config) zone=$(code /api/v1/zone/list) login=$(code /login/restaurant)"
    echo "[deploy] --- 上线前硬自检: COD 必须关闭 ---"
    bash /www/wwwroot/api.nezha.am/nzcheck-cod.sh || echo "[deploy] [31m🔴 警告: COD 自检未通过(见上), 不阻断部署但请立即去后台关闭货到付款![0m"
    CURT=$(readlink "$DEPLOY/current"); PRVT=$(readlink "$DEPLOY/previous" 2>/dev/null)
    ls -1dt "$DEPLOY"/releases/*/ 2>/dev/null | tail -n +$((KEEP+1)) | while read d; do
        dd="${d%/}"
        [ "$dd" = "$CURT" ] && continue
        [ "$dd" = "$PRVT" ] && continue
        rm -rf "$dd"
    done
else
    echo "[deploy] HEALTH FAIL -> rollback to previous"
    [ -n "$PREV_TARGET" ] && ln -sfn "$PREV_TARGET" "$DEPLOY/current"
    kill -USR2 "$FPMPID"; sleep 2
    echo "[deploy] after-rollback config=$(code /api/v1/config)  bad-release kept=$REL  log=$LOG"
    exit 1
fi
