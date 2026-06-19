<!DOCTYPE html>
<html lang="zh">
<head><meta charset="utf-8"></head>
<body style="font-family:PingFang SC,Microsoft YaHei,Arial,sans-serif;color:#222;line-height:1.7;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <h2 style="color:#C4193E;margin-bottom:4px;">哪吒外卖</h2>
        <p>尊敬的 <strong>{{ $restaurant_name }}</strong>：</p>

        @if ($type === 'remind')
            <p>您有一笔订单 <strong>#{{ $order_id }}</strong> 已等待约 <strong>{{ $waited_minutes }}</strong> 分钟仍未处理。</p>
            <p>请尽快登录商家后台<strong>确认收款并接单</strong>。若继续超时，系统将自动取消该订单@if($paid)，并通知您按原路退还顾客已付款项@endif。</p>
        @elseif ($type === 'cancel_refund')
            <p>订单 <strong>#{{ $order_id }}</strong> 因超过 <strong>{{ $waited_minutes }}</strong> 分钟未处理，已被系统<strong>自动取消</strong>。</p>
            @if ($paid)
                <p style="background:#FFF7E6;padding:12px;border-radius:8px;">
                    顾客此前已直接付款给您。请尽快<strong>按原路退还</strong>顾客（微信/支付宝原路退回，或 USDT 退回原地址），
                    退款后在「商家后台 → 订单 → 待退款」中标记<strong>已退款</strong>。平台不经手此款。
                </p>
            @else
                <p>该订单顾客未完成付款，无需退款处理。</p>
            @endif
        @elseif ($type === 'prep_overtime')
            <p>订单 <strong>#{{ $order_id }}</strong> 备餐已超过预计出餐时间约 <strong>{{ $waited_minutes }}</strong> 分钟。</p>
            <p>请尽快出餐，或主动联系顾客说明情况。系统已将此单标记为备餐异常并同步客服跟进。</p>
        @endif

        <p style="color:#888;font-size:13px;margin-top:24px;">
            此邮件由系统自动发送，请勿直接回复。如有疑问请联系平台客服：support@nezha.am
        </p>
    </div>
</body>
</html>
