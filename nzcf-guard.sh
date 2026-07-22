#!/usr/bin/env bash
# nzcf-guard.sh â€” P8 é˜²ç«å¢™åŠ å›ºã€Œæ¼‚ç§»å“¨å…µã€(è‰ç¨¿ Â· åªå‘Šè­¦ Â· ç»ä¸è‡ªåŠ¨æ”¹ ufw)
# ç›®çš„: æŠŠ debate æŒ‡å‡ºçš„ 3 ä¸ªé•¿æœŸé£Žé™©åˆå¹¶æˆä¸€é“å¹‚ç­‰æ–­è¨€å¢™,æŒ‚ nzwatch(*/5) æˆ–ç‹¬ç«‹ cronã€‚
#   æŸ¥1 CF å®˜æ–¹æ®µæ˜¯å¦æ¼‚ç§» (å˜äº†åªå‘Šè­¦,äººå·¥æ ¸å¯¹åŽå†æ‰‹åŠ¨åŒæ­¥ ufw/nginx/f2b)
#   æŸ¥2 é˜²ç«å¢™å®Œæ•´æ€§ (æŸ¥ INPUT é“¾! ä¸åªæŸ¥ ufw â€”â€” aaPanel ç™½åå•åœ¨ ufw ä¹‹å‰,ä¸€å¡«å³ç©¿é€ CF-only)
#   æŸ¥3 ä¸‰ä»½æ‰‹å·¥ CF å‰¯æœ¬ (ufw / nginx set_real_ip_from / fail2ban ignoreip) æ˜¯å¦ä»åˆ°ä½
# é“å¾‹: æœ¬è„šæœ¬çº¯åªè¯» + åªå‘Šè­¦; ä»»ä½•"è‡ªåŠ¨æ”¹ ufw"éƒ½è¢«æ˜Žç¡®å¦å†³ (åæŠ“å–ä¼šé”æ­»æˆ–å…¨å¼€)ã€‚
# ç”¨æ³•:  bash nzcf-guard.sh --init   # P8 é”å®šå®ŒæˆåŽè·‘ä¸€æ¬¡,å†™åŸºçº¿
#        bash nzcf-guard.sh          # å·¡æ£€; æœ‰é—®é¢˜æ‰“å° NZCF-WARN å¹¶ exit 1
# æ³¨: æŸ¥2b çš„ iptables ç­¾åå¾… E3 çœ‹åˆ°çœŸå®žè§„åˆ™æ¸²æŸ“åŽæœ€ç»ˆç¡®è®¤ (ä¸‹æ–¹å·²æŒ‰ multiport+ -s æºé™å®šå‡è®¾)ã€‚
set -uo pipefail

BASE=/root/nezha-p8-cf-baseline.txt
V4URL=https://www.cloudflare.com/ips-v4
V6URL=https://www.cloudflare.com/ips-v6
NGINX_RIF=/www/server/panel/vhost/nginx/0.nezha_ratelimit.conf
WARN=0
warn(){ echo "NZCF-WARN: $*"; WARN=1; }

# ---- æŠ“ CF å®˜æ–¹æ®µ (å¸¦å¤±è´¥å®ˆå«: æŠ“å°‘äº†=æ‹‰å–å¤±è´¥,ç»ä¸å½“ä½œ'æ®µè¢«åˆ ç©º') ----
fetch_cf(){
  local v4 v6 n4 n6
  v4=$(curl -fsS --max-time 15 "$V4URL" 2>/dev/null | grep -E '^[0-9]+\.' || true)
  v6=$(curl -fsS --max-time 15 "$V6URL" 2>/dev/null | grep -E '^[0-9a-fA-F]*:' || true)
  n4=$(printf '%s\n' "$v4" | grep -c . || true)
  n6=$(printf '%s\n' "$v6" | grep -c . || true)
  if [ "${n4:-0}" -lt 14 ] || [ "${n6:-0}" -lt 6 ]; then echo "__FETCH_FAIL__"; return; fi
  { printf '%s\n' "$v4"; printf '%s\n' "$v6"; } | sort -u
}

if [ "${1:-}" = "--init" ]; then
  cur=$(fetch_cf)
  [ "$cur" = "__FETCH_FAIL__" ] && { echo "æŠ“å– CF æ®µå¤±è´¥,æœªå†™åŸºçº¿"; exit 2; }
  printf '%s\n' "$cur" > "$BASE"
  echo "åŸºçº¿å·²å†™ $BASE ($(grep -c . "$BASE") æ¡)"; exit 0
