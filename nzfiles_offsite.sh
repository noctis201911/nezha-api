#!/bin/bash
# nzfiles_offsite.sh — 每6h 把上传文件(storage/app)+.env 加密备份+推R2, 补文件RPO(binlog只救DB不救文件)。
# 独立于每日全量; 同一把 backup.key; 命名 nezha-filesfreq-* 与每日 nezha-files-* 区分, 各自轮转互不误删。
set -uo pipefail
DATA=/www/wwwroot/api-deploy/shared       # storage/.env 实体在此(1a部署边界)
OUTDIR=/www/backup/database/nezha-enc
KEY=/root/.nezha/backup.key
KEEP=8                                     # 本地留8份=2天(每6h)
TS=$(date +%Y%m%d%H%M%S)
R2=r2:nezha-backup/nezha-enc
BIND=178.105.216.158
OUT="$OUTDIR/nezha-filesfreq-${TS}.tar.gz.enc"

tar -czf - --warning=no-file-changed -C "$DATA" storage/app .env 2>/dev/null | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -out "$OUT"
FRC=${PIPESTATUS[1]:-1}
if [ "$FRC" -ne 0 ]; then echo "[$(date -u +%FT%TZ)] files-freq FAILED (openssl rc=$FRC)"; rm -f "$OUT"; exit 1; fi
chmod 600 "$OUT"

# 本地滚动保留(只动本前缀)
ls -1t "$OUTDIR"/nezha-filesfreq-*.tar.gz.enc 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f

# 推 R2(只本前缀)
/usr/local/bin/rclone --bind "$BIND" copy "$OUTDIR" "$R2/" --include 'nezha-filesfreq-*.enc' --transfers 2 --retries 3 --timeout 180s 2>/dev/null
# R2 保留7天(只本前缀, 不碰每日 nezha-files-*)
/usr/local/bin/rclone --bind "$BIND" delete --min-age 7d "$R2/" --include 'nezha-filesfreq-*.enc' >/dev/null 2>&1 || true

echo "[$(date -u +%FT%TZ)] files-freq backup ok: $(basename "$OUT") ($(stat -c%s "$OUT") bytes)"
