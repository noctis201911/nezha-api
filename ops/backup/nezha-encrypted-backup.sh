#!/bin/bash
set -uo pipefail
APP=/www/wwwroot/api.nezha.am
PHP=/usr/bin/php
KEY=/root/.nezha/backup.key
OUTDIR=/www/backup/database/nezha-enc
KEEP=14
FLOCK_BIN=${FLOCK_BIN:-flock}
TMP=''
OUT=''
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
    shopt -u nullglob
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
if ! (mysqldump --default-character-set=utf8mb4 --no-tablespaces --single-transaction --quick --skip-lock-tables --routines --triggers -h"$H" -P"$PORT" -u"$U" "$DB" | gzip | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$TMP"); then
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
if ! "$FLOCK_BIN" -u 9; then
    fail 'cannot release backup lock'
fi
LOCK_ACQUIRED=0
if ! exec 9>&-; then
    fail 'cannot close backup lock descriptor'
fi
LOCK_FD_OPEN=0
trap '' INT TERM
if ! printf '[%s] backup ok: %s (%s bytes)\n' "$(date)" "$OUT" "$SIZE"; then
    fail 'cannot report backup success'
fi
