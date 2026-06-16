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

# 无异常 → 静默退出
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
