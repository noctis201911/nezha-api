#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SOURCE="$REPO_ROOT/ops/backup/nezha-encrypted-backup.sh"
TMP=$(mktemp -d /tmp/nezha-encrypted-backup-test.XXXXXX)

cleanup(){
    rm -rf "$TMP"
}
trap cleanup EXIT

fail(){
    echo "[backup-test] FAIL: $*" >&2
    exit 1
}

make_case(){
    local name="$1" root="$TMP/$1" bin="$TMP/$1/bin"
    local app="$TMP/$1/app" out="$TMP/$1/out" key="$TMP/$1/backup.key"
    local script="$TMP/$1/nezha-encrypted-backup.sh"
    local data="$TMP/$1/data" helper="$TMP/$1/nzwatch_mail.py"

    mkdir -p "$bin" "$app" "$out" "$data/storage/app"
    printf 'test-key\n' > "$key"
    # 文件备份段的取材来源($DATA): storage/app 实体 + .env 实体
    printf 'uploaded-artifact\n' > "$data/storage/app/uploaded.bin"
    cat > "$data/.env" <<'ENVSTUB'
MAIL_HOST=smtp.invalid
MAIL_PORT=587
MAIL_USERNAME=alert@invalid
MAIL_PASSWORD=SUPER_SECRET_MAIL_PASSWORD
MAIL_FROM_ADDRESS=alert@invalid
ENVSTUB
    printf '# sandbox mail helper placeholder\n' > "$helper"

cat > "$bin/php" <<'STUB'
#!/usr/bin/env bash
printf 'testdb\ttestuser\tSUPER_SECRET_BACKUP_PASSWORD\tdb.internal\t3307'
STUB

cat > "$bin/mysqldump" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" > "${NZ_MYSQLDUMP_ARGS:?}"
[ -z "${NZ_MYSQLDUMP_STARTED:-}" ] || : > "$NZ_MYSQLDUMP_STARTED"
if [ -n "${NZ_MYSQLDUMP_DELAY:-}" ]; then
    sleep "$NZ_MYSQLDUMP_DELAY"
fi
if [ "${NZ_MYSQLDUMP_FAIL:-0}" = 1 ]; then
    printf 'partial dump\n'
    exit 42
fi
printf 'CREATE TABLE test (id INT);\n'
STUB

    cat > "$bin/date" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = '+%Y%m%d%H%M%S' ] && [ -n "${NZ_DATE_TIMESTAMP:-}" ]; then
    printf '%s\n' "$NZ_DATE_TIMESTAMP"
    exit 0
fi
exec /usr/bin/date "$@"
STUB

    cat > "$bin/flock" <<'STUB'
#!/usr/bin/env bash
state=${NZ_FAKE_FLOCK_STATE:-$(cd "$(dirname "$0")/.." && pwd)/flock-state}
action=${1:-}

if [ "$action" = '-u' ]; then
    if [ "${NZ_FLOCK_UNLOCK_FAIL:-0}" = 1 ]; then
        exit 48
    fi
    rm -rf "$state"
    exit 0
fi
if [ "$action" != '-n' ]; then
    exit 64
fi

if mkdir "$state" 2>/dev/null; then
    printf '%s\n' "$PPID" > "$state/owner"
    exit 0
