<!DOCTYPE html>
<html lang="zh">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#F7F8FA;font-family:'PingFang SC',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F8FA;padding:24px 0;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr><td style="background:#C4193E;padding:20px 24px;">
                    <span style="color:#ffffff;font-size:18px;font-weight:bold;">{{ translate('哪吒外卖 · 商家通知') }}</span>
                </td></tr>
                <tr><td style="padding:24px;">
                    <p style="font-size:15px;color:#333;margin:0 0 12px;">{{ translate('尊敬的商家') }}{{ $restaurant_name ? '（'.$restaurant_name.'）' : '' }}：</p>

                    @if($is_negative)
                        <p style="font-size:15px;color:#C4193E;font-weight:bold;margin:0 0 12px;">
                            {{ translate('您的预存佣金已为负数, 即您已欠平台佣金：') }}
                            {{ \App\CentralLogics\Helpers::format_currency(abs($balance)) }}
                        </p>
                        <p style="font-size:14px;color:#555;margin:0 0 16px;">{{ translate('为不影响接单, 请尽快充值补足。余额为负或不足时, 您的店铺将暂停接收新订单。') }}</p>
                    @else
                        <p style="font-size:15px;color:#333;margin:0 0 12px;">
                            {{ translate('您的预存佣金余额已低于您设置的提醒值。') }}
                        </p>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                            <tr><td style="font-size:14px;color:#777;padding:2px 16px 2px 0;">{{ translate('当前余额') }}</td>
                                <td style="font-size:14px;color:#333;font-weight:bold;">{{ \App\CentralLogics\Helpers::format_currency($balance) }}</td></tr>
                            <tr><td style="font-size:14px;color:#777;padding:2px 16px 2px 0;">{{ translate('提醒阈值') }}</td>
                                <td style="font-size:14px;color:#333;">{{ \App\CentralLogics\Helpers::format_currency($threshold) }}</td></tr>
                        </table>
                        <p style="font-size:14px;color:#555;margin:0 0 16px;">{{ translate('余额不足时店铺会暂停接单, 建议及时充值。') }}</p>
                    @endif

                    <p style="font-size:13px;color:#999;margin:16px 0 0;">{{ translate('预存佣金是您预付给平台的佣金(B2B), 与顾客货款无关。如需充值请联系平台客服。') }}</p>
                    <p style="font-size:13px;color:#999;margin:8px 0 0;">{{ translate('您可在商家后台「预存佣金」页面随时关闭或调整本提醒。') }}</p>
                </td></tr>
                <tr><td style="background:#F7F8FA;padding:16px 24px;text-align:center;">
                    <span style="font-size:12px;color:#aaa;">© {{ date('Y') }} {{ \App\Models\BusinessSetting::where('key','business_name')->first()->value ?? '哪吒外卖' }}</span>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
