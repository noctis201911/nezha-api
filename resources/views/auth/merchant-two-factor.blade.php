<!DOCTYPE html>
<html dir="{{ $site_direction ?? 'ltr' }}" lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant two-factor authentication</title>
    <style>
        :root{--bg:#fffaf5;--text:#1c1917;--muted:#78716c;--accent:#c4193e;--border:#e7e5e4}
        *{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#fffaf5,#fef3e7);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:100%;max-width:460px;background:#fff;border:1px solid var(--border);border-radius:22px;padding:32px;box-shadow:0 22px 55px rgba(196,25,62,.1)}
        h1{font-size:24px;margin:0 0 8px}.sub{color:var(--muted);line-height:1.55;margin:0 0 22px}.qr{text-align:center;margin:18px 0}.qr img{max-width:220px;width:100%;height:auto}.secret,.codes{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#fafaf9;border:1px solid var(--border);border-radius:12px;padding:14px;overflow-wrap:anywhere}.codes{line-height:1.8}
        label{display:block;font-size:14px;font-weight:600;margin:18px 0 8px}input{width:100%;border:1px solid var(--border);border-radius:12px;padding:14px;font-size:18px;letter-spacing:2px;text-align:center}button,.button{display:block;width:100%;border:0;border-radius:12px;padding:14px;margin-top:16px;background:var(--accent);color:#fff;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}.secondary{background:#fff;color:var(--muted);border:1px solid var(--border)}.error,.notice{border-radius:10px;padding:12px;margin-bottom:16px}.error{background:#fef2f2;color:#991b1b}.notice{background:#fff7ed;color:#9a3412}.hint{font-size:13px;color:var(--muted);line-height:1.55;margin-top:14px}@media(max-width:520px){.wrap{padding:14px}.card{padding:24px 20px;border-radius:18px}}
    </style>
</head>
<body>
<main class="wrap">
    <section class="card">
        @if ($errors->any())
            <div class="error">Unable to verify that code. Check the latest code and try again.</div>
        @endif
        @if (session('merchant_2fa.disabled_notice'))
            <div class="notice">Two-factor authentication is now off and all existing sessions were revoked. You may enable it again at any time.</div>
        @endif

        @if ($mode === 'challenge')
            <h1>Verify it is you</h1>
            <p class="sub">Enter the current six-digit code from your authenticator. Lost access to it? Contact the platform to have two-factor authentication reset for you.</p>
            <form method="post" action="{{ route('merchant.2fa.verify') }}" data-single-submit>
                @csrf
                <label for="merchant-2fa-code">Authenticator code</label>
                <input id="merchant-2fa-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
                <button type="submit">Verify and sign in</button>
            </form>
        @elseif ($mode === 'setup')
            <h1>Enable optional two-factor authentication</h1>
            <p class="sub">This is your choice. Scan this QR code with an authenticator app; the secret is stored only after you confirm a valid code. If you later lose the authenticator, contact the platform to have it reset — there are no printable backup codes.</p>
            <div class="qr"><img src="data:image/svg+xml;base64,{{ $qr_svg }}" alt="Authenticator QR code"></div>
            <p class="hint">Cannot scan? Enter this time-based secret manually:</p>
            <div class="secret">{{ $secret }}</div>
            <form method="post" action="{{ route('merchant.2fa.enable') }}" data-single-submit>
                @csrf
                <label for="merchant-2fa-code">Current six-digit code</label>
                <input id="merchant-2fa-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
                <button type="submit">Enable two-factor authentication</button>
            </form>
        @else
            <h1>Two-factor authentication is active</h1>
            <p class="sub">Your account now requires an authenticator code after every successful password check. Keep the authenticator app installed — if you lose it, only the platform can reset this for you.</p>
            <h2>Turn off two-factor authentication</h2>
            <p class="sub">This is your choice. Turning it off revokes all sessions and App tokens; your password alone will sign you in again.</p>
            <form method="post" action="{{ route('merchant.2fa.disable') }}" data-single-submit>
                @csrf
                <label for="disable-password">Current password</label>
                <input id="disable-password" name="current_password" type="password" autocomplete="current-password" required>
                <label for="disable-code">Current authenticator code</label>
                <input id="disable-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required>
                <button type="submit">Turn off two-factor authentication</button>
            </form>
            <h2>Replace authenticator</h2>
            <p class="sub">This revokes all sessions and requires scanning a new secret before access is restored.</p>
            <form method="post" action="{{ route('merchant.2fa.replace') }}" data-single-submit>
                @csrf
                <label for="replace-password">Current password</label>
                <input id="replace-password" name="current_password" type="password" autocomplete="current-password" required>
                <label for="replace-code">Current authenticator code</label>
                <input id="replace-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required>
                <button type="submit">Replace authenticator</button>
            </form>
            <a class="button" href="{{ $continuation_url ?? route('vendor.dashboard') }}">Continue</a>
        @endif

        @if ($mode !== 'enabled')
            <form method="post" action="{{ route('merchant.2fa.cancel') }}">
                @csrf
                <button class="secondary" type="submit">{{ auth('vendor')->check() || auth('vendor_employee')->check() ? 'Cancel and return to profile' : 'Cancel and return to login' }}</button>
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
