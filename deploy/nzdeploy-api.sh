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
if ! : > "$LOG"; then
    echo "[deploy] FATAL: cannot initialize deploy log $LOG" >&2
    exit 1
fi
if ! FPMPID=$(cat /www/server/php/82/var/run/php-fpm.pid) || ! [[ "$FPMPID" =~ ^[0-9]+$ ]]; then
    echo "[deploy] FATAL: cannot read a valid PHP-FPM master pid" >&2
    exit 1
fi

# 哪吒[2026-07-01]: 健康检查直连 origin(127.0.0.1)绕过 Cloudflare 托管质询。健康门应测新版本应用本身,而非CF边缘bot防护;
# 否则CF对服务器出口IP发challenge(cf-mitigated: challenge)→403→误判不健康→回滚(本次事故根因)。
# --resolve 保留 SNI/Host=api.nezha.am, nginx 认 vhost + 证书匹配, 只是把连接强制打到本机 nginx。
code(){ curl -s -k -o /dev/null -m8 --resolve api.nezha.am:443:127.0.0.1 -w '%{http_code}' "https://api.nezha.am$1"; }
nz_wwrite(){ sudo -u www -H bash -c 'touch /www/wwwroot/api-deploy/shared/storage/framework/.nzp6probe && rm -f /www/wwwroot/api-deploy/shared/storage/framework/.nzp6probe' 2>/dev/null; }
healthy(){ [ "$(code /api/v1/config)" = 200 ] && [ "$(code /api/v1/zone/list)" = 200 ] && [ "$(code /login/restaurant)" = 200 ] && nz_wwrite; }
# 哪吒[R1 通知异步化 2026-07-01]: 切 current 后立即重启所有 queue worker, 让 worker 与 FPM 同步载新代码,
# 压缩 split-brain 窗口(新 FPM 派 job·旧 worker 无新 Job 类→反序列化丢)。覆盖多 worker; 新增 queue worker 名字加进下面循环。
restart_queue_workers(){
    local failed=0 w
    for w in nezha-queue nezha-queue-2; do
        if ! ( cd /home/www && sudo -u www -H PM2_HOME=/home/www/.pm2 /usr/bin/pm2 restart "$w" --update-env ) >>"$LOG" 2>&1; then
            echo "[deploy] ERROR: queue worker restart failed: $w" >&2
            failed=1
        fi
    done
    [ "$failed" -eq 0 ]
}
reload_fpm(){
    if ! kill -USR2 "$FPMPID"; then
        echo "[deploy] ERROR: PHP-FPM reload failed for pid $FPMPID" >&2
        return 1
    fi
    return 0
}
p6_deny_probe(){
    local current routes probe
    current=$(current_target)
    routes="$current/routes"
    probe="$routes/.nzp6probe"
    if [ -z "$current" ] || [ ! -d "$routes" ]; then
        echo "[deploy] ERROR: P6 deny-probe target is missing: $routes" >&2
        return 1
    fi
    if sudo -u www -H touch "$probe" 2>/dev/null; then
        rm -f "$probe" || true
        echo "[deploy] ERROR: P6 deny-probe failed: www can still write $routes" >&2
        return 1
    fi
    echo "[deploy] P6 deny-probe OK: www cannot write code tree"
    return 0
}
rollback_after_switch(){
    local reason="$1" rollback_failed=0
    echo "[deploy] $reason -> rollback to previous" >&2
    if [ -z "$PREV_TARGET" ]; then
        echo "[deploy] ERROR: no previous target is available for rollback" >&2
        rollback_failed=1
    elif ! ln -sfn "$PREV_TARGET" "$DEPLOY/current"; then
        echo "[deploy] ERROR: failed to restore current -> $PREV_TARGET" >&2
        rollback_failed=1
    fi
    if ! reload_fpm; then
        rollback_failed=1
    fi
    if ! restart_queue_workers; then
        rollback_failed=1
    fi
    sleep 2
    if [ -n "$PREV_TARGET" ] && ! healthy; then
        echo "[deploy] ERROR: previous release is unhealthy after rollback" >&2
        rollback_failed=1
    fi
    if [ "$rollback_failed" -eq 0 ]; then
        echo "[deploy] rollback OK current=$(current_target) bad-release-kept=$REL log=$LOG"
    else
        echo "[deploy] ROLLBACK DEGRADED current=$(current_target) wanted=$PREV_TARGET bad-release-kept=$REL log=$LOG" >&2
    fi
    return 1
}

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
if ! mkdir -p "$REL"; then
    echo "[deploy] FATAL: cannot create release directory $REL" >&2
    exit 1
fi
if ! git -C "$SRC" archive "$TARGET_SHA" | tar -x -C "$REL"; then
    echo "[deploy] FATAL: archive extraction failed for target $TARGET_SHA" >&2
    exit 1
fi
if ! { printf '%s\n' "$TARGET_SHA" > "$REL/.nz-deploy-sha" \
    && mkdir -p "$REL/bootstrap/cache" \
    && rm -rf "$REL/storage" \
    && ln -s "$SHARED/storage" "$REL/storage" \
    && rm -f "$REL/.env" \
    && ln -s "$SHARED/.env" "$REL/.env" \
    && ln -sfn "$REL/storage/app/public" "$REL/public/storage"; }; then
    echo "[deploy] FATAL: release shared-path preparation failed; current was not switched" >&2
    exit 1
