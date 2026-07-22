# ⚠️ 本文件是运维正本 /root/nezha-backup/README-RESTORE.txt 的入库快照
#
# 为什么入库: 2026-07-22 查出恢复手册既不在 git、也不在任何备份覆盖面内
#   ($OUTDIR 与 shared/storage 都不含 /root/nezha-backup/)。丢机时能从 R2
#   取回全部加密备份件, 却拿不到"怎么解密、怎么做 PITR"的说明 ——
#   有备份而解不开, 比没有备份更危险, 因为你会以为自己有。
#
# 快照时间: 2026-07-22
# 源文件 SHA-256: 08062aa9007a0f9d4e7bd53c943f67dbb6c7c5e7efdb7bf96746744c58adc2dc
#
# 🔴 运维时仍以 /root 那份为准; 两份可能漂移, 修改任一份后请同步另一份。
#   (nzwatch 6d 目前只对账备份脚本的 md5, 尚未覆盖本文档)
# 🔴 本文件只含路径与命令模板, 不含任何密钥/凭据内容 —— 修改时请保持这一点。

哪吒加密备份 — 恢复方法
======================================
每日自动备份(cron 每天 04:17)产出【两份】加密文件,都在 /www/backup/database/nezha-enc/,各保留最近 14 份:
  1) <库名>-<时间戳>-<随机6位>.sql.gz.enc      —— 数据库(全部表)
  2) nezha-files-<时间戳>-<随机6位>.tar.gz.enc  —— 上传文件 storage/app(商品/餐厅图、banner、会话附件、
       本地生活UGC、offline_payment 支付凭证) + .env(DB密码/Firebase/Google Maps Key/邮件凭证)
       〔2026-06-17 容灾QA 新增:此前只备份数据库,图片和 .env 没进备份〕
  〔2026-07-20 起文件名多一段随机6位后缀(原子发布用的唯一标记),存量旧件仍是无后缀的老格式,
    两种格式都在 14 份滚动保留内,恢复方法完全相同 —— 按上面的通配去找最新一份即可〕

解密密钥: /root/.nezha/backup.key
  这把钥匙丢了, 上面两份都永远解不开。务必离线另存(见文末)。
日志: /root/nezha-backup/backup.log

────────────────────────────────────────────────
【A. 恢复数据库】(最常见:误删数据找回 / 原机)
  openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in <文件>.sql.gz.enc | gunzip > /root/restore.sql
  mysql -u<用户> -p <数据库名> < /root/restore.sql && rm -f /root/restore.sql

【B. 恢复上传文件 + .env】
  ⚠️ 解压目标是【持久层 /www/wwwroot/api-deploy/shared】,不是应用目录。
     1a 部署边界改造后 storage 与 .env 的实体都在 shared,应用目录(api-deploy/current)里只是软链;
     备份也是从 shared 取的实体,解错地方 = 恢复了个寂寞(真出事时才发现)。〔2026-07-20 更正〕
  # 先看里面有什么(只列,不解到盘):
  openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in nezha-files-<时间戳>-<随机6位>.tar.gz.enc | tar -tzf - | head
  # 解到持久层(会覆盖现有 storage/app 与 .env,确认后再做):
  openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in nezha-files-<时间戳>-<随机6位>.tar.gz.enc | tar -xzf - -C /www/wwwroot/api-deploy/shared
  # 解完确认对外软链在: cd /www/wwwroot/api-deploy/current && php artisan storage:link

【C. 全新服务器(原机彻底坏了)】
  办法1(推荐,恢复后仍加密): 新机先装 keyring_file 插件并在 my.cnf 配:
      early-plugin-load = "keyring_file.so"
      keyring_file_data = /www/server/mysql-keyring/keyring
    用你离线存的 keyring.master.backup 还原这个 keyring 文件,重启 MySQL,再按 A 导入。
  办法2(省事,恢复后是明文,事后须重新启用加密): 导入时去掉加密标记:
    openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in <文件>.sql.gz.enc | gunzip | sed "s/ ENCRYPTION='Y'//g" | mysql -u<用户> -p <数据库名>
  之后按 B 还原文件 + .env。

────────────────────────────────────────────────
⚠️ 两把"救命钥匙"务必离线另存(U盘 + 密码管理器,别和服务器放一起):
   /root/.nezha/backup.key             —— 解每日备份用
   /root/.nezha/keyring.master.backup  —— 解加密表(PII全表加密)用
   (2026-06-17 已打包下载一份副本到本机,sha256 指纹在包内 sha256-指纹.txt)