fi
owner=$(cat "$state/owner" 2>/dev/null || true)
if [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; then
    rm -rf "$state"
    if mkdir "$state" 2>/dev/null; then
        printf '%s\n' "$PPID" > "$state/owner"
        exit 0
    fi
fi
exit 1
STUB

    cat > "$bin/gzip" <<'STUB'
#!/usr/bin/env bash
if [ "${NZ_GZIP_FAIL:-0}" = 1 ]; then
    printf 'partial compressed data\n'
    exit 43
fi
cat
STUB

    cat > "$bin/chmod" <<'STUB'
#!/usr/bin/env bash
[ -z "${NZ_CHMOD_LOG:-}" ] || printf '%s\t%s\n' "$1" "$2" >> "$NZ_CHMOD_LOG"
if [ "${NZ_CHMOD_FAIL:-0}" = 1 ] && [ "$1" = 600 ]; then
    exit 45
fi
exec /usr/bin/chmod "$@"
STUB

    cat > "$bin/stat" <<'STUB'
#!/usr/bin/env bash
if [ "${NZ_STAT_FAIL:-0}" = 1 ]; then
    exit 46
fi
exec /usr/bin/stat "$@"
STUB

    cat > "$bin/sort" <<'STUB'
#!/usr/bin/env bash
if [ "${NZ_RETENTION_FAIL:-0}" = 1 ]; then
    exit 47
fi
exec /usr/bin/sort "$@"
STUB

    cat > "$bin/rm" <<'STUB'
#!/usr/bin/env bash
target=${!#}
if [ -n "${NZ_RM_FAIL_ON:-}" ] && [[ "$target" == *.sql.gz.enc ]]; then
    count=0
    [ ! -f "${NZ_RM_COUNT_FILE:?}" ] || count=$(cat "$NZ_RM_COUNT_FILE")
    count=$((count + 1))
    printf '%s\n' "$count" > "$NZ_RM_COUNT_FILE"
    if [ "$count" -eq "$NZ_RM_FAIL_ON" ]; then
        exit 49
    fi
fi
exec /usr/bin/rm "$@"
STUB

    cat > "$bin/tar" <<'STUB'
#!/usr/bin/env bash
if [ "${NZ_TAR_FAIL:-0}" = 1 ]; then
    printf 'tar: injected archive failure\n' >&2
    exit 2
fi
exec /usr/bin/tar "$@"
STUB

    # rclone/python3 的调用日志路径必须写死进 stub: 生产脚本用绝对路径调它们,
    # 测试无法靠 PATH 拦截, 只能靠 make_case 的 sed 把 RCLONE_BIN/MAIL_HELPER 钉进沙箱。
    cat > "$bin/rclone" <<STUB
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$root/rclone.calls"
for arg in "\$@"; do
    if [ "\$arg" = 'copy' ]; then
        if [ "\${NZ_RCLONE_FAIL:-0}" = 1 ]; then
            printf 'rclone: injected offsite failure\n' >&2
            exit 7
        fi
    fi
done
exit 0
STUB

    cat > "$bin/python3" <<STUB
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$root/python3.calls"
[ -z "\${NZ_MAIL_ENV_LOG:-}" ] || printf 'to=%s subj=%s user=%s\n' "\${NZ_TO:-}" "\${NZ_SUBJ:-}" "\${NZ_USER:-}" >> "\$NZ_MAIL_ENV_LOG"
exit 0
STUB

cat > "$bin/openssl" <<'STUB'
#!/usr/bin/env bash
out=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-out' ]; then
        out="$2"
        shift 2
    else
        shift
    fi
done
[ -n "$out" ] || exit 64
[ -z "${NZ_OPENSSL_OUT_LOG:-}" ] || printf '%s\n' "$out" >> "$NZ_OPENSSL_OUT_LOG"
[ -z "${NZ_OPENSSL_STARTED:-}" ] || : > "$NZ_OPENSSL_STARTED"
printf 'encrypted-prefix\n' > "$out"
if [ "${NZ_OPENSSL_FAIL:-0}" = 1 ]; then
    exit 44
fi
if [ -n "${NZ_OPENSSL_DELAY:-}" ]; then
    sleep "$NZ_OPENSSL_DELAY"
fi
cat >> "$out"
[ -z "${NZ_OPENSSL_FINISHED:-}" ] || : > "$NZ_OPENSSL_FINISHED"
if [ -n "${NZ_OPENSSL_FINISH_DELAY:-}" ]; then
    sleep "$NZ_OPENSSL_FINISH_DELAY"
fi
STUB

    chmod +x "$bin/php" "$bin/mysqldump" "$bin/date" "$bin/flock" "$bin/gzip" "$bin/chmod" "$bin/stat" "$bin/sort" "$bin/rm" "$bin/openssl" "$bin/tar" "$bin/rclone" "$bin/python3"
    sed \
        -e "s#^APP=.*#APP=$app#" \
        -e "s#^DATA=.*#DATA=$data#" \
        -e "s#^PHP=.*#PHP=$bin/php#" \
        -e "s#^KEY=.*#KEY=$key#" \
        -e "s#^OUTDIR=.*#OUTDIR=$out#" \
        -e "s#^RCLONE_BIN=.*#RCLONE_BIN=$bin/rclone#" \
        -e "s#^MAIL_HELPER=.*#MAIL_HELPER=$helper#" \
        -e "s#^SENTINEL=.*#SENTINEL=$root/offsite_last_ok#" \
        -e "s#^PERR=.*#PERR=$root/r2push.err#" \
        "$SOURCE" > "$script"
    chmod +x "$script"

    if grep -Eq '/www/wwwroot|/www/backup|/root/\.nezha' "$script"; then
        fail "$name sandbox script still contains a production path"
    fi
    # rclone/mail helper 走绝对路径,PATH stub 拦不住 —— 必须确认沙箱脚本已被钉走,
    # 否则任何一个用例都可能把沙箱垃圾推上生产 R2 或真发告警邮件。
    if grep -Eq '/usr/local/bin/rclone|nzwatch_mail\.py' "$script" && ! grep -Fq "$bin/rclone" "$script"; then
        fail "$name sandbox script still points at the production rclone binary"
    fi
    grep -Fq "MAIL_HELPER=$helper" "$script" || fail "$name sandbox script still points at the production mail helper"
    grep -Fq "SENTINEL=$root/offsite_last_ok" "$script" || fail "$name sandbox script still points at the production offsite sentinel"

    printf '%s\n' "$root"
}

install_kernel_flock_adapter(){
    local root="$1"

    cat > "$root/bin/flock" <<'STUB'
#!/usr/bin/env perl
use strict;
use warnings;
use Fcntl qw(LOCK_EX LOCK_NB LOCK_UN);

my $action = shift // '';
my $fd = shift // '';
exit 64 unless $fd eq '9';
open(my $lock, '>&=9') or exit 65;
if ($action eq '-n') {
    exit(flock($lock, LOCK_EX | LOCK_NB) ? 0 : 1);
}
if ($action eq '-u') {
    exit(flock($lock, LOCK_UN) ? 0 : 1);
}
exit 64;
STUB
    chmod +x "$root/bin/flock"
}

test_utf8mb4_dump_contract(){
    local root args output
    root=$(make_case utf8mb4)
    args="$root/mysqldump.args"
    output="$root/output.log"

    NZ_MYSQLDUMP_ARGS="$args" PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1

    grep -Fxq -- '--default-character-set=utf8mb4' "$args" || {
        sed -n '1,120p' "$output" >&2
        fail 'mysqldump did not request utf8mb4 explicitly'
    }
    # --master-data=2 是 README-RESTORE §E 的 PITR 锚点(CHANGE MASTER 注释行), 丢了=时间点恢复断锚
    grep -Fxq -- '--master-data=2' "$args" || {
        sed -n '1,120p' "$output" >&2
        fail 'mysqldump did not request the binlog PITR anchor (--master-data=2)'
    }
    grep -Fxq -- '-hdb.internal' "$args" || fail 'database host was not passed through'
    grep -Fxq -- '-P3307' "$args" || fail 'database port was not passed through'
    grep -Fxq -- '-utestuser' "$args" || fail 'database user was not passed through'
    grep -Fxq -- 'testdb' "$args" || fail 'database name was not passed through'
    echo '[backup-test] PASS utf8mb4 dump contract'
}

test_failed_dump_leaves_no_backup_artifact(){
    local root args output rc
    root=$(make_case failed-dump)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_MYSQLDUMP_FAIL=1 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'failed mysqldump unexpectedly reported success'
    grep -Fq 'BACKUP FAILED' "$output" || fail 'failed mysqldump did not report backup failure'
    if compgen -G "$root/out/*.sql.gz.enc" > /dev/null; then
        fail 'failed mysqldump left an encrypted file that could be mistaken for a successful backup'
    fi
    if grep -Fq 'backup ok:' "$output"; then
        fail 'failed mysqldump printed a success marker'
    fi
    echo "[backup-test] PASS failed dump cleanup rc=$rc"
}

test_success_is_published_atomically_once(){
    local root args output started out_log chmod_log pid rc visible_during temp_count final temp_target success_count
    root=$(make_case atomic-success)
    args="$root/mysqldump.args"
    output="$root/output.log"
    started="$root/openssl.started"
    out_log="$root/openssl.out"
    chmod_log="$root/chmod.log"

    NZ_MYSQLDUMP_ARGS="$args" NZ_OPENSSL_STARTED="$started" NZ_OPENSSL_OUT_LOG="$out_log" NZ_OPENSSL_DELAY=1 NZ_CHMOD_LOG="$chmod_log" PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1 &
    pid=$!
    for _ in {1..100}; do
        [ -f "$started" ] && break
        sleep 0.02
    done
    [ -f "$started" ] || { wait "$pid" || true; fail 'openssl boundary did not start'; }

    visible_during=$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')
    temp_count=$(find "$root/out" -maxdepth 1 -type f -name '*.tmp.*' -o -name '.*.tmp.*' | wc -l | tr -d ' ')
    set +e
    wait "$pid"
    rc=$?
    set -e

    [ "$rc" -eq 0 ] || { sed -n '1,120p' "$output" >&2; fail "atomic success exited $rc"; }
    [ "$visible_during" -eq 0 ] || fail 'final encrypted artifact became visible before encryption completed'
    [ "$temp_count" -eq 1 ] || fail "expected exactly one in-progress temporary file, found $temp_count"

    mapfile -t finals < <(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc')
    [ "${#finals[@]}" -eq 1 ] || fail "expected exactly one final artifact, found ${#finals[@]}"
    final=${finals[0]}
    [ -s "$final" ] || fail 'final artifact is empty'
    # 合流后一轮产两件(DB + files),openssl 收到两个输出目标; 第一个必须是 DB 临时件。
    temp_target=$(head -1 "$out_log")
    grep -Fxq $'600\t'"$temp_target" "$chmod_log" || fail 'unique temporary artifact did not receive chmod 600 before publication'
    success_count=$(grep -Fc 'db backup ok:' "$output")
    [ "$success_count" -eq 1 ] || fail "expected one database success marker, found $success_count"
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'database password leaked to output'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$args" || fail 'database password leaked to mysqldump arguments'
    ! grep -Fq 'SUPER_SECRET_MAIL_PASSWORD' "$output" || fail 'mail credentials leaked to output'
    [ "$(wc -l < "$out_log" | tr -d ' ')" -eq 2 ] || fail 'openssl did not receive exactly the database and files output targets'
    echo '[backup-test] PASS atomic single publication'
}

test_gzip_failure_is_clean(){
    local root args output rc files
    root=$(make_case gzip-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_GZIP_FAIL=1 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'gzip failure unexpectedly reported success'
    files=$(find "$root/out" -mindepth 1 -maxdepth 1 -type f \( -name '*.sql.gz.enc' -o -name '.*.tmp.*' \) | wc -l | tr -d ' ')
    [ "$files" -eq 0 ] || fail "gzip failure left $files backup files"
    ! grep -Fq 'backup ok:' "$output" || fail 'gzip failure printed a success marker'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'gzip failure leaked the database password'
    echo "[backup-test] PASS gzip failure cleanup rc=$rc"
}

test_openssl_failure_is_clean(){
    local root args output rc files
    root=$(make_case openssl-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_OPENSSL_FAIL=1 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'openssl failure unexpectedly reported success'
    files=$(find "$root/out" -mindepth 1 -maxdepth 1 -type f \( -name '*.sql.gz.enc' -o -name '.*.tmp.*' \) | wc -l | tr -d ' ')
    [ "$files" -eq 0 ] || fail "openssl failure left $files backup files"
    ! grep -Fq 'backup ok:' "$output" || fail 'openssl failure printed a success marker'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'openssl failure leaked the database password'
    echo "[backup-test] PASS openssl failure cleanup rc=$rc"
}

test_chmod_failure_is_clean(){
    local root args output rc files
    root=$(make_case chmod-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_CHMOD_FAIL=1 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'chmod failure unexpectedly reported success'
    files=$(find "$root/out" -mindepth 1 -maxdepth 1 -type f \( -name '*.sql.gz.enc' -o -name '.*.tmp.*' \) | wc -l | tr -d ' ')
    [ "$files" -eq 0 ] || fail "chmod failure left $files backup files"
    ! grep -Fq 'backup ok:' "$output" || fail 'chmod failure printed a success marker'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'chmod failure leaked the database password'
    echo "[backup-test] PASS chmod failure cleanup rc=$rc"
}

test_stat_failure_is_clean(){
    local root args output rc files
    root=$(make_case stat-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_STAT_FAIL=1 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'stat failure unexpectedly reported success'
    files=$(find "$root/out" -mindepth 1 -maxdepth 1 -type f \( -name '*.sql.gz.enc' -o -name '.*.tmp.*' \) | wc -l | tr -d ' ')
    [ "$files" -eq 0 ] || fail "stat failure left $files backup files"
    ! grep -Fq 'backup ok:' "$output" || fail 'stat failure printed a success marker'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'stat failure leaked the database password'
    echo "[backup-test] PASS stat failure cleanup rc=$rc"
}

seed_managed_backups(){
    local out="$1" count="$2" date_prefix="${3:-20260101}" i stamp token file
    for ((i = 1; i <= count; i++)); do
        printf -v stamp '%s0000%02d' "$date_prefix" "$i"
        printf -v token 'A%05d' "$i"
        file="$out/testdb-${stamp}-${token}.sql.gz.enc"
        printf 'managed-%s\n' "$i" > "$file"
        touch -t "${date_prefix}00$(printf '%02d' "$i").00" "$file"
    done
}

run_retention_boundary(){
    local name="$1" initial="$2" root args output managed_count
    root=$(make_case "$name")
    args="$root/mysqldump.args"
    output="$root/output.log"
    seed_managed_backups "$root/out" "$initial"
    printf 'external evidence\n' > "$root/out/testdb-manual-evidence.sql.gz.enc"

    NZ_MYSQLDUMP_ARGS="$args" PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1

    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 14 ] || fail "$name retained $managed_count strictly named backups, expected 14"
    [ -f "$root/out/testdb-manual-evidence.sql.gz.enc" ] || fail "$name deleted a database-prefixed external same-suffix file"
    if [ "$initial" -eq 14 ]; then
        [ ! -f "$root/out/testdb-20260101000001-A00001.sql.gz.enc" ] || fail "$name did not prune the oldest managed backup at the 15-file boundary"
        [ -f "$root/out/testdb-20260101000002-A00002.sql.gz.enc" ] || fail "$name pruned more than the oldest managed backup"
    fi
    echo "[backup-test] PASS retention boundary initial=$initial final=$managed_count"
}

test_retention_boundaries_ignore_external_files(){
    run_retention_boundary retention-14 13
    run_retention_boundary retention-15 14
}

test_retention_never_deletes_current_committed_artifact(){
    local root args output managed_count new_count
    root=$(make_case retention-protect-current)
    args="$root/mysqldump.args"
    output="$root/output.log"
    seed_managed_backups "$root/out" 14 20990101

    NZ_MYSQLDUMP_ARGS="$args" NZ_DATE_TIMESTAMP=20250101010101 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1

    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    new_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-20250101010101-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 14 ] || fail "protected-current retention left $managed_count managed artifacts, expected 14"
    [ "$new_count" -eq 1 ] || fail "retention deleted the current committed artifact when existing timestamps were newer"
    [ ! -f "$root/out/testdb-20990101000001-A00001.sql.gz.enc" ] || fail 'retention did not delete the oldest pre-existing artifact while protecting current output'
    echo '[backup-test] PASS retention protects current committed artifact from future-dated files'
}

test_retention_failure_keeps_committed_artifact(){
    local root args output rc managed_count new_count
    root=$(make_case retention-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"
    seed_managed_backups "$root/out" 14

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_RETENTION_FAIL=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'retention failure unexpectedly reported success'
    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    new_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-20990101010101-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 15 ] || fail "retention failure left $managed_count managed artifacts, expected original 14 plus committed new artifact"
    [ "$new_count" -eq 1 ] || fail "retention failure kept $new_count committed new artifacts, expected 1"
    [ -f "$root/out/testdb-20260101000001-A00001.sql.gz.enc" ] || fail 'retention failure deleted an existing managed backup'
    ! grep -Fq 'backup ok:' "$output" || fail 'retention failure printed a success marker'
    grep -Fq 'BACKUP POST-COMMIT WARNING:' "$output" || fail 'retention failure did not identify the post-commit warning state'
    grep -Fq 'verified artifact kept:' "$output" || fail 'retention failure did not report that the committed artifact was kept'
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail 'retention failure leaked the database password'
    echo "[backup-test] PASS retention failure preserves committed artifact rc=$rc"
}

test_unlock_failure_keeps_committed_artifact(){
    local root args output rc managed_count new_count
    root=$(make_case unlock-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"
    seed_managed_backups "$root/out" 14

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_FLOCK_UNLOCK_FAIL=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'unlock failure unexpectedly reported success'
    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    new_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-20990101010101-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 14 ] || fail "unlock failure left $managed_count managed artifacts, expected 14"
    [ "$new_count" -eq 1 ] || fail "unlock failure kept $new_count committed new artifacts, expected 1"
    [ ! -f "$root/out/testdb-20260101000001-A00001.sql.gz.enc" ] || fail 'unlock failure did not preserve completed retention pruning'
    [ -f "$root/out/testdb-20260101000002-A00002.sql.gz.enc" ] || fail 'unlock failure pruned more than the oldest managed backup'
    # 合流后 db/files 的 ok 行按现役版顺序落在 R2 段之前(锁释放在最后),
    # 故此处不再断言"无 ok 行", 改断言: 唯一失败点是解锁, 且它没有被伪装成整轮成功。
    grep -Fq 'R2 offsite push ok' "$output" || fail 'unlock failure test did not reach the offsite stage as expected'
    grep -Fq 'cannot release backup lock' "$output" || fail 'unlock failure did not emit an operator-visible error'
    echo "[backup-test] PASS unlock failure preserves committed artifact rc=$rc"
}

test_success_log_failure_keeps_committed_artifact(){
    local root args errors rc managed_count new_count
    root=$(make_case success-log-failure)
    args="$root/mysqldump.args"
    errors="$root/errors.log"
    seed_managed_backups "$root/out" 14

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > /dev/full 2> "$errors"
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'success-log failure unexpectedly reported success'
    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    new_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-20990101010101-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 14 ] || fail "success-log failure left $managed_count managed artifacts, expected 14"
    [ "$new_count" -eq 1 ] || fail "success-log failure kept $new_count committed new artifacts, expected 1"
    [ ! -f "$root/out/testdb-20260101000001-A00001.sql.gz.enc" ] || fail 'success-log failure did not preserve completed retention pruning'
    [ -f "$root/out/testdb-20260101000002-A00002.sql.gz.enc" ] || fail 'success-log failure pruned more than the oldest managed backup'
    grep -Fq 'cannot report backup success' "$errors" || fail 'success-log failure did not emit an operator-visible error'
    echo "[backup-test] PASS success-log failure preserves committed artifact rc=$rc"
}

test_partial_retention_failure_never_deletes_new_artifact(){
    local root args output rm_count rc managed_count new_count
    root=$(make_case partial-retention-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"
    rm_count="$root/rm-count"
    seed_managed_backups "$root/out" 16

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_DATE_TIMESTAMP=20990101010101 NZ_RM_FAIL_ON=2 NZ_RM_COUNT_FILE="$rm_count" PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'partial retention failure unexpectedly reported success'
    managed_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-??????????????-??????.sql.gz.enc' | wc -l | tr -d ' ')
    new_count=$(find "$root/out" -maxdepth 1 -type f -name 'testdb-20990101010101-??????.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$managed_count" -eq 16 ] || fail "partial retention failure left $managed_count artifacts, expected 16 after one successful old-file deletion"
    [ "$managed_count" -ge 14 ] || fail "partial retention failure reduced managed artifacts below 14"
    [ "$new_count" -eq 1 ] || fail "partial retention failure kept $new_count committed new artifacts, expected 1"
    [ ! -f "$root/out/testdb-20260101000001-A00001.sql.gz.enc" ] || fail 'partial retention failure did not delete the first oldest backup'
    [ -f "$root/out/testdb-20260101000002-A00002.sql.gz.enc" ] || fail 'partial retention failure continued deleting after the injected second-delete failure'
    [ "$(cat "$rm_count")" -eq 2 ] || fail 'partial retention did not stop on the second delete failure'
    ! grep -Fq 'backup ok:' "$output" || fail 'partial retention failure printed a success marker'
    grep -Fq 'cannot enforce backup retention policy' "$output" || fail 'partial retention failure did not emit an operator-visible error'
    echo "[backup-test] PASS partial retention preserves committed artifact rc=$rc"
}

test_concurrent_same_second_run_is_rejected(){
    local root started args1 args2 output1 output2 pid1 pid2 rc1 rc2 successes failures finals temps
    root=$(make_case concurrent-same-second)
    started="$root/mysqldump.started"
    args1="$root/mysqldump-1.args"
    args2="$root/mysqldump-2.args"
    output1="$root/output-1.log"
    output2="$root/output-2.log"

    NZ_MYSQLDUMP_ARGS="$args1" NZ_MYSQLDUMP_STARTED="$started" NZ_MYSQLDUMP_DELAY=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output1" 2>&1 &
    pid1=$!
    for _ in {1..100}; do
        [ -f "$started" ] && break
        sleep 0.02
    done
    [ -f "$started" ] || { wait "$pid1" || true; fail 'first concurrent backup did not reach dump boundary'; }

    NZ_MYSQLDUMP_ARGS="$args2" NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output2" 2>&1 &
    pid2=$!
    set +e
    wait "$pid1"; rc1=$?
    wait "$pid2"; rc2=$?
    set -e

    successes=0; failures=0
    [ "$rc1" -eq 0 ] && successes=$((successes + 1)) || failures=$((failures + 1))
    [ "$rc2" -eq 0 ] && successes=$((successes + 1)) || failures=$((failures + 1))
    finals=$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')
    temps=$(find "$root/out" -maxdepth 1 -type f -name '.*.tmp.*' | wc -l | tr -d ' ')
    [ "$successes" -eq 1 ] || fail "concurrent run produced $successes successes, expected 1"
    [ "$failures" -eq 1 ] || fail "concurrent run produced $failures failures, expected 1"
    [ "$finals" -eq 1 ] || fail "concurrent run left $finals final artifacts, expected 1"
    [ "$temps" -eq 0 ] || fail "concurrent run left $temps temporary artifacts"
    [ ! -d "$root/flock-state" ] || fail 'concurrent run left an active lock state'
    echo "[backup-test] PASS concurrent same-second rejection rc=$rc1/$rc2"
}

run_signal_cleanup_case(){
    local signal="$1" name="$2" root args output started sent rc files
    root=$(make_case "$name")
    args="$root/mysqldump.args"
    output="$root/output.log"
    started="$root/mysqldump.started"
    sent="$root/signal.sent"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_MYSQLDUMP_STARTED="$started" NZ_MYSQLDUMP_DELAY=1 PATH="$root/bin:$PATH" \
        bash -c '
            target=$$
            signal=$1
            marker=$2
            sent=$3
            script=$4
            (
                for _ in {1..100}; do
                    if [ -f "$marker" ]; then
                        : > "$sent"
                        kill -s "$signal" "$target"
                        exit 0
                    fi
                    sleep 0.02
                done
            ) &
            exec bash "$script"
        ' _ "$signal" "$started" "$sent" "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail "$signal interruption unexpectedly reported success"
    [ -f "$started" ] || fail "$signal test never reached the dump boundary"
    [ -f "$sent" ] || fail "$signal was not sent after the dump boundary marker"
    files=$(find "$root/out" -mindepth 1 -maxdepth 1 -type f \( -name '*.sql.gz.enc' -o -name '.*.tmp.*' \) | wc -l | tr -d ' ')
    [ "$files" -eq 0 ] || fail "$signal interruption left $files backup files"
    [ ! -d "$root/flock-state" ] || fail "$signal interruption left an active lock state"
    ! grep -Fq 'backup ok:' "$output" || fail "$signal interruption printed a success marker"
    ! grep -Fq 'SUPER_SECRET_BACKUP_PASSWORD' "$output" || fail "$signal interruption leaked the database password"
    echo "[backup-test] PASS $signal interruption cleanup rc=$rc"
}

test_term_cleans_in_progress_backup(){
    run_signal_cleanup_case TERM signal-term
}

test_int_cleans_in_progress_backup(){
    run_signal_cleanup_case INT signal-int
}

run_sigkill_lock_inheritance_case(){
    local name="$1" flock_bin="$2" label="$3"
    local root first_args second_args marker_args third_args first_output second_output marker_output third_output
    local first_started first_finished second_started marker_started first_pid first_rc second_rc marker_rc third_rc
    local attempt finals temps
    root=$(make_case "$name")
    if [ "$flock_bin" = 'kernel-adapter' ]; then
        install_kernel_flock_adapter "$root"
        flock_bin="$root/bin/flock"
    fi
    first_args="$root/mysqldump-1.args"
    second_args="$root/mysqldump-2.args"
    marker_args="$root/mysqldump-marker.args"
    third_args="$root/mysqldump-3.args"
    first_output="$root/output-1.log"
    second_output="$root/output-2.log"
    marker_output="$root/output-marker.log"
    third_output="$root/output-3.log"
    first_started="$root/mysqldump-1.started"
    first_finished="$root/openssl-1.finished"
    second_started="$root/mysqldump-2.started"
    marker_started="$root/mysqldump-marker.started"

    NZ_MYSQLDUMP_ARGS="$first_args" NZ_MYSQLDUMP_STARTED="$first_started" NZ_MYSQLDUMP_DELAY=2 FLOCK_BIN="$flock_bin" \
        NZ_OPENSSL_FINISHED="$first_finished" NZ_OPENSSL_FINISH_DELAY=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$first_output" 2>&1 &
    first_pid=$!
    for _ in {1..100}; do
        [ -f "$first_started" ] && break
        sleep 0.02
    done
    [ -f "$first_started" ] || { wait "$first_pid" || true; fail 'kernel-lock run did not reach the dump boundary'; }

    kill -KILL "$first_pid"
    set +e
    wait "$first_pid"
    first_rc=$?
    NZ_MYSQLDUMP_ARGS="$second_args" NZ_MYSQLDUMP_STARTED="$second_started" NZ_DATE_TIMESTAMP=20990101010102 FLOCK_BIN="$flock_bin" \
        PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$second_output" 2>&1
    second_rc=$?
    set -e

    [ "$first_rc" -eq 137 ] || fail "kernel-lock parent SIGKILL exited $first_rc, expected 137"
    [ "$second_rc" -ne 0 ] || fail 'immediate retry acquired the lock while the first dump pipeline was still running'
    [ ! -f "$second_started" ] || fail 'immediate retry reached mysqldump while the first dump pipeline still held the lock'
    grep -Fq 'another backup is active' "$second_output" || fail 'immediate retry did not report the active kernel lock'

    for _ in {1..200}; do
        [ -f "$first_finished" ] && break
        sleep 0.02
    done
    [ -f "$first_finished" ] || fail 'surviving dump pipeline did not finish after parent SIGKILL'

    set +e
    NZ_MYSQLDUMP_ARGS="$marker_args" NZ_MYSQLDUMP_STARTED="$marker_started" NZ_DATE_TIMESTAMP=20990101010103 \
        FLOCK_BIN="$flock_bin" PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$marker_output" 2>&1
    marker_rc=$?
    set -e
    [ "$marker_rc" -ne 0 ] || fail 'openssl marker was mistaken for release of the inherited pipeline lock'
    [ ! -f "$marker_started" ] || fail 'marker-time retry reached mysqldump before the inherited pipeline lock was released'
    grep -Fq 'another backup is active' "$marker_output" || fail 'marker-time retry did not report the inherited pipeline lock'

    third_rc=1
    for attempt in {1..100}; do
        set +e
        NZ_MYSQLDUMP_ARGS="$third_args" NZ_DATE_TIMESTAMP=20990101010104 FLOCK_BIN="$flock_bin" PATH="$root/bin:$PATH" \
            bash "$root/nezha-encrypted-backup.sh" > "$third_output" 2>&1
        third_rc=$?
        set -e
        [ "$third_rc" -ne 0 ] || break
        grep -Fq 'another backup is active' "$third_output" || {
            sed -n '1,120p' "$third_output" >&2
            fail "bounded retry failed for a reason other than the active inherited lock on attempt $attempt"
        }
        sleep 0.02
    done

    [ "$third_rc" -eq 0 ] || { sed -n '1,120p' "$third_output" >&2; fail 'bounded full-backup retries timed out waiting for the inherited pipeline lock'; }
    finals=$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')
    temps=$(find "$root/out" -maxdepth 1 -type f -name '.*.tmp.*' | wc -l | tr -d ' ')
    [ "$finals" -eq 1 ] || fail "kernel-lock recovery left $finals final artifacts, expected 1"
    [ "$temps" -eq 0 ] || fail "kernel-lock recovery left $temps orphan temporary artifacts"
    echo "[backup-test] PASS $label lock survives parent SIGKILL until bounded full-backup retry succeeds on attempt $attempt"
}

test_kernel_lock_is_held_by_surviving_pipeline_after_parent_sigkill(){
    run_sigkill_lock_inheritance_case kernel-lock-sigkill kernel-adapter 'kernel-adapter'
}

test_real_util_linux_flock_is_held_by_surviving_pipeline_after_parent_sigkill(){
    local real_flock=${REAL_FLOCK_BIN:-} explicit_flock=0

    if [ -n "$real_flock" ]; then
        explicit_flock=1
    else
        real_flock=$(command -v flock 2>/dev/null || true)
    fi
    if [ -z "$real_flock" ]; then
        echo '[backup-test] SKIP real util-linux flock inheritance (set REAL_FLOCK_BIN on Linux to require it)'
        return 0
    fi
    real_flock=$(command -v "$real_flock" 2>/dev/null || true)
    [ -n "$real_flock" ] || fail 'REAL_FLOCK_BIN is not executable'
    if ! "$real_flock" --version 2>&1 | grep -Fq 'util-linux'; then
        [ "$explicit_flock" -eq 0 ] || fail 'REAL_FLOCK_BIN is not util-linux flock'
        echo '[backup-test] SKIP real util-linux flock inheritance (auto-detected flock is not util-linux)'
        return 0
    fi

    run_sigkill_lock_inheritance_case util-linux-flock-sigkill "$real_flock" 'real util-linux flock'
}

test_auto_detected_non_util_linux_flock_is_skipped(){
    local root output rc
    root=$(make_case auto-non-util-flock)
    output="$root/output.log"

    set +e
    (
        trap - EXIT
        unset REAL_FLOCK_BIN
        PATH="$root/bin:$PATH" test_real_util_linux_flock_is_held_by_surviving_pipeline_after_parent_sigkill
    ) > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -eq 0 ] || { sed -n '1,120p' "$output" >&2; fail "auto-detected non-util-linux flock returned $rc instead of skipping"; }
    grep -Fq 'SKIP real util-linux flock inheritance' "$output" || fail 'auto-detected non-util-linux flock did not report SKIP'
    echo '[backup-test] PASS auto-detected non-util-linux flock is skipped'
}

test_explicit_non_util_linux_flock_fails(){
    local root output rc
    root=$(make_case explicit-non-util-flock)
    output="$root/output.log"

    set +e
    (
        trap - EXIT
        REAL_FLOCK_BIN="$root/bin/flock" test_real_util_linux_flock_is_held_by_surviving_pipeline_after_parent_sigkill
    ) > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'explicit non-util-linux REAL_FLOCK_BIN unexpectedly skipped or passed'
    grep -Fq 'REAL_FLOCK_BIN is not util-linux flock' "$output" || fail 'explicit non-util-linux REAL_FLOCK_BIN did not report the invalid binary'
    echo '[backup-test] PASS explicit non-util-linux REAL_FLOCK_BIN fails'
}

test_missing_flock_fails_closed(){
    local root args output rc finals
    root=$(make_case missing-flock)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" FLOCK_BIN="$root/bin/definitely-missing-flock" PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'missing flock unexpectedly reported success'
    finals=$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')
    [ "$finals" -eq 0 ] || fail "missing flock left $finals final artifacts"
    ! grep -Fq 'backup ok:' "$output" || fail 'missing flock printed a success marker'
    grep -Fq 'flock is required for backup locking' "$output" || fail 'missing injected flock did not report the required dependency'
    echo "[backup-test] PASS injected missing flock fails closed rc=$rc"
}

test_sigkill_releases_lock_and_next_run_succeeds(){
    local root args1 args2 output1 output2 started sent rc1 rc2 finals temps
    root=$(make_case sigkill-recovery)
    args1="$root/mysqldump-1.args"
    args2="$root/mysqldump-2.args"
    output1="$root/output-1.log"
    output2="$root/output-2.log"
    started="$root/mysqldump.started"
    sent="$root/signal.sent"

    set +e
    NZ_MYSQLDUMP_ARGS="$args1" NZ_MYSQLDUMP_STARTED="$started" NZ_MYSQLDUMP_DELAY=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash -c '
            target=$$
            marker=$1
            sent=$2
            script=$3
            (
                for _ in {1..100}; do
                    if [ -f "$marker" ]; then
                        : > "$sent"
                        kill -KILL "$target"
                        exit 0
                    fi
                    sleep 0.02
                done
            ) &
            exec bash "$script"
        ' _ "$started" "$sent" "$root/nezha-encrypted-backup.sh" > "$output1" 2>&1
    rc1=$?
    set -e

    [ "$rc1" -ne 0 ] || fail 'SIGKILL run unexpectedly reported success'
    [ -f "$started" ] || fail 'SIGKILL test never reached the dump boundary'
    [ -f "$sent" ] || fail 'SIGKILL was not sent after the dump boundary marker'
    sleep 1.2

    set +e
    NZ_MYSQLDUMP_ARGS="$args2" NZ_DATE_TIMESTAMP=20990101010102 PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output2" 2>&1
    rc2=$?
    set -e

    [ "$rc2" -eq 0 ] || { sed -n '1,120p' "$output2" >&2; fail "run after SIGKILL exited $rc2"; }
    finals=$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')
    temps=$(find "$root/out" -maxdepth 1 -type f -name '.*.tmp.*' | wc -l | tr -d ' ')
    [ "$finals" -eq 1 ] || fail "run after SIGKILL left $finals final artifacts, expected 1"
    [ "$temps" -eq 0 ] || fail "run after SIGKILL left $temps orphan temporary artifacts"
    [ ! -d "$root/flock-state" ] || fail 'run after SIGKILL left an active lock state'
    [ "$(grep -Fc 'db backup ok:' "$output2")" -eq 1 ] || fail 'run after SIGKILL did not report exactly one success'
    echo "[backup-test] PASS SIGKILL releases lock and next run succeeds rc=$rc1/$rc2"
}

files_artifacts(){
    find "$1" -maxdepth 1 -type f -name 'nezha-files-*.tar.gz.enc'
}

test_files_and_offsite_stages_complete_a_full_round(){
    local root args output farchive listing sentinel calls
    root=$(make_case files-offsite-success)
    args="$root/mysqldump.args"
    output="$root/output.log"

    NZ_MYSQLDUMP_ARGS="$args" NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1

    mapfile -t listing < <(files_artifacts "$root/out")
    [ "${#listing[@]}" -eq 1 ] || fail "full round produced ${#listing[@]} files archives, expected 1"
    farchive=${listing[0]}
    [[ "${farchive##*/}" =~ ^nezha-files-[0-9]{14}-[A-Za-z0-9]{6}\.tar\.gz\.enc$ ]] || fail "files archive name is not in the tokenised format: ${farchive##*/}"
    [ -s "$farchive" ] || fail 'files archive is empty'
    [ "$(stat -c%a -- "$farchive")" = '600' ] || fail 'files archive was published without 600 permissions'
    [ "$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')" -eq 1 ] || fail 'full round did not publish exactly one database artifact'

    # 归档实际内容必须同时含 storage/app 实体与 .env(换新机重建的两个必需项)。
    # 剥掉 openssl stub 的首行标记后用 tar -tf(自动识别): 沙箱 gzip stub 是直通不真压缩,
    # 所以这里不能写死 -tzf。
    tail -n +2 -- "$farchive" | tar -tf - > "$root/archive.listing" 2>/dev/null || fail 'files archive is not a readable tar payload'
    grep -Fq 'storage/app/uploaded.bin' "$root/archive.listing" || fail 'files archive does not contain the storage/app payload'
    grep -Fxq '.env' "$root/archive.listing" || fail 'files archive does not contain the .env secret bundle'

    grep -Fq 'files backup ok:' "$output" || fail 'full round did not log the files success line'
    grep -Fq 'db backup ok:' "$output" || fail 'full round did not log the database success line'
    grep -Fq 'R2 offsite push ok' "$output" || fail 'full round did not log the offsite success line'
    ! grep -Fq 'FAILED' "$output" || fail 'full round logged a failure line'

    calls="$root/rclone.calls"
    [ -f "$calls" ] || fail 'offsite stage never invoked rclone'
    grep -Fq -- '--bind 178.105.216.158 copy' "$calls" || fail 'offsite copy did not bind the whitelisted IPv4 source address'
    grep -Fq -- 'delete --min-age 30d' "$calls" || fail 'offsite stage did not run the 30d remote prune'
    # 锁文件是 $OUTDIR 内的运行时产物而非备份件, 必须被排除在异地副本之外
    grep -Fq -- '--exclude .nezha-encrypted-backup.lock' "$calls" || fail 'offsite copy did not exclude the runtime lock file'
    [ -f "$root/out/.nezha-encrypted-backup.lock" ] || fail 'test premise broken: the run did not create a lock file inside OUTDIR'
    sentinel="$root/offsite_last_ok"
    [ -s "$sentinel" ] || fail 'offsite sentinel was not refreshed'
    [[ "$(cat "$sentinel")" =~ ^[0-9]+$ ]] || fail 'offsite sentinel does not hold an epoch timestamp'
    [ ! -f "$root/python3.calls" ] || fail 'a successful round sent an alert mail'
    echo '[backup-test] PASS files + offsite stages complete a full round'
}

test_files_failure_keeps_database_artifact_and_continues(){
    local root args output rc
    root=$(make_case files-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_TAR_FAIL=1 NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -eq 0 ] || { sed -n '1,120p' "$output" >&2; fail "files failure aborted the round (exit $rc); it must log and continue"; }
    [ "$(files_artifacts "$root/out" | wc -l | tr -d ' ')" -eq 0 ] || fail 'files failure published a files archive anyway'
    [ "$(find "$root/out" -maxdepth 1 -type f -name '.nezha-files-*.tmp.*' | wc -l | tr -d ' ')" -eq 0 ] || fail 'files failure left a temporary archive behind'
    [ "$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')" -eq 1 ] || fail 'files failure discarded the already verified database artifact'
    grep -Fq 'FILES BACKUP FAILED' "$output" || fail 'files failure was not logged in the grep-stable format'
    grep -Fq 'db backup ok:' "$output" || fail 'files failure suppressed the database success line'
    grep -Fq 'R2 offsite push ok' "$output" || fail 'files failure prevented the offsite push of the database artifact'
    echo "[backup-test] PASS files failure keeps the database artifact and continues rc=$rc"
}

test_offsite_failure_alerts_and_exits_nonzero(){
    local root args output rc mail_env
    root=$(make_case offsite-failure)
    args="$root/mysqldump.args"
    output="$root/output.log"
    mail_env="$root/mail.env"

    set +e
    NZ_MYSQLDUMP_ARGS="$args" NZ_RCLONE_FAIL=1 NZ_MAIL_ENV_LOG="$mail_env" NZ_DATE_TIMESTAMP=20990101010101 \
        PATH="$root/bin:$PATH" bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1
    rc=$?
    set -e

    [ "$rc" -ne 0 ] || fail 'offsite failure unexpectedly reported success'
    grep -Fq 'R2 OFFSITE PUSH FAILED' "$output" || fail 'offsite failure was not logged in the grep-stable format'
    grep -Fq 'BACKUP POST-COMMIT WARNING:' "$output" || fail 'offsite failure did not identify the post-commit warning state'
    grep -Fq 'verified artifact kept:' "$output" || fail 'offsite failure did not report that the local artifacts were kept'
    [ -s "$root/python3.calls" ] || fail 'offsite failure did not invoke the alert mail helper'
    [ -s "$mail_env" ] || fail 'alert mail helper received no envelope'
    grep -Fq 'to=noctis201911@gmail.com' "$mail_env" || fail 'alert mail was not addressed to the operator'
    ! grep -Fq 'SUPER_SECRET_MAIL_PASSWORD' "$output" || fail 'alert path leaked the SMTP password to the log'
    [ ! -e "$root/offsite_last_ok" ] || fail 'offsite failure still refreshed the sentinel'
    [ -s "$root/r2push.err" ] || fail 'offsite failure did not capture rclone stderr for triage'
    [ "$(find "$root/out" -maxdepth 1 -type f -name '*.sql.gz.enc' | wc -l | tr -d ' ')" -eq 1 ] || fail 'offsite failure discarded the database artifact'
    [ "$(files_artifacts "$root/out" | wc -l | tr -d ' ')" -eq 1 ] || fail 'offsite failure discarded the files archive'
    echo "[backup-test] PASS offsite failure alerts and exits nonzero rc=$rc"
}

test_files_retention_prunes_only_whitelisted_excess(){
    local root args output i stamp token managed_count
    root=$(make_case files-retention)
    args="$root/mysqldump.args"
    output="$root/output.log"

    for ((i = 1; i <= 13; i++)); do
        printf -v stamp '202601010000%02d' "$i"
        printf -v token 'A%05d' "$i"
        printf 'managed-%s\n' "$i" > "$root/out/nezha-files-${stamp}-${token}.tar.gz.enc"
    done
    # 存量旧格式(无 token)必须同样被滚动管理, 否则老件永远不被清理
    printf 'legacy\n' > "$root/out/nezha-files-20260102000000.tar.gz.enc"
    # 非白名单件(人工留证/外部件)绝不能被误删
    printf 'external evidence\n' > "$root/out/nezha-files-manual-evidence.tar.gz.enc"
    printf 'unrelated\n' > "$root/out/unrelated-archive.tar.gz.enc"

    NZ_MYSQLDUMP_ARGS="$args" NZ_DATE_TIMESTAMP=20990101010101 PATH="$root/bin:$PATH" \
        bash "$root/nezha-encrypted-backup.sh" > "$output" 2>&1

    managed_count=$(find "$root/out" -maxdepth 1 -type f \
        \( -name 'nezha-files-??????????????-??????.tar.gz.enc' -o -name 'nezha-files-??????????????.tar.gz.enc' \) | wc -l | tr -d ' ')
    [ "$managed_count" -eq 14 ] || fail "files retention left $managed_count managed archives, expected 14"
    [ ! -f "$root/out/nezha-files-20260101000001-A00001.tar.gz.enc" ] || fail 'files retention did not prune the oldest managed archive'
    [ -f "$root/out/nezha-files-20260101000002-A00002.tar.gz.enc" ] || fail 'files retention pruned more than the oldest managed archive'
    [ -f "$root/out/nezha-files-20260102000000.tar.gz.enc" ] || fail 'files retention deleted a legacy-format archive that was still within the retention window'
    [ -f "$root/out/nezha-files-manual-evidence.tar.gz.enc" ] || fail 'files retention deleted a non-whitelisted external archive'
    [ -f "$root/out/unrelated-archive.tar.gz.enc" ] || fail 'files retention deleted an unrelated archive'
    [ "$(find "$root/out" -maxdepth 1 -type f -name 'nezha-files-20990101010101-??????.tar.gz.enc' | wc -l | tr -d ' ')" -eq 1 ] || fail 'files retention deleted the archive produced by the current round'
    echo '[backup-test] PASS files retention prunes only whitelisted excess'
}

test_auto_detected_non_util_linux_flock_is_skipped
test_explicit_non_util_linux_flock_fails
test_utf8mb4_dump_contract
test_failed_dump_leaves_no_backup_artifact
test_success_is_published_atomically_once
test_gzip_failure_is_clean
test_openssl_failure_is_clean
test_chmod_failure_is_clean
test_stat_failure_is_clean
test_retention_boundaries_ignore_external_files
test_retention_never_deletes_current_committed_artifact
test_retention_failure_keeps_committed_artifact
test_unlock_failure_keeps_committed_artifact
test_success_log_failure_keeps_committed_artifact
test_partial_retention_failure_never_deletes_new_artifact
test_concurrent_same_second_run_is_rejected
test_term_cleans_in_progress_backup
test_int_cleans_in_progress_backup
test_kernel_lock_is_held_by_surviving_pipeline_after_parent_sigkill
test_real_util_linux_flock_is_held_by_surviving_pipeline_after_parent_sigkill
test_missing_flock_fails_closed
test_sigkill_releases_lock_and_next_run_succeeds
test_files_and_offsite_stages_complete_a_full_round
test_files_failure_keeps_database_artifact_and_continues
test_offsite_failure_alerts_and_exits_nonzero
test_files_retention_prunes_only_whitelisted_excess
echo '[backup-test] ALL PASS'