fi

CUR="$LOCKED_CURRENT"
if [ -n "$CUR" ] && [ -f "$CUR/composer.lock" ] && [ -d "$CUR/vendor" ] && cmp -s "$REL/composer.lock" "$CUR/composer.lock" && cp -al "$CUR/vendor" "$REL/vendor"; then
    VMODE="vendor-hardlink-reuse"
else
    # 哪吒[防 vendor 级联 2026-06-24]: 上个 release 无 vendor/ 或硬链失败时不留空 vendor, 回退 composer install。
    if ! rm -rf "$REL/vendor"; then
        echo "[deploy] FATAL: cannot clear incomplete vendor directory" >&2
        exit 1
    fi
    VMODE="composer-install"
fi
if ! chown -R www:www "$REL"; then
    echo "[deploy] FATAL: cannot assign release build ownership" >&2
    exit 1
fi
if [ "$VMODE" = "composer-install" ] && ! (cd "$REL" && sudo -u www -H composer install --no-dev --optimize-autoloader >>"$LOG" 2>&1); then
    echo "[deploy] FATAL: composer install failed; current was not switched" >&2
    exit 1
fi
echo "[deploy] $VMODE"

# 哪吒[vendor 断言 2026-06-24]: 无论哪种模式 autoload 必须就位, 否则整个 release 不可用→worker 回收即全站500。fail-fast 不切 current, 线上仍跑旧 release。
if [ ! -f "$REL/vendor/autoload.php" ]; then
    echo "[deploy] FATAL: $REL/vendor/autoload.php 缺失 (VMODE=$VMODE) -- 终止部署, 不切换 current, 线上仍跑旧 release"
    exit 1
fi

assert_deploy_snapshot "before package preparation" || exit 75
if ! (cd "$REL" && sudo -u www -H php artisan package:discover --ansi >>"$LOG" 2>&1); then
    echo "[deploy] FATAL: package discovery failed; current was not switched" >&2
    exit 1
fi
assert_deploy_snapshot "before migration" || exit 75
if ! (cd "$REL" && sudo -u www -H php artisan migrate --force >>"$LOG" 2>&1); then
    echo "[deploy] FATAL: migration failed; current was not switched" >&2
    exit 1
fi
if ! (cd "$REL" \
    && sudo -u www -H php artisan generate:admin-route >>"$LOG" 2>&1 \
    && sudo -u www -H php artisan generate:restaurant-route >>"$LOG" 2>&1); then
    echo "[deploy] FATAL: generated login route preparation failed; current was not switched" >&2
    exit 1
fi
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

# 哪吒[P6.0 代码属主 root 化 2026-07-11]: 构建完成、原子切换前把代码树翻 root:www 只读(www 经组读、不可写→堵 webshell落地/改路由/持久化)。
# --no-dereference + find -type 不碰 storage/.env symlink 指向的 shared(保 www 可写)。P6.0 保留 bootstrap/cache www 可写(P6.1 再收只读)。
[ -s "$REL/bootstrap/cache/services.php" ] || { echo "[deploy] FATAL: bootstrap/cache/services.php 缺失 -- 不切 current, 线上仍跑旧 release"; exit 1; }
if ! chown -R --no-dereference root:www "$REL" \
    || ! find "$REL" -type d -exec chmod 750 {} + \
    || ! find "$REL" -type f -exec chmod 640 {} + \
    || ! find "$REL" -type f -name '*.sh' -exec chmod 750 {} + \
    || ! chown -R www:www "$REL/bootstrap/cache" \
    || ! chmod 775 "$REL/bootstrap/cache" \
    || ! find "$REL/bootstrap/cache" -type f -exec chmod 664 {} + \
    || ! chown root:www "$SHARED/.env" \
    || ! chmod 640 "$SHARED/.env"; then
    echo "[deploy] FATAL: P6.0 ownership/permission lock failed; current was not switched" >&2
    exit 1
fi
echo "[deploy] P6.0 lock: code root:www 640/750 sh750 - bootstrap/cache www writable - .env root:www 640"

assert_deploy_snapshot "before current switch" || exit 75
PREV_TARGET="$CUR"
if ! ln -sfn "$REL" "$DEPLOY/current"; then
    echo "[deploy] FATAL: current switch failed; previous release remains active" >&2
    exit 1
fi
if [ -n "$PREV_TARGET" ] && ! ln -sfn "$PREV_TARGET" "$DEPLOY/previous"; then
    rollback_after_switch "previous symlink update failed"
    exit 1
fi
if ! reload_fpm; then
    rollback_after_switch "PHP-FPM reload failed after current switch"
    exit 1
fi
if ! restart_queue_workers; then
    rollback_after_switch "queue worker restart failed after current switch"
    exit 1
fi
sleep 2

if ! healthy; then
    rollback_after_switch "health gate failed"
    exit 1
fi
if ! p6_deny_probe; then
    rollback_after_switch "P6 deny-probe failed"
    exit 1
fi

if healthy; then
    echo "[deploy] OK $TARGET_SHA  config=$(code /api/v1/config) zone=$(code /api/v1/zone/list) login=$(code /login/restaurant)"
    echo "[deploy] queue workers reloaded (restart_queue_workers)"
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
    rollback_after_switch "health gate changed after initial success"
    exit 1
fi
