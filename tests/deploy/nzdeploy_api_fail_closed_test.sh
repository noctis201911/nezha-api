#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SOURCE="$REPO_ROOT/deploy/nzdeploy-api.sh"
TMP=$(mktemp -d /tmp/nzdeploy-failclosed-test.XXXXXX)
BIN="$TMP/bin"
BASE="$TMP/base"
SRC="$BASE/src"
ORIGIN="$BASE/origin.git"

cleanup(){
    if [ "${NZDEPLOY_TEST_KEEP:-0}" = 1 ]; then
        echo "[failclosed-test] kept sandbox: $TMP" >&2
    else
        rm -rf "$TMP"
    fi
}
trap cleanup EXIT

fail(){ echo "[failclosed-test] FAIL: $*" >&2; exit 1; }

mkdir -p "$BIN" "$SRC" "$ORIGIN"
git init -q --bare "$ORIGIN"
git -C "$SRC" init -q
git -C "$SRC" config user.name NzdeployTest
git -C "$SRC" config user.email nzdeploy-test@example.invalid
mkdir -p "$SRC/bootstrap/cache" "$SRC/public" "$SRC/routes"
printf '{}\n' > "$SRC/composer.lock"
printf '#!/usr/bin/env bash\nexit 0\n' > "$SRC/nzcheck-cod.sh"
printf 'fixture\n' > "$SRC/bootstrap/cache/.gitkeep"
printf 'fixture\n' > "$SRC/public/.gitkeep"
printf 'fixture\n' > "$SRC/routes/.gitkeep"
chmod +x "$SRC/nzcheck-cod.sh"
git -C "$SRC" add composer.lock nzcheck-cod.sh bootstrap/cache/.gitkeep public/.gitkeep routes/.gitkeep
git -C "$SRC" commit -q -m fixture
git -C "$SRC" branch -M main
git -C "$SRC" remote add origin "$ORIGIN"
git -C "$SRC" push -q -u origin main
TARGET=$(git -C "$SRC" rev-parse HEAD)
SHORT=${TARGET:0:7}

cat > "$BIN/sudo" <<'STUB'
#!/usr/bin/env bash
set -u
while [ "$#" -gt 0 ]; do
    case "$1" in
        -u) shift 2 ;;
        -H) shift ;;
        *=*) export "$1"; shift ;;
        *) break ;;
    esac
done
cmd=${1:-}
if [ "$cmd" = pm2 ] || [ "$cmd" = /usr/bin/pm2 ]; then
    [ "${NZFAIL_STAGE:-}" = queue ] && exit 41
    exit 0
fi
if [ "$cmd" = touch ] && [[ "${2:-}" == */routes/.nzp6probe ]]; then
    if [ "${NZFAIL_STAGE:-}" = p6_probe ]; then
        /usr/bin/touch "$2"
        exit 0
    fi
    exit 1
fi
if [ "$cmd" = php ] && [ "${2:-}" = artisan ]; then
    action=${3:-}
    case "$action" in
        package:discover)
            [ "${NZFAIL_STAGE:-}" = package ] && exit 42
            mkdir -p bootstrap/cache
            printf 'fixture-services\n' > bootstrap/cache/services.php
            exit 0
            ;;
        migrate)
            [ "${NZFAIL_STAGE:-}" = migration ] && exit 43
            exit 0
            ;;
        generate:admin-route|generate:restaurant-route)
            [ "${NZFAIL_STAGE:-}" = routes ] && exit 44
            exit 0
            ;;
    esac
fi
if [ "$cmd" = composer ]; then
    [ "${NZFAIL_STAGE:-}" = composer ] && exit 45
    mkdir -p vendor
    /usr/bin/touch vendor/autoload.php
    exit 0
fi
exec "$@"
STUB

cat > "$BIN/curl" <<'STUB'
#!/usr/bin/env bash
if [ "${NZFAIL_STAGE:-}" = health ]; then
    printf '500'
elif [ "${NZFAIL_STAGE:-}" = health_after_probe ]; then
    count=0
    [ -f "${NZ_CURL_COUNT_FILE:?}" ] && count=$(cat "$NZ_CURL_COUNT_FILE")
    count=$((count + 1))
    printf '%s\n' "$count" > "$NZ_CURL_COUNT_FILE"
    if [ "$count" -le 3 ]; then printf '200'; else printf '500'; fi
else
    printf '200'
fi
STUB

cat > "$BIN/chown" <<'STUB'
#!/usr/bin/env bash
if [ "${NZFAIL_STAGE:-}" = p6_lock ]; then
    for arg in "$@"; do
        [ "$arg" = --no-dereference ] && exit 46
    done
fi
exit 0
STUB

cat > "$BIN/nz_test_reload_fpm" <<'STUB'
#!/usr/bin/env bash
[ "${NZFAIL_STAGE:-}" = fpm_reload ] && exit 47
exit 0
STUB

chmod +x "$BIN/sudo" "$BIN/curl" "$BIN/chown" "$BIN/nz_test_reload_fpm"

