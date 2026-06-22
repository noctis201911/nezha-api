<!DOCTYPE html>
<html lang="zh">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#F7F8FA;font-family:'PingFang SC',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F8FA;padding:24px 0;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr><td style="background:#C4193E;padding:20px 24px;">
                    <span style="color:#ffffff;font-size:18px;font-weight:bold;">{{ translate('哪吒外卖 · 待退款提醒') }}</span>
                </td></tr>
                <tr><td style="padding:24px;">
                    <p style="font-size:15px;color:#333;margin:0 0 12px;">{{ translate('尊敬的商家') }}{{ $restaurant_name ? '（'.$restaurant_name.'）' : '' }}：</p>

                    <p style="font-size:15px;color:#C4193E;font-weight:bold;margin:0 0 12px;">
                        {{ translate('您有一笔待退款订单已逾期') }} {{ $overdue_label }} {{ translate('未处理，请尽快原路退款。') }}
                    </p>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                        <tr><td style="font-size:14px;color:#777;padding:2px 16px 2px 0;">{{ translate('订单号') }}</td>
                            <td style="font-size:14px;color:#333;font-weight:bold;">#{{ $order_id }}</td></tr>
                        <tr><td style="font-size:14px;color:#777;padding:2px 16px 2px 0;">{{ translate('应退金额') }}</td>
                            <td style="font-size:14px;color:#333;font-weight:bold;">{{ \App\CentralLogics\Helpers::format_currency($refund_amount) }}</td></tr>
                    </table>

                    <p style="font-size:14px;color:#555;margin:0 0 8px;">{{ translate('请尽快通过原支付方式(原路)将款项退还给顾客本人。哪吒为点对点直付, 平台不经手退款, 退款须由您在自己的收款账户原路退回。') }}</p>
                    <p style="font-size:14px;color:#555;margin:0 0 8px;">{{ translate('若您已经退款, 请登录商家后台「订单 → 待退款」, 找到该订单点「标记已退款」, 以免被系统判为逾期。') }}</p>
                    <p style="font-size:14px;color:#C4193E;margin:0 0 16px;">{{ translate('持续逾期不退款将计入您的风控档案, 并可能被平台暂停接单, 请务必及时处理。') }}</p>

                    <p style="font-size:13px;color:#999;margin:16px 0 0;">{{ translate('本提醒仅针对平台已取消/退款、等待您原路退还顾客的订单。如有疑问请联系平台客服。') }}</p>
                </td></tr>
                <tr><td style="background:#F7F8FA;padding:16px 24px;text-align:center;">
                    <span style="font-size:12px;color:#aaa;">© {{ date('Y') }} {{ \App\Models\BusinessSetting::where('key','business_name')->first()->value ?? '哪吒外卖' }}</span>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
