{{-- 哪吒[本地生活商户轻管理面] 设密/重置密码邮件 · 自有品牌中文模板(不走 Laravel 默认 markdown, 避开 stackfood 的 APP_NAME 泄露) --}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>哪吒商户管理面 · 设置登录密码</title>
</head>
<body style="margin:0; padding:0; background-color:#eef0f3; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef0f3;">
  <tr>
    <td align="center" style="padding:28px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; width:100%; font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

        {{-- 品牌头 --}}
        <tr>
          <td style="background-color:#102A4C; border-radius:14px 14px 0 0; padding:26px 28px 20px; text-align:center;">
            <img src="https://api.nezha.am/storage/business/nezha-logo-sq.png" width="74" height="74" alt="哪吒外卖" style="display:inline-block; width:74px; height:74px; border-radius:16px; border:0; outline:none; -ms-interpolation-mode:bicubic;">
            <div style="margin-top:12px; color:#cdd8e6; font-size:14px; letter-spacing:3px;">商户管理</div>
          </td>
        </tr>

        {{-- 卡片正文 --}}
        <tr>
          <td style="background-color:#ffffff; border-radius:0 0 14px 14px; padding:32px 28px 28px;">
            <p style="margin:0 0 16px; color:#1f2329; font-size:17px; font-weight:600;">您好，</p>
            <p style="margin:0 0 24px; color:#4a5159; font-size:15px; line-height:1.7;">
              这是哪吒平台「本地生活商户管理面」的登录密码设置链接。请点击下方按钮，为您的商户账号设置或重置登录密码。
            </p>

            {{-- 主按钮 --}}
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 26px;">
              <tr>
                <td align="center" style="border-radius:10px; background-color:#102A4C;">
                  <a href="{{ $url }}" target="_blank"
                     style="display:inline-block; padding:14px 40px; color:#ffffff; font-size:16px; font-weight:600; text-decoration:none; border-radius:10px;">
                    设置登录密码
                  </a>
                </td>
              </tr>
            </table>

            {{-- fallback 链接(中文) --}}
            <p style="margin:0 0 8px; color:#8a9099; font-size:13px; line-height:1.6;">
              若上方按钮无法点击，请将下面的链接复制到浏览器打开：
            </p>
            <p style="margin:0 0 24px; padding:12px 14px; background-color:#f4f6f8; border-radius:8px; word-break:break-all; color:#3b6ea5; font-size:13px; line-height:1.6;">
              {{ $url }}
            </p>

            <p style="margin:0; padding-top:20px; border-top:1px solid #eef0f3; color:#8a9099; font-size:13px; line-height:1.7;">
              链接在 {{ $hours }} 小时内有效。若非您本人操作，请忽略本邮件，您的账号不会有任何变化。
            </p>
          </td>
        </tr>

        {{-- 页脚 --}}
        <tr>
          <td style="padding:18px 28px 0; text-align:center;">
            <p style="margin:0; color:#a4abb3; font-size:12px; line-height:1.6;">
              哪吒平台 · 本地生活商户自助维护
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
