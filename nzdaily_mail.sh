#!/bin/bash
# nzdaily_mail.sh — 每日巡检投递层: 跑 nzdaily.sh → 去 ANSI → 落 /tmp/nzdaily.log + 邮件发运维信箱
# 由 cron 30 8 * * * 调用 (业主 2026-07-07 拍板=邮件投递)。手动巡检仍直接跑 nzdaily.sh(纯只读报告)。
# 邮件链路复用 nzwatch_mail.py (SMTP 凭证 .env 运行时注入, 不硬编码)。本层只读, 无副作用。
DIR=/www/wwwroot/api.nezha.am
ENVF="$DIR/.env"
MAILTO="noctis201911@gmail.com"

OUT="$(bash "$DIR/nzdaily.sh" 2>&1)"
CLEAN="$(printf '%s\n' "$OUT" | sed -r 's/\x1B\[[0-9;]*[A-Za-z]//g')"

# 落盘历史 (带分隔头)
{ echo "########## $(date '+%F %T %Z') ##########"; printf '%s\n' "$CLEAN"; } >> /tmp/nzdaily.log 2>/dev/null

# 主题状态 (从 SUMMARY 抽)
if printf '%s' "$CLEAN" | grep -q 'all clear'; then ST='OK all-clear'
elif printf '%s' "$CLEAN" | grep -q '有需处理项'; then ST='RED action-needed'
else ST='YEL watch'; fi

# SMTP 凭证从 .env 注入 (与 nzwatch.sh send_mail 同法)
get(){ grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -E 's/^"//; s/"$//'; }
export NZ_HOST="$(get MAIL_HOST)"     NZ_PORT="$(get MAIL_PORT)"
export NZ_USER="$(get MAIL_USERNAME)" NZ_PASS="$(get MAIL_PASSWORD)"
export NZ_FROM="$(get MAIL_FROM_ADDRESS)" NZ_TO="$MAILTO"
export NZ_SUBJ="哪吒每日巡检 [$ST] ($(date '+%F'))"
export NZ_BODY="$CLEAN"
python3 "$DIR/nzwatch_mail.py"