────────────────────────────────────────────────
【D. 异地副本(Cloudflare R2)— 2026-06-21 上线】
  每日备份成功后, 两份 .enc 会自动推一份到 Cloudflare R2 对象存储(异地, 不在本机同盘)。
    桶: nezha-backup/nezha-enc/    供应商: Cloudflare R2(S3兼容)
    工具: rclone(配置 /root/.config/rclone/rclone.conf, remote 名 r2, 仅root可读)
    出站强制 IPv4: rclone 命令带 --bind 178.105.216.158 (R2 token 锁了这个IP, 双栈默认走IPv6会403)
    保留: 远端独立保留 30 天(本地保留14份); 用 copy 只增不删, 本地被清/被勒索不会级联删远端。
  从 R2 取回备份(原机或新机, 装好 rclone + 放好同一份 rclone.conf 后):
    rclone --bind 178.105.216.158 ls   r2:nezha-backup/nezha-enc/            # 列出远端所有备份
    rclone --bind 178.105.216.158 copy r2:nezha-backup/nezha-enc/<文件> .    # 下载某一份
  下载下来的 .enc 解密方法同 A/B/C(仍需 backup.key)。
  ⚠️ 新机恢复时 --bind 的IP要换成新机的出站IP, 并在 Cloudflare R2 token 白名单里加上新IP, 否则403。
  监控: 看门狗 nzwatch 每5分钟查 /root/nezha-backup/offsite_last_ok, >26h 未成功推送即邮件告警;
        推送失败时备份脚本也会立即发一封告警邮件(复用 nzwatch 邮件链路)。

⚠️ 两把"救命钥匙"仍只在本机/服务器: backup.key 与 keyring.master.backup 务必离线另存(密码管理器+U盘)。
   R2 上的是【加密】副本, 没有 backup.key 也解不开 —— 钥匙若随服务器一起丢, 异地副本=废。
   注:链上交易留痕 nezha_refund_records 有 ≥5年 法定留存义务; 异地副本上线后物理可靠性
       不再=单台机器(但异地推送仅覆盖每日加密备份的范围: DB + storage/app + .env)。

────────────────────────────────────────────────
【E. 时点恢复 PITR (2026-07-08 起 · binlog 已开启)】
现在 = 每日全量(04:17) + binlog(每5分钟加密推 R2) → 可恢复到"出事前几秒", 不再只是每日快照。
  RPO: 原机盘还在≈0; 总损(丢机)≈最近一次 binlog 异地推送(≤~5分钟)。
配置: my.cnf log-bin=mysql-bin / binlog_format=ROW / sync_binlog=1; 备份账号已授 RELOAD+REPLICATION CLIENT;
      全量 mysqldump 带 --master-data=2 (dump 内嵌位点); binlog 加密脚本 nzbinlog_offsite.sh (cron */5, 同一把 backup.key)。

恢复步骤:
 1) 先按 A 恢复最新全量到目标库(得到全量时刻的状态)。
 2) 取全量对应的 binlog 位点(--master-data=2 已写进 dump, 是注释行):
    openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in <全量>.sql.gz.enc | gunzip | grep -m1 'CHANGE MASTER'
    → 得到 MASTER_LOG_FILE='mysql-bin.NNNNNN'  MASTER_LOG_POS=P
 3) 备齐从该位点到目标时刻的 binlog:
    - 原机: 直接用 /www/server/data/mysql-bin.*
    - 新机: 从 R2 取加密 binlog 再解密:
        rclone --bind <新机出站IP> copy r2:nezha-backup/binlog/ ./binlog-enc/
        for f in ./binlog-enc/*.enc; do openssl enc -d -aes-256-cbc -pbkdf2 -pass file:<backup.key路径> -in "$f" -out "${f%.enc}"; done
 4) 回放 (⚠️ mysqlbinlog 要用全路径! 不在默认 PATH 里):
    /www/server/mysql/bin/mysqlbinlog --start-position=P [--stop-datetime='YYYY-MM-DD HH:MM:SS'] \
        mysql-bin.NNNNNN [后续文件按序...] | mysql -u<用户> -p <库名>
    · 恢复到"最新/出事前"→ 省略 --stop-datetime (回放到底)
    · 恢复到"某误操作之前"→ 用 --stop-datetime 卡在那一刻之前
    · 多个 binlog 文件一次全给(按序), --start-position 只作用于第一个
 ※ 2026-07-08 已实测通过: base + binlog 回放到 cutoff, 精确恢复到该刻(cutoff 前的写入有、之后的没有), 且回放经 --rewrite-db 定向不污染其它库。
 ※ binlog 明文含 PII, 故 R2 上是加密的(.enc); 解密与全量同一把钥匙 backup.key。钥匙丢=binlog 也解不开。
