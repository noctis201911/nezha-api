#!/bin/bash
# nzcheck-cod.sh — 上线前/部署后硬自检: 货到付款(COD)必须全平台关闭。
# 为什么: B方案平台不碰钱、无现金代收路径, cash_on_delivery 必须恒为 0;
#         若被(误)开启, failed/pending 单的 COD 切换会把订单改成 cash_on_delivery 产出脏单。
# 行为: 两项都满足 => 绿字 exit 0; 任一不满足 => 红字 exit 1 (可用于阻断/CI/告警)。只读无副作用。
#   手动:   node nz.js run "bash /www/wwwroot/api.nezha.am/nzcheck-cod.sh"
#   被调用: nzhealth.sh / nzdeploy-api.sh / nzdeploy-web.sh
set -o pipefail
RED=$'\e[31m'; GRN=$'\e[32m'; OFF=$'\e[0m'
BASE="/www/wwwroot/api-deploy/current"
FAIL=0
echo "[COD] 货到付款必须关闭自检 (cash_on_delivery == 0)"

# (1) DB business_settings.cash_on_delivery.status 必须 = 0 (权威存储值)
DBSTATUS=$(php -r '
$b=$argv[1];
require $b."/vendor/autoload.php";
$app=require $b."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$v=Illuminate\Support\Facades\DB::table("business_settings")->where("key","cash_on_delivery")->value("value");
$d=json_decode($v,true);
echo (is_array($d) && array_key_exists("status",$d)) ? $d["status"] : "READ_FAIL";
' "$BASE" 2>/dev/null)
if [ "$DBSTATUS" = "0" ]; then
  echo "    ${GRN}🟢 DB business_settings.cash_on_delivery.status = 0${OFF}"
else
  echo "    ${RED}🔴 DB cash_on_delivery.status = ${DBSTATUS:-READ_FAIL} (必须为 0)${OFF}"
  FAIL=1
fi

# (2) 线上 /api/v1/config cash_on_delivery 必须 = false (含缓存的实效值)
CFG=$(curl -s -k --resolve api.nezha.am:443:127.0.0.1 -H "X-software-id: 33571750" -H "X-server: react" "https://api.nezha.am/api/v1/config" 2>/dev/null)
CODVAL=$(printf '%s' "$CFG" | python3 -c 'import sys,json
try:
    print(str(json.load(sys.stdin).get("cash_on_delivery")).lower())
except Exception:
    print("read_fail")' 2>/dev/null)
if [ "$CODVAL" = "false" ]; then
  echo "    ${GRN}🟢 /api/v1/config cash_on_delivery = false${OFF}"
else
  echo "    ${RED}🔴 /api/v1/config cash_on_delivery = ${CODVAL:-read_fail} (必须 false)${OFF}"
  FAIL=1
fi

if [ "$FAIL" = "0" ]; then
  echo "    ${GRN}✅ COD 已全平台关闭${OFF}"
else
  echo "    ${RED}❌ COD 自检未通过 — B方案禁止货到付款!${OFF}"
  echo "    ${RED}   修复: 后台 /admin/business-settings/payment-setup 关闭 Cash on Delivery(保留 Offline 支付), 保存即生效(observer自动清缓存)${OFF}"
fi
exit $FAIL