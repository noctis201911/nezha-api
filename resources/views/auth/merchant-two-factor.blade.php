<!DOCTYPE html>
<html dir="{{ $site_direction ?? 'ltr' }}" lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>商家两步验证</title>
    <style>
        :root{--bg:#fffaf5;--text:#1c1917;--muted:#78716c;--accent:#c4193e;--border:#e7e5e4}
        *{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#fffaf5,#fef3e7);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif}
        .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:100%;max-width:460px;background:#fff;border:1px solid var(--border);border-radius:22px;padding:32px;box-shadow:0 22px 55px rgba(196,25,62,.1)}
        h1{font-size:24px;margin:0 0 8px}.sub{color:var(--muted);line-height:1.7;margin:0 0 22px}.qr{text-align:center;margin:18px 0}.qr img{max-width:220px;width:100%;height:auto}.secret{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#fafaf9;border:1px solid var(--border);border-radius:12px;padding:14px;overflow-wrap:anywhere}
        label{display:block;font-size:14px;font-weight:600;margin:18px 0 8px}input{width:100%;border:1px solid var(--border);border-radius:12px;padding:14px;font-size:18px;letter-spacing:2px;text-align:center}button,.button{display:block;width:100%;border:0;border-radius:12px;padding:14px;margin-top:16px;background:var(--accent);color:#fff;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}.secondary{background:#fff;color:var(--muted);border:1px solid var(--border)}.error,.notice{border-radius:10px;padding:12px;margin-bottom:16px;line-height:1.7}.error{background:#fef2f2;color:#991b1b}.notice{background:#fff7ed;color:#9a3412}.hint{font-size:13px;color:var(--muted);line-height:1.7;margin-top:14px}
        .status{display:flex;align-items:center;gap:8px;font-size:13px;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 12px;margin-bottom:18px}
        .items{margin-top:22px;border-top:1px solid var(--border)}
        details{border-bottom:1px solid var(--border)}
        summary{list-style:none;cursor:pointer;padding:16px 0;display:flex;align-items:center;justify-content:space-between;gap:12px}
        summary::-webkit-details-marker{display:none}
        summary .t{font-size:15px;font-weight:600}summary .d{font-size:12.5px;color:var(--muted);margin-top:3px;line-height:1.6}
        summary .chev{color:var(--muted);font-size:20px;flex:none;transition:transform .15s}
        details[open] summary .chev{transform:rotate(90deg)}
        details .body{padding:0 0 20px}
        @media(max-width:520px){.wrap{padding:14px}.card{padding:24px 20px;border-radius:18px}}
    </style>
</head>
<body>
<main class="wrap">
    <section class="card">
        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif
        @if (session('merchant_2fa.disabled_notice'))
            <div class="notice">两步验证已关闭，原有的登录状态也已全部失效。您随时可以再次开启。</div>
        @endif

        @if ($mode === 'challenge')
            <h1>请验证是您本人</h1>
            <p class="sub">请输入验证器 App 中当前显示的六位数字。如果手机丢了或验证器已删除，请联系平台帮您重置。</p>
            <form method="post" action="{{ route('merchant.2fa.verify') }}" data-single-submit>
                @csrf
                <label for="merchant-2fa-code">验证器六位数字</label>
                <input id="merchant-2fa-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
                <button type="submit">验证并登录</button>
            </form>
        @elseif ($mode === 'setup')
            <h1>开启两步验证（可选）</h1>
            <p class="sub">开不开由您自己决定。用验证器 App 扫描下面的二维码完成绑定；只有在您输入正确的六位数字之后，平台才会保存绑定。请注意：平台不提供可以打印保存的备用码，所以之后若丢了手机，只能联系平台重置。</p>
            <div class="qr"><img src="data:image/svg+xml;base64,{{ $qr_svg }}" alt="验证器绑定二维码"></div>
            <p class="hint">无法扫码？也可以在验证器里手动输入下面这串密钥：</p>
            <div class="secret">{{ $secret }}</div>
            <form method="post" action="{{ route('merchant.2fa.enable') }}" data-single-submit>
                @csrf
                <label for="merchant-2fa-code">验证器当前的六位数字</label>
                <input id="merchant-2fa-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
                <button type="submit">开启两步验证</button>
            </form>
        @else
            @php($openPanel = session('merchant_2fa.open_panel'))
            <h1>两步验证已开启</h1>
            <div class="status">✓ 登录时会要求输入验证器六位数字</div>
            <p class="sub">请保管好绑定的那部手机——万一丢失，只有平台能帮您重置。</p>

            <div class="items">
                <details @if ($openPanel === 'replace') open @endif>
                    <summary>
                        <span><span class="t">更换验证器</span><span class="d" style="display:block">换手机或重装验证器时使用</span></span>
                        <span class="chev" aria-hidden="true">›</span>
                    </summary>
                    <div class="body">
                        <p class="sub" style="margin:0">提交后所有登录状态会失效，需要重新扫码绑定才能继续使用。</p>
                        <form method="post" action="{{ route('merchant.2fa.replace') }}" data-single-submit>
                            @csrf
                            <label for="replace-password">当前登录密码</label>
                            <input id="replace-password" name="current_password" type="password" autocomplete="current-password" required>
                            <label for="replace-code">验证器当前的六位数字</label>
                            <input id="replace-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required>
                            <button class="secondary" type="submit">更换验证器</button>
                        </form>
                    </div>
                </details>

                <details @if ($openPanel === 'disable') open @endif>
                    <summary>
                        <span><span class="t">关闭两步验证</span><span class="d" style="display:block">之后仅凭密码即可登录</span></span>
                        <span class="chev" aria-hidden="true">›</span>
                    </summary>
                    <div class="body">
                        <p class="sub" style="margin:0">开不开由您自己决定。关闭后，其它设备和 App 上的登录都会失效。</p>
                        <form method="post" action="{{ route('merchant.2fa.disable') }}" data-single-submit>
                            @csrf
                            <label for="disable-password">当前登录密码</label>
                            <input id="disable-password" name="current_password" type="password" autocomplete="current-password" required>
                            <label for="disable-code">验证器当前的六位数字</label>
                            <input id="disable-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required>
                            <button class="secondary" type="submit">关闭两步验证</button>
                        </form>
                    </div>
                </details>
            </div>

            <a class="button secondary" href="{{ $continuation_url ?? route('vendor.dashboard') }}">返回后台</a>
        @endif

        @if ($mode !== 'enabled')
            <form method="post" action="{{ route('merchant.2fa.cancel') }}">
                @csrf
                <button class="secondary" type="submit">{{ auth('vendor')->check() || auth('vendor_employee')->check() ? '暂不设置，返回个人资料' : '取消，返回登录页' }}</button>
            </form>
        @endif
    </section>
</main>
<script>
    document.querySelectorAll('[data-single-submit]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.submitted === '1') { event.preventDefault(); return; }
            form.dataset.submitted = '1';
            var button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
        });
    });
</script>
</body>
</html>
