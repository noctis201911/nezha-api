#!/bin/bash
# 哪吒每日巡检摘要 (nzdaily) — 只读·安全·无副作用·fail-open
# 用途: T0 晨检自动化——昨日请求量/5xx/499、queue:failed、备份新鲜度、laravel 新增 ERROR、pm2 重启增量、磁盘
# 配套: nzhealth.sh(即时体检) + nzwatch.sh(5min看门狗告警)。本脚本= 每日 08:30 cron 落 /tmp/nzdaily.log
# 触发词「日常巡检/运维QA」时任何窗口可跑: node nz.js run "bash /www/wwwroot/api.nezha.am/nzdaily.sh"
# 由 HANDOFF_ops_routine_qa §E 规格落库 (2026-07-07)。任何段失败都继续下一段, 绝不中断/写业务表。
# ⚠️ TZ: 系统=UTC → nginx/backup 按 UTC 计"昨日"; laravel 运行时=Asia/Yerevan → ERROR 按 Yerevan 计"昨日"。
set -o pipefail
DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
RED=$'\e[31m'; YEL=$'\e[33m'; GRN=$'\e[32m'; OFF=$'\e[0m'

ISSUES_R=""   # 🔴 项
ISSUES_Y=""   # 🟡 项
red(){  ISSUES_R="${ISSUES_R}    🔴 $1"$'\n'; }
yel(){  ISSUES_Y="${ISSUES_Y}    🟡 $1"$'\n'; }

YDAY_U=$(date -u -d yesterday '+%d/%b/%Y')                 # nginx 日志时区 = UTC
YDAY_L=$(TZ=Asia/Yerevan date -d yesterday '+%Y-%m-%d')    # laravel 时区 = Yerevan
echo "==================== 哪吒每日巡检 nzdaily  $(date '+%F %T %Z') ===================="
echo "  昨日窗口: nginx/备份=${YDAY_U}(UTC)  laravel=${YDAY_L}(Yerevan)"

# ── ① 昨日 nginx 请求量 + 5xx/499 (两站) ──────────────────────────────
echo "[1] 昨日流量 + 错误 (nginx, UTC 日)"
nginx_stat(){  # $1=站名 $2=日志路径
  local name="$1" log="$2" tot=0 e5=0 e499=0
  if [ -r "$log" ]; then
    tot=$(grep -acE "\[${YDAY_U}:" "$log" 2>/dev/null)
    e5=$(grep -acE "\[${YDAY_U}:.*\" 5[0-9][0-9] " "$log" 2>/dev/null)
    e499=$(grep -acE "\[${YDAY_U}:.*\" 499 " "$log" 2>/dev/null)
  else
    echo "    ${YEL}${name}: 日志不可读 ${log}${OFF}"; return
  fi
  printf "    %-16s 请求 %-8s 5xx %-5s 499 %-5s\n" "$name" "$tot" "$e5" "$e499"
  [ "${e5:-0}" -gt 100 ] && red "${name} 昨日 5xx=${e5} (>100, 疑事故)"
  { [ "${e5:-0}" -gt 0 ] && [ "${e5:-0}" -le 100 ]; } && yel "${name} 昨日 5xx=${e5} (>0)"
}
nginx_stat "nezha.am(前端)"    "/www/wwwlogs/nezha.am.log"
nginx_stat "api.nezha.am(后端)" "/www/wwwlogs/api.nezha.am.log"

# ── ② 失败队列 ────────────────────────────────────────────────────────
echo "[2] 失败队列 (queue:failed)"
QF=$(cd /www/wwwroot/api-deploy/current 2>/dev/null && php artisan queue:failed 2>&1 | head -6)
echo "$QF" | sed 's/^/    /'
if echo "$QF" | grep -qiE 'No failed jobs'; then
  :
elif echo "$QF" | grep -qE '[0-9a-f]{8}-[0-9a-f]{4}-'; then
  red "存在失败队列任务 — 通知/异步逻辑可能未送达"
