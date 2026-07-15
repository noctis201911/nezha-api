<!DOCTYPE html>
<html dir="{{ $site_direction ?? 'ltr' }}" lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    @php $app_name = \App\CentralLogics\Helpers::get_business_settings('business_name', false); @endphp
    <title>两步验证 | {{ $app_name ?? 'Nezha' }}</title>
    <style>
        body{font-family:'Open Sans',-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f6f9;margin:0;color:#1f2937;}
        .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;}
        .card{background:#fff;max-width:380px;width:100%;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:28px;}
        h1{font-size:20px;margin:0 0 6px;}
        p.sub{color:#6b7280;font-size:14px;margin:0 0 20px;}
        label{display:block;font-size:13px;margin-bottom:6px;color:#374151;}
        input[type=text]{width:100%;box-sizing:border-box;padding:12px 14px;font-size:18px;letter-spacing:3px;text-align:center;border:1px solid #d1d5db;border-radius:10px;}
        button{width:100%;margin-top:16px;padding:12px;border:0;border-radius:10px;background:#E53935;color:#fff;font-size:15px;font-weight:600;cursor:pointer;}
        button:disabled{opacity:.7;cursor:not-allowed;}
        .err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:16px;}
        .hint{margin-top:16px;font-size:12px;color:#9ca3af;text-align:center;}
        a.logout{color:#9ca3af;}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>两步验证</h1>
        <p class="sub">请输入你认证器 App 上显示的 6 位验证码。</p>
        @if ($errors->any())
            <div class="err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif
        <form id="two-factor-form" action="{{ route('admin.2fa.verify') }}" method="POST">
            @csrf
            <label>验证码 / 恢复码</label>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" autofocus required>
            <button id="two-factor-submit" type="submit">验证并登录</button>
        </form>
        <div class="hint">认证器丢了? 用一个一次性恢复码也可登录。<br>都没有? 请通过服务器 SSH 关闭两步验证。</div>
        <div class="hint"><a class="logout" href="{{ route('logout') }}">返回登录</a></div>
    </div>
</div>
<script>
    (() => {
        const form = document.getElementById('two-factor-form');
        const button = document.getElementById('two-factor-submit');
        if (!form || !button) return;

        form.addEventListener('submit', (event) => {
            if (form.dataset.submitted === '1') {
                event.preventDefault();
                return;
            }

            form.dataset.submitted = '1';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = '验证中…';
        });
    })();
</script>
</body>
</html>
