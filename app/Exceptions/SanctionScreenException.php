<?php

namespace App\Exceptions;

/**
 * 哪吒制裁筛查② — 付款来源地址命中 OFAC SDN/黑名单时抛出 (L1-6).
 *
 * 由 OrderLogic::confirm_offline_payment() 在写库拒收 + 留痕之后抛出, 用于让确认收款的
 * 调用方(商家端 / admin 端)统一中止"确认成功"并向操作者展示拒收原因。
 * 抛出前订单已被安全置为"拒收"(offline_payments=denied, 订单未确认), 即便上层未捕获也失败在安全侧。
 */
class SanctionScreenException extends \Exception
{
}
