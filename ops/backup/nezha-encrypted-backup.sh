#!/bin/bash
set -uo pipefail
APP=/www/wwwroot/api.nezha.am
PHP=/usr/bin/php
KEY=/root/.nezha/backup.key
OUTDIR=/www/backup/database/nezha-enc
KEEP=14
TS=$(date +%Y%m%d%H%M%S)
mkdir -p "$OUTDIR"; chmod 700 "$OUTDIR"
cd "$APP" || exit 1
CREDS=$("$PHP" -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=config("database.connections.".config("database.default")); echo $c["database"]."\t".$c["username"]."\t".$c["password"]."\t".($c["host"]??"127.0.0.1")."\t".($c["port"]??"3306");') || { echo "creds fail"; exit 1; }
DB=$(printf '%s' "$CREDS" | cut -f1); U=$(printf '%s' "$CREDS" | cut -f2); P=$(printf '%s' "$CREDS" | cut -f3); H=$(printf '%s' "$CREDS" | cut -f4); PORT=$(printf '%s' "$CREDS" | cut -f5)
OUT="$OUTDIR/${DB}-${TS}.sql.gz.enc"
export MYSQL_PWD="$P"
set -o pipefail
mysqldump --no-tablespaces --single-transaction --quick --skip-lock-tables --routines --triggers -h"$H" -P"$PORT" -u"$U" "$DB" | gzip | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$OUT"
RC=$?
unset MYSQL_PWD
if [ $RC -ne 0 ]; then echo "[$(date)] BACKUP FAILED rc=$RC"; rm -f "$OUT"; exit 1; fi
chmod 600 "$OUT"
ls -1t "$OUTDIR"/*.sql.gz.enc 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f
echo "[$(date)] backup ok: $OUT ($(stat -c%s "$OUT") bytes)"
