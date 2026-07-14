#!/bin/bash
# 哪吒后端部署: 干净ref发布 + 原子切换 + 健康门 + 自动回滚
# 用法: node nz.js run "bash /www/wwwroot/api-deploy/nzdeploy-api.sh <40-hex-commit-sha>"
# 只部署调用者明确指定、且已 commit+push 到 origin/main 的完整 SHA; 禁止隐式 latest main。
set -u
set -o pipefail
DEPLOY=/www/wwwroot/api-deploy
SHARED=$DEPLOY/shared
SRC=/www/wwwroot/api.nezha.am
LOCK=/tmp/nezha_apideploy.lock
LOG=/tmp/nezha_apideploy_last.log
KEEP=5

usage(){ echo "usage: $0 <40-hex-commit-sha>" >&2; }
if [ "$#" -ne 1 ] || ! [[ "$1" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "[deploy] FATAL: an explicit full 40-hex target SHA is required; implicit latest main is forbidden" >&2
    usage
    exit 64
fi
TARGET_SHA="${1,,}"
SHORT_SHA="${TARGET_SHA:0:7}"

current_target(){ readlink "$DEPLOY/current" 2>/dev/null || true; }
resolve_main(){ git -C "$SRC" rev-parse --verify 'origin/main^{commit}' 2>/dev/null || true; }
resolve_target(){ git -C "$SRC" rev-parse --verify "$TARGET_SHA^{commit}" 2>/dev/null || true; }
lock_current_unchanged(){
    LOCKED_CURRENT=$(current_target)
    if [ "$LOCKED_CURRENT" != "$REQUESTED_CURRENT" ]; then
        echo "[deploy] FATAL: current changed while waiting for lock (was=$REQUESTED_CURRENT now=$LOCKED_CURRENT); refusing stale queued deploy" >&2
        return 1
    fi
    return 0
}
validate_target_against_main(){
    FETCHED_MAIN=$(resolve_main)
    RESOLVED_TARGET=$(resolve_target)
    if [ -z "$FETCHED_MAIN" ] || [ "$RESOLVED_TARGET" != "$TARGET_SHA" ]; then
        echo "[deploy] FATAL: target does not resolve exactly after fetch (target=$TARGET_SHA resolved=$RESOLVED_TARGET main=$FETCHED_MAIN)" >&2
        return 1
    fi
    if ! git -C "$SRC" merge-base --is-ancestor "$TARGET_SHA" "$FETCHED_MAIN"; then
        echo "[deploy] FATAL: target $TARGET_SHA is not reachable from fetched origin/main $FETCHED_MAIN" >&2
        return 1
    fi
    return 0
}
assert_deploy_snapshot(){
    local stage="$1" live_current live_main live_target
    live_current=$(current_target)
    if [ "$live_current" != "$LOCKED_CURRENT" ]; then
        echo "[deploy] FATAL: current drift at $stage (was=$LOCKED_CURRENT now=$live_current); refusing stale/racing deploy" >&2
        return 1
    fi
    live_main=$(resolve_main)
    if [ "$live_main" != "$FETCHED_MAIN" ]; then
        echo "[deploy] FATAL: origin/main drift at $stage (was=$FETCHED_MAIN now=$live_main); refusing deploy" >&2
        return 1
    fi
    live_target=$(resolve_target)
    if [ "$live_target" != "$TARGET_SHA" ]; then
        echo "[deploy] FATAL: target SHA no longer resolves exactly at $stage (wanted=$TARGET_SHA got=$live_target); refusing deploy" >&2
        return 1
    fi
    return 0
}

REQUESTED_CURRENT=$(current_target)
exec 9>"$LOCK" || { echo "[deploy] lock fail"; exit 1; }
flock -w 300 9 || { echo "[deploy] wait-lock timeout, deploy in progress"; exit 1; }
lock_current_unchanged || exit 75
: > "$LOG"
FPMPID=$(cat /www/server/php/82/var/run/php-fpm.pid)

code(){ curl -s -o /dev/null -m8 -w '%{http_code}' "https://api.nezha.am$1"; }
healthy(){ [ "$(code /api/v1/config)" = 200 ] && [ "$(code /api/v1/zone/list)" = 200 ] && [ "$(code /login/restaurant)" = 200 ]; }

echo "[deploy] $(date '+%H:%M:%S') fetch origin/main"
if ! git -C "$SRC" fetch origin main >>"$LOG" 2>&1; then
    echo "[deploy] FATAL: fetch origin/main failed; refusing stale local ref" >&2
    tail -n 15 "$LOG"
    exit 1
fi
validate_target_against_main || exit 65
assert_deploy_snapshot "after fetch" || exit 75
REL="$DEPLOY/releases/$(date +%Y%m%d-%H%M%S)-$SHORT_SHA"
echo "[deploy] release=$REL target=$TARGET_SHA fetched-main=$FETCHED_MAIN"
mkdir -p "$REL"
if ! git -C "$SRC" archive "$TARGET_SHA" | tar -x -C "$REL"; then
    echo "[deploy] FATAL: archive extraction failed for target $TARGET_SHA" >&2
    exit 1
fi
printf '%s\n' "$TARGET_SHA" > "$REL/.nz-deploy-sha"
mkdir -p "$REL/bootstrap/cache"
rm -rf "$REL/storage"; ln -s "$SHARED/storage" "$REL/storage"
rm -f "$REL/.env"; ln -s "$SHARED/.env" "$REL/.env"
ln -sfn "$REL/storage/app/public" "$REL/public/storage"

CUR="$LOCKED_CURRENT"
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

assert_deploy_snapshot "before package preparation" || exit 75
(cd "$REL" && sudo -u www -H php artisan package:discover --ansi >>"$LOG" 2>&1)
assert_deploy_snapshot "before migration" || exit 75
(cd "$REL" && sudo -u www -H php artisan migrate --force >>"$LOG" 2>&1)
(cd "$REL" && sudo -u www -H php artisan generate:admin-route >>"$LOG" 2>&1; sudo -u www -H php artisan generate:restaurant-route >>"$LOG" 2>&1)
assert_deploy_snapshot "after package preparation" || exit 75

# 哪吒[blade 编译探针 2026-06-26]: 切 current 前, 对本次相对上个 release 改动的 blade 做真实编译检查。
# @php 误编译 / 源码多括号 typo 等畸形 php -l 测不出, FPM include 编译产物时才 fatal → 整页 500。
# 失败则不切 current, 线上仍跑旧 release(零 500 窗口)。git/PREV_SHA 异常时降级放行(2>/dev/null→空)。详见 nzcheck-blade.php。
if [ -n "$CUR" ]; then
    PREV_SHA=$(basename "$CUR" | sed 's/.*-//')
    CHANGED_BLADE=$(git -C "$SRC" diff --name-only "$PREV_SHA" "$TARGET_SHA" -- '*.blade.php' 2>/dev/null)
    if [ -n "$CHANGED_BLADE" ]; then
        if ! sudo -u www -H php "$DEPLOY/nzcheck-blade.php" "$REL" $CHANGED_BLADE >>"$LOG" 2>&1; then
            echo "[deploy] FATAL: blade 编译探针失败 -- 不切 current, 线上仍跑旧 release:"
            grep 'blade-probe' "$LOG" | tail -8
            exit 1
        fi
        echo "[deploy] blade-probe OK ($(echo "$CHANGED_BLADE" | wc -l) changed blade)"
    fi
fi

assert_deploy_snapshot "before current switch" || exit 75
PREV_TARGET="$CUR"
ln -sfn "$REL" "$DEPLOY/current"
[ -n "$PREV_TARGET" ] && ln -sfn "$PREV_TARGET" "$DEPLOY/previous"
kill -USR2 "$FPMPID"
sleep 2

if healthy; then
    echo "[deploy] OK $TARGET_SHA  config=$(code /api/v1/config) zone=$(code /api/v1/zone/list) login=$(code /login/restaurant)"
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
