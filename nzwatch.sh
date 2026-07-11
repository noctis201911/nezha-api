#!/bin/bash
# 哪吒服务器看门狗 (nzwatch) — cron 每5分钟跑; 阈值突破→邮件告警; 绝不自动 kill; 带每类1小时冷却防刷屏
# 用法:  cron 自动跑  |  手动测试邮件链路:  bash nzwatch.sh test
# 由 2026-06-16 "孤儿空循环烧CPU 7天致全站慢" 事件催生; 配套体检见 nzhealth.sh
ALERT_TO="noctis201911@gmail.com"
ENVF="/www/wwwroot/api.nezha.am/.env"
STATE_DIR="/tmp/nzwatch"
COOLDOWN=3600
CORES=$(nproc)
HOSTN=$(hostname)
mkdir -p "$STATE_DIR"

ALERTS=""; CATS=""
add(){ ALERTS="${ALERTS}- $1
"; CATS="${CATS}$2,"; }

send_mail(){
  local subj="$1" body="$2"
  get(){ grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -E 's/^"//; s/"$//'; }
  export NZ_HOST="$(get MAIL_HOST)"   NZ_PORT="$(get MAIL_PORT)"
  export NZ_USER="$(get MAIL_USERNAME)" NZ_PASS="$(get MAIL_PASSWORD)"
  export NZ_FROM="$(get MAIL_FROM_ADDRESS)" NZ_TO="$ALERT_TO"
  export NZ_SUBJ="$subj" NZ_BODY="$body"
  python3 /www/wwwroot/api.nezha.am/nzwatch_mail.py
}

# ---- 测试模式: 直接发一封, 验证邮件链路 ----
if [ "$1" = "test" ]; then
  send_mail "✅ 哪吒看门狗测试 ($HOSTN)" "这是一封测试告警 (nzwatch test)  $(date '+%F %T')
收到这封说明: 看门狗邮件链路正常, 真出问题时会发到 $ALERT_TO。
本看门狗只告警、不会自动 kill 任何进程。"
  exit $?
fi

# ===== 阈值检查 (阈值都偏保守, 避免构建/临时尖峰误报) =====

# 1. 持续高CPU进程: >80% 且 已跑>30分钟, 排除常驻服务 → 跑飞/空转嫌疑(构建<30min不会中招)
PROC=$(ps -eo pid,ppid,pcpu,etimes,comm,args --sort=-pcpu 2>/dev/null | \
  awk 'NR>1 && $3+0>80 && $4+0>1800 && $5!~/^(mysqld|nginx|redis-server|php-fpm|hysteria)$/ {printf "PID=%s PPID=%s CPU=%s%% 已跑%d分钟 %s\n",$1,$2,$3,int($4/60),$6}')
if [ -n "$PROC" ]; then while IFS= read -r l; do add "持续高CPU(疑跑飞/空转): $l" "highcpu"; done <<< "$PROC"; fi

# 2. 空转循环嫌疑(写错条件的后台 until/while 循环)
LOOP=$(ps -eo pid,pcpu,etimes,args 2>/dev/null | grep -iE 'until \[|while true|do :; *done|do : *; *done' | grep -vE 'grep|nzwatch|nzhealth')
[ -n "$LOOP" ] && add "空转循环进程: $(echo "$LOOP" | head -3 | tr '\n' ';')" "loop"

# 3. 15分钟负载持续过载(用load15, 构建的2分钟尖峰不会把load15顶上去)
LOAD15=$(cut -d' ' -f3 /proc/loadavg)
awk -v l="$LOAD15" -v c="$CORES" 'BEGIN{exit !(l+0 > c*2)}' && add "15分钟负载 $LOAD15 > 核心数×2 (=$((CORES*2))) — 持续过载" "load"

# 4. 磁盘根分区 >90%
DISK=$(df -P / 2>/dev/null | tail -1 | awk '{print $5+0}')
[ "${DISK:-0}" -gt 90 ] && add "根分区磁盘已用 ${DISK}% — 接近满,会致写失败/500" "disk"

# 5. php-fpm 逼近上限
FPM=$(ps -eo comm 2>/dev/null | grep -c '[p]hp-fpm')
MAXC=$(grep -rh 'pm.max_children' /www/server/php/*/etc/php-fpm.d/*.conf 2>/dev/null | grep -v ';' | grep -oE '[0-9]+' | head -1)
[ -n "$MAXC" ] && [ "$FPM" -ge "$((MAXC-2))" ] && add "php-fpm 进程 $FPM 逼近上限 $MAXC — 高峰排队致变慢/超时" "fpm"

# 6. 备份新鲜度: 最新加密数据库备份 >26h 未更新 → 备份可能静默失败(出事才发现没备份)
BK=$(ls -1t /www/backup/database/nezha-enc/*.sql.gz.enc 2>/dev/null | head -1)
if [ -z "$BK" ]; then
  add "未找到任何数据库备份文件 — 每日备份可能未在产出" "backup"
else
  BAGE=$(( ( $(date +%s) - $(stat -c %Y "$BK") ) / 3600 ))
  [ "$BAGE" -gt 26 ] && add "最新数据库备份已 ${BAGE}h 未更新 (>26h) — 每日备份可能静默失败" "backup"
fi

# 6b. 异地备份(R2)新鲜度: off-site 推送 >26h 未成功 → 异地副本停更(本地在但丢机即丢留存)
OSOK="/root/nezha-backup/offsite_last_ok"
if [ ! -f "$OSOK" ]; then
  add "异地备份(Cloudflare R2)从未成功推送 — 当前仅有本地备份, 丢机即丢" "offsite"
else
  OAGE=$(( ( $(date +%s) - $(cat "$OSOK" 2>/dev/null || echo 0) ) / 3600 ))
  [ "$OAGE" -gt 26 ] && add "异地备份(R2)已 ${OAGE}h 未成功推送 (>26h) — 异地副本停更, 仅剩本地" "offsite"
fi

# 7. SSL 源站证书剩余天数 <14天 → 自动续期可能失败,提前预警(绕CF直查本机源站证书)
for SD in nezha.am api.nezha.am; do
  CEND=$(echo | timeout 10 openssl s_client -servername "$SD" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
  [ -z "$CEND" ] && continue
  CEE=$(date -d "$CEND" +%s 2>/dev/null) || continue
  CLEFT=$(( (CEE - $(date +%s)) / 86400 ))
  [ "$CLEFT" -lt 14 ] && add "$SD 源站SSL证书仅剩 ${CLEFT} 天 — 自动续期可能失败,需手动续" "ssl"
done

# 无异常 → 静默退出
# 8. 制裁名单(OFAC SDN)同步新鲜度: status!=ok 或 >30h 未成功 -> 筛查在用陈旧名单(对称于第6类备份告警, 补合规告警不对称缺口)
SANC=$(cd /www/wwwroot/api-deploy/current 2>/dev/null && php artisan tinker --execute='$j=json_decode(DB::table("business_settings")->where("key","nezha_sanction_last_sync")->value("value"),true); if(!$j){echo "NEVER";} elseif(($j["status"]??"")!=="ok"){echo "BADSTATUS:".($j["status"]??"unknown");} else{$a=(time()-strtotime($j["at"]??"2000-01-01"))/3600; if($a>30){echo "STALE:".round($a);}}' 2>/dev/null | tail -1 | tr -d "[:space:]")
case "$SANC" in
  NEVER) add "制裁名单(OFAC SDN)从未成功同步 — 筛查可能在用空/陈旧名单(违 L1 制裁拦截)" "sanction" ;;
  BADSTATUS:*) add "制裁名单最近一次同步失败(status=${SANC#BADSTATUS:}) — 筛查在用陈旧名单, 新增制裁地址可能漏拦" "sanction" ;;
  STALE:*) add "制裁名单已 ${SANC#STALE:}h 未成功同步(>30h) — OFAC SDN 更新停滞, 筛查在用陈旧名单" "sanction" ;;
esac

# 9. 前端 build 运行期漂移哨兵 (0711 退版事故: pm2 从旧 dump/resurrect 顶回旧 build, 部署时健康门管不到; 只告警不自愈)
exec 8>>/tmp/nezha_webdeploy.lock
if flock -n 8; then                     # 抢到锁=当前无部署进行(部署会持锁), 躲开 restart 窗口误报
  EXP=$(cat "$(readlink /www/wwwroot/web-deploy/current 2>/dev/null)/.next/BUILD_ID" 2>/dev/null)
  wbid(){ curl -s -m8 http://127.0.0.1:3000/home | grep -aoE '"buildId":"[^"]*"' | head -1 | sed -E 's/.*:"([^"]*)"/\1/'; }
  SRV=$(wbid)
  if [ -z "$EXP" ]; then
    add "web-deploy/current 或其 .next/BUILD_ID 读不到 — 前端 release 结构异常" "webdrift"
  elif [ -n "$SRV" ] && [ "$SRV" != "$EXP" ]; then
    sleep 3; SRV=$(wbid)                 # 3秒复查, 滤掉部署重启瞬时窗口
    [ -n "$SRV" ] && [ "$SRV" != "$EXP" ] && add "前端实际 build=$SRV != current release=$EXP — 疑 pm2 顶回旧 build(运行期漂移). 修: node nz.js run bash /www/wwwroot/nezha.am/nzbuild.sh (内含 pm2 save)" "webdrift"
  fi
  flock -u 8
fi
exec 8>&-

[ -z "$ALERTS" ] && exit 0

# 冷却: 按"告警类别集合"做指纹, 同一组问题1小时内只发一封; 出现新类别立即另发
FP=$(printf '%s' "$CATS" | md5sum | cut -c1-12)
SF="$STATE_DIR/last_$FP"
NOW=$(date +%s)
LAST=0; [ -f "$SF" ] && LAST=$(cat "$SF" 2>/dev/null || echo 0)
[ $((NOW-LAST)) -lt "$COOLDOWN" ] && exit 0
echo "$NOW" > "$SF"

BODY="哪吒服务器看门狗告警  ($HOSTN  $(date '+%F %T'))

检测到以下异常 (看门狗只负责告警, 不会自动处理):

${ALERTS}
------- 当前快照 -------
$(uptime | grep -o 'load average.*')
$(ps -eo pid,ppid,pcpu,etimes,comm --sort=-pcpu 2>/dev/null | head -6)

排查: node nz.js run \"bash /www/wwwroot/api.nezha.am/nzhealth.sh\"
确认是跑飞/孤儿进程且无用后, 人工 kill (看门狗不自动杀, 防误杀正经活)。"

send_mail "🔴 哪吒服务器告警 ($HOSTN): ${CATS%,}" "$BODY"
