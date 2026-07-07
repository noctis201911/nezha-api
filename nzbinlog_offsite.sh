#!/bin/bash
# nzbinlog_offsite.sh — 每5分钟把 MySQL binlog 用 backup.key 加密后推 R2 异地(近零 RPO 的异地保障)。
# 🔴 binlog 含明文 PII → 必须加密后才出站(与每日备份同一把钥匙 backup.key 同套 openssl 加密)。
# 只读 binlog, 不改 MySQL; MySQL 自动按 max_binlog_size 轮转, 本脚本不强制 FLUSH(免文件爆炸)。
set -uo pipefail
DATADIR=/www/server/data
STAGE=/www/backup/binlog-enc
KEY=/root/.nezha/backup.key
R2=r2:nezha-backup/binlog
BIND=178.105.216.158
ERRLOG=/tmp/nzbinlog_offsite.err
mkdir -p "$STAGE"; chmod 700 "$STAGE"

# 1) 加密每个 binlog(新增的完成文件, 或还在长的当前文件→重新加密其最新字节)
for BL in "$DATADIR"/mysql-bin.0*; do
  [ -f "$BL" ] || continue
  N=$(basename "$BL"); ENC="$STAGE/$N.enc"
  if [ ! -f "$ENC" ] || [ "$BL" -nt "$ENC" ]; then
    openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:"$KEY" -in "$BL" -out "$ENC.tmp" 2>>"$ERRLOG" && mv "$ENC.tmp" "$ENC" && chmod 600 "$ENC"
  fi
done

# 2) 推 R2(copy 只增/更新, 不删远端: 防本地被清级联删远端)
/usr/local/bin/rclone --bind "$BIND" copy "$STAGE" "$R2/" --include '*.enc' --transfers 2 --retries 3 --contimeout 30s --timeout 120s 2>>"$ERRLOG"

# 3) 远端保留 30 天
/usr/local/bin/rclone --bind "$BIND" delete --min-age 30d "$R2/" >/dev/null 2>&1 || true

# 4) 本地 staging 清理: 源 binlog 已被 MySQL 过期purge(expire_logs_days=10)的孤儿 .enc 删掉
for ENC in "$STAGE"/mysql-bin.*.enc; do
  [ -f "$ENC" ] || continue
  BN=$(basename "$ENC" .enc)
  [ -f "$DATADIR/$BN" ] || rm -f "$ENC"
done
echo "[$(date -u +%FT%TZ)] binlog offsite ok (staged+pushed)"
