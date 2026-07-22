#!/bin/bash
# nzcf-guard-cron.sh â€” cron åŒ…è£…: è·‘ P8 é˜²ç«å¢™å“¨å…µ, çœŸæ¼‚ç§»æ—¶é‚®ä»¶å‘Šè­¦(å¤ç”¨ nzwatch_mail.py é‚®ä»¶é“¾è·¯, ä¸æ”¹ nzwatch.sh)
# cron: */15 * * * * bash /www/wwwroot/api.nezha.am/nzcf-guard-cron.sh
LOG=/tmp/nzcf-guard.log
ENVF="/www/wwwroot/api.nezha.am/.env"
ALERT_TO="noctis201911@gmail.com"
SENT=/www/wwwroot/api.nezha.am/nzcf-guard.sh
MAILER=/www/wwwroot/api.nezha.am/nzwatch_mail.py

OUT=$(bash "$SENT" 2>&1)
echo "$(date '+%F %T') | $(printf '%s' "$OUT" | tr '\n' ' ')" >> "$LOG"

# çœŸæ¼‚ç§» = NZCF-WARN ä½†æŽ’é™¤çž¬æ—¶"æŠ“å–å¤±è´¥"(ç½‘ç»œæŠ–åŠ¨éžå¯æ“ä½œæ¼‚ç§»)
WARN=$(printf '%s\n' "$OUT" | grep 'NZCF-WARN' | grep -v 'æŠ“å–å¤±è´¥')
[ -z "$WARN" ] && exit 0

# å†·å´ 1h é˜²åˆ·å±(å¯¹é½ nzwatch)
SF=/tmp/nzcf-guard.lastmail; NOW=$(date +%s); LAST=0
[ -f "$SF" ] && LAST=$(cat "$SF" 2>/dev/null || echo 0)
[ $((NOW-LAST)) -lt 3600 ] && exit 0
echo "$NOW" > "$SF"

get(){ grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -E 's/^"//; s/"$//'; }
export NZ_HOST="$(get MAIL_HOST)" NZ_PORT="$(get MAIL_PORT)"
export NZ_USER="$(get MAIL_USERNAME)" NZ_PASS="$(get MAIL_PASSWORD)"
export NZ_FROM="$(get MAIL_FROM_ADDRESS)" NZ_TO="$ALERT_TO"
export NZ_SUBJ="ðŸ”´ å“ªå’ P8 é˜²ç«å¢™æ¼‚ç§»å‘Šè­¦ ($(hostname))"
export NZ_BODY="P8 CF-only é˜²ç«å¢™å“¨å…µæ£€æµ‹åˆ°æ¼‚ç§» ($(date '+%F %T')):

$WARN

å¯èƒ½å«ä¹‰(ä»»ä¸€): CF å®˜æ–¹æ®µå˜æ›´ / aaPanel ç™½åå•è¢«å¡«(=CF-only è¢«é™é»˜æ—è·¯) / 22 æ¡ CF è§„åˆ™è¢«æŠ¹ / é»˜è®¤ INPUT ç­–ç•¥éž DROPã€‚
æŽ’æŸ¥:  node nz.js run \"bash $SENT\"
å¤„ç½®:  è‹¥éžé¢„æœŸ, äººå·¥æ ¸å¯¹åŽä¿®å¤ ufw; è‹¥ CF ç¡®æ”¹æ®µ, åŒæ­¥æ›´æ–° ufw+nginx set_real_ip_from+fail2ban ignoreip å¹¶é‡è·‘ '$SENT --init'ã€‚
(æœ¬å“¨å…µåªå‘Šè­¦, ç»ä¸è‡ªåŠ¨æ”¹ ufwã€‚)"
python3 "$MAILER"