else
  yel "queue:failed 输出无法判定 (artisan 异常?) — 见上"
fi

# ── ③ 备份新鲜度 (本地 db + 异地 R2) ─────────────────────────────────
echo "[3] 备份 (backup.log 末2行 + 新鲜度)"
tail -2 /root/nezha-backup/backup.log 2>/dev/null | sed 's/^/    /' || echo "    ${YEL}backup.log 不可读${OFF}"
BK=$(ls -1t /www/backup/database/nezha-enc/sql_*.sql.gz.enc 2>/dev/null | head -1)
if [ -n "$BK" ]; then
  BAGE=$(( ( $(date +%s) - $(stat -c %Y "$BK") ) / 3600 ))
  echo "    最新数据库备份: ${BAGE}h 前  ($(basename "$BK"))"
  [ "$BAGE" -gt 30 ] && red "最新数据库备份 ${BAGE}h 未更新 (>30h) — 每日备份可能静默失败"
else
  red "未找到任何数据库备份 (.enc) — 每日备份未产出?"
fi
FBK=$(ls -1t /www/backup/database/nezha-enc/nezha-files-*.tar.gz.enc 2>/dev/null | head -1)
if [ -n "$FBK" ]; then
  FAGE=$(( ( $(date +%s) - $(stat -c %Y "$FBK") ) / 3600 ))
  echo "    最新文件备份: ${FAGE}h 前  ($(basename "$FBK"))"
  [ "$FAGE" -gt 30 ] && red "最新文件备份 ${FAGE}h 未更新 (>30h) — 图片/支付凭证/.env 未进备份"
else
  red "未找到任何文件备份 (nezha-files-*.tar.gz.enc) — storage/app 与 .env 未在备份?"
fi
OSOK=/root/nezha-backup/offsite_last_ok
if [ -f "$OSOK" ]; then
  OAGE=$(( ( $(date +%s) - $(cat "$OSOK" 2>/dev/null || echo 0) ) / 3600 ))
  echo "    异地(R2)最近成功: ${OAGE}h 前"
  [ "$OAGE" -gt 30 ] && red "异地备份(R2) ${OAGE}h 未成功 (>30h) — 仅剩本地副本"
else
  yel "异地备份标记 offsite_last_ok 不存在 — R2 可能从未成功"
fi

# ── ④ laravel 昨日新增 ERROR ─────────────────────────────────────────
echo "[4] laravel 昨日 ERROR (Yerevan 日)"
LLOG=/www/wwwroot/api-deploy/current/storage/logs/laravel.log
if [ -r "$LLOG" ]; then
  LE=$(grep -acE "^\[${YDAY_L} [0-9:]+\] [a-zA-Z]+\.ERROR" "$LLOG" 2>/dev/null)
  echo "    ${YDAY_L} 新增 *.ERROR 条数: ${LE}"
  if [ "${LE:-0}" -gt 200 ]; then
    red "laravel 昨日 ERROR=${LE} (>200, 错误风暴)"
  elif [ "${LE:-0}" -gt 0 ]; then
    yel "laravel 昨日新增 ERROR=${LE} — 需分类确认，不能汇总为 all clear"
  fi
  [ "${LE:-0}" -gt 0 ] && echo "    近1条样例:" && grep -aE "^\[${YDAY_L} [0-9:]+\] [a-zA-Z]+\.ERROR" "$LLOG" 2>/dev/null | tail -1 | cut -c1-140 | sed 's/^/      /'
else
  yel "laravel.log 不可读 ${LLOG}"
fi