make_case(){
    local name="$1" case_root="$TMP/$1" deploy="$TMP/$1/deploy" previous script
    mkdir -p "$deploy/releases" "$deploy/shared/storage/framework" "$case_root/home-www"
    printf 'fixture-env\n' > "$deploy/shared/.env"
    previous="$deploy/releases/20260101-000000-$SHORT"
    mkdir -p "$previous/vendor" "$previous/routes" "$previous/bootstrap/cache"
    if [ "$name" = composer ]; then
        printf '{"different":true}\n' > "$previous/composer.lock"
    else
        cp "$SRC/composer.lock" "$previous/composer.lock"
    fi
    printf '<?php\n' > "$previous/vendor/autoload.php"
    ln -s "$previous" "$deploy/current"
    ln -s "$previous" "$deploy/previous"
    printf '424242\n' > "$case_root/php-fpm.pid"
    script="$case_root/nzdeploy-api.sh"
    sed \
        -e "s#/www/wwwroot/api-deploy#$deploy#g" \
        -e "s#/www/wwwroot/api.nezha.am#$SRC#g" \
        -e "s#/www/server/php/82/var/run/php-fpm.pid#$case_root/php-fpm.pid#g" \
        -e "s#LOCK=/tmp/nezha_apideploy.lock#LOCK=$case_root/deploy.lock#" \
        -e "s#LOG=/tmp/nezha_apideploy_last.log#LOG=$case_root/deploy.log#" \
        -e "s#/home/www#$case_root/home-www#g" \
        -e 's#/usr/bin/pm2#pm2#g' \
        -e 's#kill -USR2 "$FPMPID"#nz_test_reload_fpm "$FPMPID"#g' \
        -e 's#sleep 2#:#g' \
        "$SOURCE" > "$script"
    chmod +x "$script"
    if grep -Eq '/www/wwwroot|/www/server' "$script"; then
        fail "$name sandbox script still contains a production path"
    fi
    printf '%s\n' "$previous"
}

run_failure(){
    local name="$1" stage="$2" needle="$3" previous output rc
    previous=$(make_case "$name")
    output="$TMP/$name/output.log"
    set +e
    NZFAIL_STAGE="$stage" NZ_CURL_COUNT_FILE="$TMP/$name/curl-count" PATH="$BIN:$PATH" bash "$TMP/$name/nzdeploy-api.sh" "$TARGET" >"$output" 2>&1
    rc=$?
    set -e
    [ "$rc" -ne 0 ] || fail "$name unexpectedly succeeded"
    [ "$(readlink "$TMP/$name/deploy/current")" = "$previous" ] || fail "$name did not preserve/restore current"
    grep -Fq "$needle" "$output" || { sed -n '1,220p' "$output" >&2; fail "$name missing expected evidence: $needle"; }
    case "$stage" in
        fpm_reload|queue|health|health_after_probe)
            grep -Fq "ROLLBACK DEGRADED" "$output" || fail "$name did not report degraded rollback verification"
            ;;
        p6_probe)
            grep -Fq "rollback OK" "$output" || fail "$name did not verify rollback success"
            ;;
    esac
    echo "[failclosed-test] PASS $name rc=$rc current=previous"
}

run_success(){
    local previous output
    previous=$(make_case success)
    output="$TMP/success/output.log"
    NZFAIL_STAGE=none PATH="$BIN:$PATH" bash "$TMP/success/nzdeploy-api.sh" "$TARGET" >"$output" 2>&1
    [ "$(readlink "$TMP/success/deploy/current")" != "$previous" ] || fail "success did not switch current"
    grep -Fq "[deploy] OK $TARGET" "$output" || fail "success did not reach final OK"
    grep -Fq "P6 deny-probe OK" "$output" || fail "success did not verify P6 deny-probe"
    echo "[failclosed-test] PASS success current=target"
}

set +e
bash "$SOURCE" >/dev/null 2>&1
noarg=$?
bash "$SOURCE" "$SHORT" >/dev/null 2>&1
shortarg=$?
set -e
[ "$noarg" -eq 64 ] || fail "no-argument guard returned $noarg, expected 64"
[ "$shortarg" -eq 64 ] || fail "short-SHA guard returned $shortarg, expected 64"
echo "[failclosed-test] PASS argument guards rc=64"

run_failure package package "package discovery failed"
run_failure composer composer "composer install failed"
run_failure migration migration "migration failed"
run_failure routes routes "generated login route preparation failed"
run_failure p6_lock p6_lock "P6.0 ownership/permission lock failed"
run_failure fpm_reload fpm_reload "PHP-FPM reload failed after current switch"
run_failure queue queue "queue worker restart failed after current switch"
run_failure health health "health gate failed"
run_failure p6_probe p6_probe "P6 deny-probe failed"
run_failure health_after_probe health_after_probe "health gate changed after initial success"
run_success

echo "[failclosed-test] ALL PASS"
