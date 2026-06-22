#!/bin/bash
# 哪吒服务器健康速查 (nzhealth) — 只读·安全·无副作用
# 用途: 揪出"产品/前端QA看不见"的服务器层问题(跑飞进程/过载/内存/磁盘/php-fpm饱和/源站延迟)
# 任何窗口可跑:  node nz.js run "bash /www/wwwroot/api.nezha.am/nzhealth.sh"
# 由 2026-06-16 "登录扫脸后慢"事件催生(真因=孤儿until空循环烧CPU 7天,全部前端走查都看不到)
set -o pipefail
RED=$'\e[31m'; YEL=$'\e[33m'; GRN=$'\e[32m'; OFF=$'\e[0m'
echo "==================== 哪吒服务器体检  $(date '+%F %T') ===================="

CORES=$(nproc)
LOAD1=$(cut -d' ' -f1 /proc/loadavg)
echo "[1] 负载 (核心数=$CORES)"
uptime | grep -o 'load average.*' | sed 's/^/    /'
awk -v l="$LOAD1" -v c="$CORES" 'BEGIN{
  if (l+0 > c+0)      printf "    \033[31m🔴 1分钟负载 %s > 核心数 %s — 过载,几乎肯定有进程在吃CPU(看[3])\033[0m\n", l, c;
  else if (l+0 > c*0.7) printf "    \033[33m🟡 负载偏高 %s (核心 %s)\033[0m\n", l, c;
  else                printf "    \033[32m🟢 负载正常 %s (核心 %s)\033[0m\n", l, c;
}'

echo "[2] CPU占用 TOP5"
ps -eo pid,ppid,pcpu,etime,comm --sort=-pcpu | head -6 | sed 's/^/    /'

echo "[3] 🔴 跑飞嫌疑进程 (孤儿PPID=1且CPU>20%  或  空转循环)"
HIT=0
while read -r pid ppid pcpu rest; do
  [ "$pid" = "PID" ] && continue
  if [ "$ppid" = "1" ] && awk -v p="$pcpu" 'BEGIN{exit !(p+0>20)}'; then
    echo "    ${RED}孤儿高CPU: PID=$pid CPU=$pcpu% $rest${OFF}"; HIT=1
  fi
done < <(ps -eo pid,ppid,pcpu,args --sort=-pcpu)
LOOPS=$(ps -eo pid,pcpu,etime,args | grep -iE 'until \[|while true|do :; *done|do : *; *done' | grep -vE 'grep|nzhealth')
if [ -n "$LOOPS" ]; then echo "    ${RED}空转循环嫌疑:${OFF}"; echo "$LOOPS" | sed 's/^/      /'; HIT=1; fi
[ "$HIT" = "0" ] && echo "    ${GRN}🟢 未发现跑飞/空转进程${OFF}"

echo "[4] 内存"
free -h | head -2 | sed 's/^/    /'
SWAP=$(free -m | awk '/Swap:/{print $3}')
[ "${SWAP:-0}" -gt 200 ] && echo "    ${YEL}🟡 swap已用 ${SWAP}MB — 内存可能吃紧${OFF}"

echo "[5] 磁盘根分区"
df -h / | tail -1 | awk '{u=$5+0; if(u>85) printf "    \033[31m🔴 已用 %s — 接近满,会致写失败/500\033[0m\n",$5; else printf "    \033[32m🟢 已用 %s\033[0m\n",$5}'

echo "[6] php-fpm 工作进程 vs 上限"
FPM=$(ps -eo comm | grep -c '[p]hp-fpm')
MAXC=$(grep -rh 'pm.max_children' /www/server/php/*/etc/php-fpm.d/*.conf /www/server/php/*/etc/php-fpm*.conf 2>/dev/null | grep -v ';' | grep -oE '[0-9]+' | head -1)
echo "    当前 php-fpm 进程: ${FPM}   pm.max_children: ${MAXC:-未读到}"
if [ -n "$MAXC" ] && [ "$FPM" -ge "$((MAXC - 2))" ]; then echo "    ${RED}🔴 php-fpm 逼近上限 — 高峰会排队致请求变慢/超时${OFF}"; fi

echo "[7] 源站延迟探针 (直连绕CF,看后端本身快不快)"
for u in api/v1/config; do
  curl -s -k -o /dev/null -w "    /$u : %{time_total}s  HTTP:%{http_code}\n" --resolve api.nezha.am:443:127.0.0.1 "https://api.nezha.am/$u"
done

echo "[8] 运行最久的用户态非常驻进程 TOP3 (异常长跑=可疑;已排内核线程/常驻服务)"
ps -eo etimes,pid,ppid,comm --sort=-etimes | \
  awk 'NR>1 && $3>2 && $4!~/systemd|sshd|nginx|mysqld|php-fpm|pm2|redis|hysteria|next-server|kworker|rcu_|ksoftirq|migration|kthread|kcompact|khugepaged|kswapd/ {d=int($1/86400); h=int(($1%86400)/3600); printf "    跑了%d天%d时  PID=%s  %s\n", d,h,$2,$4; n++} n>=3{exit}'

echo "[9] 货到付款(COD)必须关闭 — B方案禁现金代收(详见 nzcheck-cod.sh)"
bash /www/wwwroot/api.nezha.am/nzcheck-cod.sh 2>/dev/null | tail -n +2
echo "==================== 体检完 — 有🔴先处理,孤儿/空转进程可 kill(需用户授权) ===================="