fi

[ -f "$BASE" ] || { echo "NZCF-WARN: åŸºçº¿ $BASE ä¸å­˜åœ¨,å…ˆè·‘ --init"; exit 1; }

# ---- æŸ¥1: CF æ®µæ¼‚ç§» ----
cur=$(fetch_cf)
if [ "$cur" = "__FETCH_FAIL__" ]; then
  warn "CF æ®µæŠ“å–å¤±è´¥(ç½‘ç»œ/CF é¡µé¢å¼‚å¸¸)â€”â€”æœ¬è½®è·³è¿‡æ®µæ¯”å¯¹,è¿™ä¸æ˜¯'æ®µè¢«åˆ ç©º'"
else
  d=$(diff <(sort -u "$BASE") <(printf '%s\n' "$cur") || true)
  [ -n "$d" ] && warn "CF å®˜æ–¹æ®µ != åŸºçº¿ (CF å¯èƒ½æ”¹æ®µ; äººå·¥æ ¸å¯¹åŽæ‰‹åŠ¨åŒæ­¥ ufw/nginx/f2b å†é‡è·‘ --init):
$d"
fi

# ---- æŸ¥2: é˜²ç«å¢™å®Œæ•´æ€§ (æŸ¥ INPUT é“¾, ä¸åª ufw) ----
# 2a aaPanel ç™½åå•å¿…é¡»ä¸ºç©º (éžç©º=ufw ä¹‹å‰çš„å…¨ç«¯å£æ—è·¯,é™é»˜ç©¿é€ CF-only)
wl=$(ipset list aapanel.ipv4.whitelist -t 2>/dev/null | awk '/Number of entries/{print $NF}')
[ "${wl:-0}" != "0" ] && warn "aapanel.ipv4.whitelist æœ‰ $wl æ¡(ufw ä¹‹å‰å…¨ç«¯å£æ—è·¯!æŸ¥è°åœ¨é¢æ¿åŠ çš„)"
# 2b 80/443 ä¸å¾—æœ‰æ— æºé™å®š(Anywhere)çš„ ACCEPT (v4+v6Â·å®žæµ‹æ¸²æŸ“: CF è§„åˆ™=`-s CIDR -m multiport --dports 80,443 -j ACCEPT`)
bad=$( { iptables -S ufw-user-input; ip6tables -S ufw6-user-input; } 2>/dev/null | grep -- '-j ACCEPT' | grep -E -- '--dports? (80|443)' | grep -v -- ' -s ' || true )
[ -n "$bad" ] && warn "user-input å‡ºçŽ°æ— æºé™å®šçš„ 80/443 æ”¾è¡Œ(ç–‘ Anywhere è¢«é‡å¼€/é¢æ¿å¤ºæƒ):
$bad"
# 2c 22 æ¡ CF è§„åˆ™åº”åœ¨
n=$(ufw status 2>/dev/null | grep -c 'CF-origin-pull')
[ "${n:-0}" -lt 22 ] && warn "ufw CF-origin-pull è§„åˆ™åªå‰© ${n:-0} æ¡(åº” 22),ç–‘è¢«æŠ¹"
# 2d é»˜è®¤ç­–ç•¥ä»é¡» DROP (ç”¨ grep -c é¿å… pipefail+grep-q çš„ SIGPIPE è¯¯æŠ¥)
polok=$(iptables -S 2>/dev/null | grep -c '^-P INPUT DROP')
[ "${polok:-0}" -lt 1 ] && warn "iptables é»˜è®¤ INPUT ç­–ç•¥ä¸æ˜¯ DROP!"

# ---- æŸ¥3: nginx set_real_ip_from å‰¯æœ¬ä»åœ¨ ----
rif=$(grep -c 'set_real_ip_from' "$NGINX_RIF" 2>/dev/null || echo 0)
[ "${rif:-0}" -lt 22 ] && warn "nginx set_real_ip_from åªå‰© ${rif} æ¡(åº”>=22),CF æ®µå‰¯æœ¬æ¼‚ç§»"

[ "$WARN" = 0 ] && echo "NZCF-OK: CFæ®µ/é˜²ç«å¢™/å‰¯æœ¬ ä¸‰æŸ¥é€šè¿‡"
exit "$WARN"