# ── ⑤ pm2 重启增量 (对比上次落盘) ────────────────────────────────────
echo "[5] pm2 重启增量 (自上次 nzdaily)"
STATE=${NZDAILY_STATE:-/tmp/nzdaily_state.json}
if [ -x /usr/bin/pm2 ] && command -v python3 >/dev/null 2>&1; then
  PM2_REPORT=$(sudo -u www -H env PM2_HOME=/home/www/.pm2 /usr/bin/pm2 jlist 2>/dev/null \
    | python3 "$DIR/nzruntime.py" pm2-daily --state "$STATE" 2>&1)
  PM2_RC=$?
  printf '%s\n' "$PM2_REPORT" | sed 's/^/    /'
  [ "$PM2_RC" -eq 2 ] && red "www PM2 生产拓扑缺失、离线或不可读 — 监控不能继续报绿"
  [ "$PM2_RC" -eq 1 ] && yel "www PM2 出现非计划重启信号 — 见逐进程增量"
else
  red "www PM2 或 nzruntime.py 不可执行 — 生产进程采集失明"
fi

# ── ⑥ 磁盘 ───────────────────────────────────────────────────────────
echo "[6] 磁盘根分区"
df -h / | tail -1 | awk '{printf "    已用 %s  可用 %s  挂载 %s\n",$5,$4,$6}'
DU=$(df -P / | tail -1 | awk '{print $5+0}')
[ "${DU:-0}" -gt 85 ] && red "根分区磁盘已用 ${DU}% (>85%)"

# ── ⑦ git 墙在不在 (两仓 .git/hooks 与入库正本 ops/githooks 对账) ──────
# hook 不入库、不随 clone 走: 新 clone / .git 重建 / 有人手改, 墙会静默消失而提交侧一路绿灯。
echo "[7] git 墙 (ops/githooks 正本 vs 已装 hooks)"
for R in ${NZDAILY_GITHOOK_REPOS:-/www/wwwroot/nezha.am /www/wwwroot/api.nezha.am}; do
  N=$(basename "$R")
  if [ -r "$R/ops/githooks/install.sh" ]; then
    # 2026-07-22 GATE 审计第5条: 本段由 root cron 每天执行, 而 $R 是共享工作树(可变),
    #   等于给能写工作树的任何主体一条每日 root 执行通道。nzdaily.sh 自身跑在不可变 release 里,
    #   故在此加守卫: 工作树副本相对 HEAD 一旦被改动就拒绝执行, 只报警不运行。
    if ! git -C "$R" diff --quiet HEAD -- ops/githooks/install.sh 2>/dev/null; then
      red "${N} ops/githooks/install.sh 相对 HEAD 已被改动 — 拒绝以 root 执行工作树副本(疑被篡改), 请人工比对后再跑"
      continue
    fi
    GOUT=$(bash "$R/ops/githooks/install.sh" --check 2>&1); GRC=$?
    printf '%s\n' "$GOUT" | grep -E '\[(一致|漂移)\]|未安装' | sed "s|^ *|    ${N}: |"
    [ "$GRC" -ne 0 ] && red "${N} git 墙漂移/缺失 — 防覆盖墙/php-l墙/L1红线墙可能已失守, 重装: bash ${R}/ops/githooks/install.sh"
  else
    yel "${N} 读不到 ops/githooks/install.sh — git 墙无从对账"
  fi
done

# ── ⑧ 汇总 ───────────────────────────────────────────────────────────
echo "==================== SUMMARY ===================="
if [ -n "$ISSUES_R" ]; then
  echo "${RED}🔴 有需处理项:${OFF}"; printf '%s' "$ISSUES_R"
  [ -n "$ISSUES_Y" ] && { echo "${YEL}🟡 关注项:${OFF}"; printf '%s' "$ISSUES_Y"; }
  echo "  → 走 QA_MASTER §五 T3 症状路由, 先取证再动手。"
elif [ -n "$ISSUES_Y" ]; then
  echo "${YEL}🟡 有关注项 (非阻断):${OFF}"; printf '%s' "$ISSUES_Y"
else
  echo "${GRN}🟢 all clear — 昨日无异常${OFF}"
fi
# 投递渠道待业主拍板 (HANDOFF ④-1): 邮件复用 nzwatch_mail.py / Telegram / 仅落盘 /tmp/nzdaily.log
echo "==================== 巡检完 $(date '+%T') ===================="
