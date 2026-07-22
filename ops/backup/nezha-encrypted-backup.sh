#!/bin/bash
set -uo pipefail
APP=/www/wwwroot/api.nezha.am
DATA=${DATA:-/www/wwwroot/api-deploy/shared}
PHP=/usr/bin/php
KEY=/root/.nezha/backup.key
OUTDIR=/www/backup/database/nezha-enc
KEEP=14
# 运维还原点目录($OPSNAP_PARENT/$OPSNAP_NAME): 改生产数据/图片前留的整行快照+逐张 SHA+还原器。
# 它既不在 git、也不在 storage/app —— 2026-07-22 前不在任何备份覆盖面内, 盘一清还原路径就没了。
OPSNAP_PARENT=${OPSNAP_PARENT:-/root/nzimg}
OPSNAP_NAME=${OPSNAP_NAME:-backup}
OPSNAP_KEEP=${OPSNAP_KEEP:-14}
OPSNAP_MAX_MB=${OPSNAP_MAX_MB:-512}
FLOCK_BIN=${FLOCK_BIN:-flock}
RCLONE_BIN=${RCLONE_BIN:-/usr/local/bin/rclone}
MAIL_HELPER=${MAIL_HELPER:-/www/wwwroot/api-deploy/current/nzwatch_mail.py}
R2_REMOTE=${R2_REMOTE:-r2:nezha-backup/nezha-enc}
R2_BIND=${R2_BIND:-178.105.216.158}
SENTINEL=${SENTINEL:-/root/nezha-backup/offsite_last_ok}
PERR=${PERR:-/root/nezha-backup/r2push.err}
ALERT_TO=${ALERT_TO:-noctis201911@gmail.com}
TMP=''
OUT=''
FTMP=''
FOUT=''
OTMP=''
OOUT=''
COMMITTED=0
LOCK="$OUTDIR/.nezha-encrypted-backup.lock"
LOCK_FD_OPEN=0
LOCK_ACQUIRED=0

cleanup(){
    local rc=$?
    trap - EXIT INT TERM
    unset MYSQL_PWD
    if [ -n "$TMP" ]; then
        rm -f -- "$TMP" >/dev/null 2>&1 || true
    fi
    if [ -n "$FTMP" ]; then
        rm -f -- "$FTMP" >/dev/null 2>&1 || true
    fi
    if [ -n "$OTMP" ]; then
        rm -f -- "$OTMP" >/dev/null 2>&1 || true
    fi
    if [ "$LOCK_ACQUIRED" -eq 1 ]; then
        "$FLOCK_BIN" -u 9 >/dev/null 2>&1 || true
    fi
    if [ "$LOCK_FD_OPEN" -eq 1 ]; then
        exec 9>&- || true
    fi
    exit "$rc"
}

fail(){
    if [ "$COMMITTED" -eq 1 ]; then
        printf '[%s] BACKUP POST-COMMIT WARNING: %s; verified artifact kept: %s\n' "$(date)" "$1" "$OUT" >&2
    else
        printf '[%s] BACKUP FAILED: %s\n' "$(date)" "$1" >&2
    fi
    exit "${2:-1}"
}

on_signal(){
    fail "interrupted by $2" "$1"
}

# 复用 nzwatch 邮件链路; SMTP 凭证运行时从 $DATA/.env 读, 脚本文本不含密码
nezha_alert(){
    local subj="$1" body="$2" ef="$DATA/.env"
    g(){ grep -E "^$1=" "$ef" | head -1 | cut -d= -f2- | sed -E 's/^"//; s/"$//'; }
    NZ_HOST="$(g MAIL_HOST)" NZ_PORT="$(g MAIL_PORT)" NZ_USER="$(g MAIL_USERNAME)" \
    NZ_PASS="$(g MAIL_PASSWORD)" NZ_FROM="$(g MAIL_FROM_ADDRESS)" NZ_TO="$ALERT_TO" \
    NZ_SUBJ="$subj" NZ_BODY="$body" python3 "$MAIL_HELPER" >/dev/null 2>&1
}

