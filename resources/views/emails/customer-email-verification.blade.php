<!doctype html>
<html lang="{{ $htmlLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:#f6f3ee;color:#1f2329;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f3ee;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border:1px solid #ece7df;border-radius:18px;">
                    <tr>
                        <td style="padding:32px;">
                            <div style="font-size:14px;font-weight:700;color:#c4193e;">{{ $brand }}</div>
                            <h1 style="margin:18px 0 10px;font-size:24px;line-height:1.35;">{{ $title }}</h1>
                            <p style="margin:0;color:#5a6069;font-size:15px;line-height:1.7;">{{ $body }}</p>
                            <div style="margin:28px 0;padding:18px 20px;border-radius:14px;background:#faf7f2;text-align:center;font-size:34px;font-weight:750;letter-spacing:8px;color:#1f2329;">
                                {{ $code }}
                            </div>
                            <p style="margin:0 0 8px;color:#5a6069;font-size:13px;line-height:1.6;">{{ $expiry }}</p>
                            <p style="margin:0;color:#9aa0a8;font-size:12px;line-height:1.6;">{{ $safety }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