prune_old_backups(){
    local file base rest stamp token sorted i delete_count deleted
    local -a managed=()

    shopt -s nullglob
    for file in "$OUTDIR"/"$DB"-*.sql.gz.enc; do
        base=${file##*/}
        rest=${base#"$DB"-}
        if [[ "$rest" =~ ^[0-9]{14}\.sql\.gz\.enc$ ]]; then
            managed+=("$file")
            continue
        fi

        stamp=${rest%%-*}
        if [ "$stamp" = "$rest" ]; then
            continue
        fi
        token=${rest#*-}
        token=${token%.sql.gz.enc}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            managed+=("$file")
        fi
    done
    shopt -u nullglob

    if [ "${#managed[@]}" -le "$KEEP" ]; then
        return 0
    fi
    if ! sorted=$(printf '%s\n' "${managed[@]}" | sort -r); then
        return 1
    fi
    if ! mapfile -t managed <<< "$sorted"; then
        return 1
    fi
    delete_count=$((${#managed[@]} - KEEP))
    deleted=0
    for ((i = ${#managed[@]} - 1; i >= 0 && deleted < delete_count; i--)); do
        if [ "${managed[$i]}" = "$OUT" ]; then
            continue
        fi
        if ! rm -f -- "${managed[$i]}"; then
            return 1
        fi
        deleted=$((deleted + 1))
    done
    [ "$deleted" -eq "$delete_count" ]
}

# 文件备份件的滚动清理: 白名单同时认旧格式(无 token, 存量件)与新格式(带 token)
prune_old_files_backups(){
    local file base rest stamp token sorted i delete_count deleted
    local -a managed=()

    shopt -s nullglob
    for file in "$OUTDIR"/nezha-files-*.tar.gz.enc; do
        base=${file##*/}
        rest=${base#nezha-files-}
        if [[ "$rest" =~ ^[0-9]{14}\.tar\.gz\.enc$ ]]; then
            managed+=("$file")
            continue
        fi

        stamp=${rest%%-*}
        if [ "$stamp" = "$rest" ]; then
            continue
        fi
        token=${rest#*-}
        token=${token%.tar.gz.enc}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            managed+=("$file")
        fi
    done
    shopt -u nullglob

    if [ "${#managed[@]}" -le "$KEEP" ]; then
        return 0
    fi
    if ! sorted=$(printf '%s\n' "${managed[@]}" | sort -r); then
        return 1
    fi
    if ! mapfile -t managed <<< "$sorted"; then
        return 1
    fi
    delete_count=$((${#managed[@]} - KEEP))
    deleted=0
    for ((i = ${#managed[@]} - 1; i >= 0 && deleted < delete_count; i--)); do
        if [ "${managed[$i]}" = "$FOUT" ]; then
            continue
        fi
        if ! rm -f -- "${managed[$i]}"; then
            return 1
        fi
        deleted=$((deleted + 1))
    done
    [ "$deleted" -eq "$delete_count" ]
}

# 运维还原点归档件的滚动清理: 只认本前缀 nezha-opsnap-<14位时间戳>-<6位token>,
# 与 DB 件($DB-*)/文件件(nezha-files-*)/高频件(nezha-filesfreq-*)的白名单互不相交。
prune_old_opsnap_backups(){
    local file base rest stamp token sorted i delete_count deleted
    local -a managed=()

    shopt -s nullglob
    for file in "$OUTDIR"/nezha-opsnap-*.tar.gz.enc; do
        base=${file##*/}
        rest=${base#nezha-opsnap-}
        stamp=${rest%%-*}
        if [ "$stamp" = "$rest" ]; then
            continue
        fi
        token=${rest#*-}
        token=${token%.tar.gz.enc}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            managed+=("$file")
        fi
    done
    shopt -u nullglob

    if [ "${#managed[@]}" -le "$OPSNAP_KEEP" ]; then
        return 0
    fi
    if ! sorted=$(printf '%s\n' "${managed[@]}" | sort -r); then
        return 1
    fi
    if ! mapfile -t managed <<< "$sorted"; then
        return 1
    fi
    delete_count=$((${#managed[@]} - OPSNAP_KEEP))
    deleted=0
    for ((i = ${#managed[@]} - 1; i >= 0 && deleted < delete_count; i--)); do
        if [ "${managed[$i]}" = "$OOUT" ]; then
            continue
        fi
        if ! rm -f -- "${managed[$i]}"; then
            return 1
        fi
        deleted=$((deleted + 1))
    done
    [ "$deleted" -eq "$delete_count" ]
}

remove_orphaned_temp_files(){
    local file base rest stamp token

    shopt -s nullglob
    for file in "$OUTDIR"/."$DB"-*.tmp.*; do
        base=${file##*/}
        rest=${base#."$DB"-}
        stamp=${rest%%.tmp.*}
        token=${rest##*.tmp.}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            if ! rm -f -- "$file"; then
                shopt -u nullglob
                return 1
            fi
        fi
    done
    for file in "$OUTDIR"/.nezha-opsnap-*.tmp.*; do
        base=${file##*/}
        rest=${base#.nezha-opsnap-}
        stamp=${rest%%.tmp.*}
        token=${rest##*.tmp.}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            if ! rm -f -- "$file"; then
                shopt -u nullglob
                return 1
            fi
        fi
    done
    for file in "$OUTDIR"/.nezha-files-*.tmp.*; do
        base=${file##*/}
        rest=${base#.nezha-files-}
        stamp=${rest%%.tmp.*}
        token=${rest##*.tmp.}
        if [[ "$stamp" =~ ^[0-9]{14}$ ]] && [[ "$token" =~ ^[A-Za-z0-9]{6}$ ]]; then
            if ! rm -f -- "$file"; then
                shopt -u nullglob
                return 1
            fi
        fi
    done
    shopt -u nullglob
}

# 文件备份(上传文件 storage/app + .env), 与 DB 件同一把钥匙加密.
# storage/app 含商品/餐厅图、banner、会话附件、本地生活UGC、offline_payment 支付凭证(不在DB、不在git)
# .env 含 DB 密码/Firebase/Google Maps Key/邮件凭证等 secret —— 换新机重建必需
# 1a 部署边界改造后 storage/.env 实体在 $DATA, 工作树是软链, 故从 $DATA 取实体
# 失败语义: 记日志 + 继续(绝不因文件段失败丢弃已验证发布的 DB 件)
run_files_backup(){
    local size
    local -a pst
    local trc frc

    if ! FTMP=$(mktemp "$OUTDIR/.nezha-files-${TS}.tmp.XXXXXX"); then
        FTMP=''
        printf '[%s] FILES BACKUP FAILED: cannot create temporary archive\n' "$(date)"
        return 1
    fi
    FOUT="$OUTDIR/nezha-files-${TS}-${FTMP##*.tmp.}.tar.gz.enc"
    if [ -e "$FOUT" ]; then
        printf '[%s] FILES BACKUP FAILED: archive destination already exists\n' "$(date)"
        rm -f -- "$FTMP"; FTMP=''; FOUT=''
        return 1
    fi

    tar -czf - --warning=no-file-changed -C "$DATA" storage/app .env | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$FTMP"
    pst=("${PIPESTATUS[@]}"); trc=${pst[0]:-1}; frc=${pst[1]:-1}
    if [ "$frc" -ne 0 ] || [ "$trc" -ge 2 ]; then
        printf '[%s] FILES BACKUP FAILED tar=%s openssl=%s\n' "$(date)" "$trc" "$frc"
        rm -f -- "$FTMP"; FTMP=''; FOUT=''
        return 1
    fi
    if ! chmod 600 "$FTMP"; then
        printf '[%s] FILES BACKUP FAILED: cannot secure temporary archive\n' "$(date)"
        rm -f -- "$FTMP"; FTMP=''; FOUT=''
        return 1
    fi
    if ! size=$(stat -c%s -- "$FTMP") || [[ ! "$size" =~ ^[0-9]+$ ]] || [ "$size" -le 0 ]; then
        printf '[%s] FILES BACKUP FAILED: temporary archive is empty or unreadable\n' "$(date)"
        rm -f -- "$FTMP"; FTMP=''; FOUT=''
        return 1
    fi
    if ! mv -- "$FTMP" "$FOUT"; then
        printf '[%s] FILES BACKUP FAILED: cannot publish archive atomically\n' "$(date)"
        rm -f -- "$FTMP"; FTMP=''; FOUT=''
        return 1
    fi
    FTMP=''
    printf '[%s] files backup ok: %s (%s bytes)\n' "$(date)" "$FOUT" "$size"
}

# 运维还原点归档: 把 $OPSNAP_PARENT/$OPSNAP_NAME 整目录加密成第三份归档件, 随本轮一起推 R2。
# 目录不存在 = 跳过(不是错误: 没有待保护的还原点时它本就该是空的);
# 超过 $OPSNAP_MAX_MB = 拒绝归档并留一行 FAILED(防有人往里丢大件, 把备份窗口和 R2 撑爆而没人知道);
# 失败语义与 files 段一致: 记日志 + 继续, 绝不因它丢掉已发布的 DB 件。
run_opsnap_backup(){
    local src size mb
    local -a pst
    local trc frc

    src="$OPSNAP_PARENT/$OPSNAP_NAME"
    if [ ! -d "$src" ]; then
        printf '[%s] opsnap backup skipped: %s does not exist\n' "$(date)" "$src"
        return 0
    fi
    mb=$(du -sm -- "$src" 2>/dev/null | cut -f1)
    if [[ ! "$mb" =~ ^[0-9]+$ ]]; then
        printf '[%s] OPSNAP BACKUP FAILED: cannot measure %s\n' "$(date)" "$src"
        return 1
    fi
    if [ "$mb" -gt "$OPSNAP_MAX_MB" ]; then
        printf '[%s] OPSNAP BACKUP FAILED: %s is %sMB, over the %sMB cap; not archived\n' "$(date)" "$src" "$mb" "$OPSNAP_MAX_MB"
        return 1
    fi
    if ! OTMP=$(mktemp "$OUTDIR/.nezha-opsnap-${TS}.tmp.XXXXXX"); then
        OTMP=''
        printf '[%s] OPSNAP BACKUP FAILED: cannot create temporary archive\n' "$(date)"
        return 1
    fi
    OOUT="$OUTDIR/nezha-opsnap-${TS}-${OTMP##*.tmp.}.tar.gz.enc"
    if [ -e "$OOUT" ]; then
        printf '[%s] OPSNAP BACKUP FAILED: archive destination already exists\n' "$(date)"
        rm -f -- "$OTMP"; OTMP=''; OOUT=''
        return 1
    fi

    tar -czf - --warning=no-file-changed -C "$OPSNAP_PARENT" "$OPSNAP_NAME" | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$OTMP"
    pst=("${PIPESTATUS[@]}"); trc=${pst[0]:-1}; frc=${pst[1]:-1}
    if [ "$frc" -ne 0 ] || [ "$trc" -ge 2 ]; then
        printf '[%s] OPSNAP BACKUP FAILED tar=%s openssl=%s\n' "$(date)" "$trc" "$frc"
        rm -f -- "$OTMP"; OTMP=''; OOUT=''
        return 1
    fi
    if ! chmod 600 "$OTMP"; then
        printf '[%s] OPSNAP BACKUP FAILED: cannot secure temporary archive\n' "$(date)"
        rm -f -- "$OTMP"; OTMP=''; OOUT=''
        return 1
    fi
    if ! size=$(stat -c%s -- "$OTMP") || [[ ! "$size" =~ ^[0-9]+$ ]] || [ "$size" -le 0 ]; then
        printf '[%s] OPSNAP BACKUP FAILED: temporary archive is empty or unreadable\n' "$(date)"
        rm -f -- "$OTMP"; OTMP=''; OOUT=''
        return 1
    fi
    if ! mv -- "$OTMP" "$OOUT"; then
        printf '[%s] OPSNAP BACKUP FAILED: cannot publish archive atomically\n' "$(date)"
        rm -f -- "$OTMP"; OTMP=''; OOUT=''
        return 1
    fi
    OTMP=''
    printf '[%s] opsnap backup ok: %s (%s bytes)\n' "$(date)" "$OOUT" "$size"
}

trap cleanup EXIT
trap 'on_signal 130 INT' INT
trap 'on_signal 143 TERM' TERM

if ! mkdir -p "$OUTDIR"; then
    fail 'cannot create output directory'
fi
if ! chmod 700 "$OUTDIR"; then
    fail 'cannot secure output directory'
fi
if ! command -v "$FLOCK_BIN" >/dev/null 2>&1; then
    fail 'flock is required for backup locking'
fi
if ! exec 9>"$LOCK"; then
    fail 'cannot open backup lock file'
fi
LOCK_FD_OPEN=1
if ! "$FLOCK_BIN" -n 9; then
    fail 'another backup is active or the backup lock is unavailable'
fi
LOCK_ACQUIRED=1
if ! cd "$APP"; then
    fail 'cannot enter application directory'
fi
if ! CREDS=$("$PHP" -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=config("database.connections.".config("database.default")); echo $c["database"]."\t".$c["username"]."\t".$c["password"]."\t".($c["host"]??"127.0.0.1")."\t".($c["port"]??"3306");'); then
    fail 'cannot load database credentials'
fi
DB=$(printf '%s' "$CREDS" | cut -f1); U=$(printf '%s' "$CREDS" | cut -f2); P=$(printf '%s' "$CREDS" | cut -f3); H=$(printf '%s' "$CREDS" | cut -f4); PORT=$(printf '%s' "$CREDS" | cut -f5)
if [[ ! "$DB" =~ ^[A-Za-z0-9_]+$ ]] || [ -z "$U" ] || [ -z "$H" ] || [ -z "$PORT" ]; then
    fail 'invalid database connection metadata'
fi
if ! remove_orphaned_temp_files; then
    fail 'cannot remove an orphaned temporary backup file'
fi
if ! TS=$(date +%Y%m%d%H%M%S); then
    fail 'cannot obtain backup timestamp'
fi
if [[ ! "$TS" =~ ^[0-9]{14}$ ]]; then
    fail 'invalid backup timestamp'
fi
if ! TMP=$(mktemp "$OUTDIR/.${DB}-${TS}.tmp.XXXXXX"); then
    fail 'cannot create temporary backup file'
fi
TOKEN=${TMP##*.tmp.}
OUT="$OUTDIR/${DB}-${TS}-${TOKEN}.sql.gz.enc"
if [ -e "$OUT" ]; then
    fail 'backup destination already exists'
fi
export MYSQL_PWD="$P"
# The pipeline must inherit FD 9 so a parent-shell SIGKILL cannot release the
# kernel lock while dump/compression/encryption children are still running.
# --master-data=2 写入 CHANGE MASTER 注释行, 是 README-RESTORE §E PITR 的锚点, 不可去。
if ! (mysqldump --default-character-set=utf8mb4 --no-tablespaces --master-data=2 --single-transaction --quick --skip-lock-tables --routines --triggers -h"$H" -P"$PORT" -u"$U" "$DB" | gzip | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$TMP"); then
    fail 'backup pipeline failed'
fi
unset MYSQL_PWD
if ! chmod 600 "$TMP"; then
    fail 'cannot secure temporary backup file'
fi
if ! SIZE=$(stat -c%s -- "$TMP"); then
    fail 'cannot verify temporary backup size'
fi
if [[ ! "$SIZE" =~ ^[0-9]+$ ]] || [ "$SIZE" -le 0 ]; then
    fail 'temporary backup file is empty'
fi
if ! mv -- "$TMP" "$OUT"; then
    fail 'cannot publish backup atomically'
fi
TMP=''
COMMITTED=1
if ! prune_old_backups; then
    fail 'cannot enforce backup retention policy'
fi
run_files_backup || true
prune_old_files_backups || printf '[%s] FILES BACKUP FAILED: cannot enforce files retention policy\n' "$(date)"
# 放在 'db backup ok' 之前: nzdaily.sh 只 tail -2 backup.log, 插在后面会把 db 那行挤出日报视野。
run_opsnap_backup || true
prune_old_opsnap_backups || printf '[%s] OPSNAP BACKUP FAILED: cannot enforce opsnap retention policy\n' "$(date)"
if ! printf '[%s] db backup ok: %s (%s bytes)\n' "$(date)" "$OUT" "$SIZE"; then
    fail 'cannot report backup success'
fi

# ===== 异地推送 Cloudflare R2 (off-site) — 2026-06-21 加 =====
# 把已加密的备份件原样上传到 R2(异地). 用 copy(只增不删: 防本地被清/勒索级联删远端);
# 远端独立 prune 30 天. 强制 --bind IPv4(178.105.216.158) 匹配 R2 token IP 白名单(双栈默认走IPv6会被403).
# --exclude: 锁文件是加固版新引入的、$OUTDIR 内的 0 字节运行时产物, 不是备份件;
# 它每轮 mtime 都变, 不排除就会天天重传一个空对象上 R2(业主 2026-07-20 批准加此排除)。
if "$RCLONE_BIN" --bind "$R2_BIND" copy "$OUTDIR" "$R2_REMOTE/" --exclude "${LOCK##*/}" \
      --transfers 2 --retries 3 --contimeout 30s --timeout 120s 2>"$PERR"; then
    "$RCLONE_BIN" --bind "$R2_BIND" delete --min-age 30d "$R2_REMOTE/" >/dev/null 2>&1 || true
    date +%s > "$SENTINEL"
    printf '[%s] R2 offsite push ok -> %s\n' "$(date)" "$R2_REMOTE"
else
    printf '[%s] R2 OFFSITE PUSH FAILED (see %s)\n' "$(date)" "$PERR"
    nezha_alert "🔴 哪吒异地备份推送失败 ($(hostname))" "$(date '+%F %T')  R2 异地备份推送失败。
本地备份仍在 $OUTDIR(未受影响), 但 Cloudflare R2 异地副本未更新。
排查: tail /root/nezha-backup/backup.log 和 $PERR
若持续失败=异地副本停更, 丢机将丢失留存数据。"
    fail 'R2 offsite push failed'
fi

if ! "$FLOCK_BIN" -u 9; then
    fail 'cannot release backup lock'
fi
LOCK_ACQUIRED=0
if ! exec 9>&-; then
    fail 'cannot close backup lock descriptor'
fi
LOCK_FD_OPEN=0
trap '' INT TERM
