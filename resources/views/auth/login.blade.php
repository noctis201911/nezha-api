<!DOCTYPE html>
<?php $log_email_succ = session()->get('log_email_succ'); ?>
<html dir="{{ $site_direction }}" lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $app_name = \App\CentralLogics\Helpers::get_business_settings('business_name', false);
        $icon = \App\CentralLogics\Helpers::get_business_settings('icon', false);
        $recaptcha = \App\CentralLogics\Helpers::get_business_settings('recaptcha');
        $isVendor = in_array($role ?? '', ['vendor', 'vendor_employee']);
    @endphp
    <title>{{ translate('messages.login') }} | {{ $app_name ?? '哪吒外卖' }}</title>
    <link rel="shortcut icon" href="{{ $icon ? dynamicStorage('storage/app/public/business/'.$icon) : dynamicAsset('favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+SC:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #FFFAF5; --fg: #1C1917; --muted: #78716C;
            --accent: #C4193E; --accent-hover: #A8152F; --gold: #E8B53A;
            --card: #FFFFFF; --border: #E7E5E4;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Noto Sans SC', 'Space Grotesk', -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: var(--bg); color: var(--fg); min-height: 100vh; overflow-x: hidden;
        }
        .font-display { font-family: 'Space Grotesk', 'Noto Sans SC', sans-serif; }

        .login-page {
            min-height: 100vh;
            background: linear-gradient(180deg, #FFFAF5 0%, #FEF3E7 100%);
            position: relative; overflow: hidden;
        }
        .city-skyline { position: absolute; bottom: 0; left: 0; right: 0; height: 300px; opacity: 0.12; pointer-events: none; }
        .floating-food { position: absolute; width: 60px; height: 60px; opacity: 0.15; animation: floatFood 15s ease-in-out infinite; pointer-events: none; }
        @keyframes floatFood { 0%,100% { transform: translateY(0) rotate(0deg); } 25% { transform: translateY(-20px) rotate(5deg); } 75% { transform: translateY(10px) rotate(-5deg); } }
        .warm-glow { position: absolute; width: 600px; height: 600px; background: radial-gradient(circle, rgba(196,25,62,0.08) 0%, transparent 70%); top: -200px; right: -200px; animation: pulseGlow 8s ease-in-out infinite; pointer-events: none; }
        @keyframes pulseGlow { 0%,100% { transform: scale(1); opacity: 0.5; } 50% { transform: scale(1.2); opacity: 0.8; } }
        .ribbon { position: absolute; top: -40px; left: -60px; width: 520px; opacity: 0.9; pointer-events: none; }
        #particles { position: absolute; inset: 0; pointer-events: none; }

        .login-inner {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: clamp(32px, 5vh, 64px) clamp(20px, 5vw, 72px);
            position: relative; z-index: 10;
        }
        .login-columns {
            width: 100%; max-width: 1160px; display: grid; grid-template-columns: minmax(0, 1fr);
            align-items: center; justify-content: center; gap: 36px;
        }

        .brand-col { width: 100%; max-width: 432px; text-align: center; justify-self: center; }
        .brand-logo { display: inline-block; line-height: 0; }
        .brand-logo img { height: clamp(78px, 7vw, 104px); width: auto; display: inline-block; }
        .brand-title { font-size: clamp(30px, 3vw, 38px); line-height: 1.18; font-weight: 700; color: #1C1917; margin: 18px 0 14px; }
        .brand-desc {
            display: flex; flex-direction: column; align-items: center; gap: 3px;
            font-size: 16px; color: #78716C; max-width: 432px; margin: 0 auto 32px;
            line-height: 1.7; text-align: center;
        }
        .brand-desc .desc-main { color: #57534E; font-weight: 500; }
        .brand-desc .desc-sub { color: #78716C; font-size: 15px; }
        .features { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; max-width: 432px; margin: 0 auto; }
        .feature-card { min-height: 118px; padding: 18px 12px; background: rgba(255,255,255,0.92); border-radius: 14px; border: 1px solid var(--border); transition: all 0.3s ease; text-align: center; }
        .feature-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(196,25,62,0.06); border-color: rgba(196,25,62,0.2); }
        .feature-ic { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .feature-card .ft { font-weight: 600; font-size: 14px; color: #1C1917; }
        .feature-card .fs { font-size: 12px; margin-top: 4px; color: #A8A29E; }

        @media (min-width: 992px) {
            .login-columns { grid-template-columns: minmax(420px, 540px) minmax(380px, 440px); gap: clamp(64px, 8vw, 120px); }
            .brand-col { justify-self: start; text-align: center; }
            .brand-col .brand-desc, .brand-col .features { margin-left: auto; margin-right: auto; }
            .card-col { justify-self: end; }
        }

        .card-col { width: 100%; max-width: 440px; }
        .login-card {
            background: var(--card); border: 1px solid var(--border); border-radius: 24px;
            padding: 40px 36px; width: 100%; position: relative; z-index: 10;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 25px 50px -12px rgba(196,25,62,0.10);
        }
        .card-title { font-size: 22px; font-weight: 700; color: var(--fg); }
        .card-sub { font-size: 14px; color: var(--muted); margin: 4px 0 26px; }

        .input-group { position: relative; margin-bottom: 20px; }
        .field-label { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-bottom: 8px; transition: color 0.3s ease; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper > svg.field-icon { position: absolute; left: 16px; width: 20px; height: 20px; color: var(--muted); transition: color 0.3s ease; pointer-events: none; }
        .form-input { width: 100%; padding: 14px 16px 14px 48px; background: #FEFDFB; border: 1px solid var(--border); border-radius: 12px; font-size: 15px; color: var(--fg); font-family: inherit; transition: all 0.3s ease; outline: none; }
        .form-input::placeholder { color: #A8A29E; }
        .form-input:focus { border-color: var(--accent); background: #FFFFFF; box-shadow: 0 0 0 4px rgba(196,25,62,0.08); }
        .form-input:focus ~ svg.field-icon { color: var(--accent); }
        .pass-toggle { position: absolute; right: 14px; background: none; border: none; color: var(--muted); cursor: pointer; display: flex; align-items: center; padding: 4px; }
        .pass-toggle svg { width: 20px; height: 20px; }

        .captcha-row { display: flex; gap: 10px; align-items: stretch; }
        .captcha-row .input-wrapper { flex: 1; }
        .captcha-img-wrap { display: flex; align-items: center; gap: 4px; background: #F5F5F5; border: 1px solid var(--border); border-radius: 12px; padding: 0 8px 0 6px; cursor: pointer; flex-shrink: 0; }
        .captcha-img-wrap img { height: 40px; max-width: 120px; border-radius: 8px; display: block; }
        .captcha-reload { color: var(--muted); font-size: 17px; line-height: 1; padding: 0 4px; transition: color 0.2s, transform 0.4s; flex-shrink: 0; }
        .captcha-img-wrap:hover .captcha-reload { color: var(--accent); transform: rotate(180deg); }

        .form-meta { display: flex; align-items: center; justify-content: space-between; margin: 4px 0 20px; font-size: 13px; }
        .remember { display: flex; align-items: center; gap: 8px; color: var(--muted); cursor: pointer; user-select: none; }
        .remember input { width: 16px; height: 16px; accent-color: var(--accent); }
        .link { color: var(--accent); text-decoration: none; font-weight: 500; background: none; border: none; font-family: inherit; font-size: 13px; cursor: pointer; padding: 0; }
        .link:hover { color: var(--accent-hover); }

        .login-btn { width: 100%; padding: 16px; background: linear-gradient(135deg, #E03A4E, #C4193E); color: #fff; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; letter-spacing: 2px; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; box-shadow: 0 10px 22px -8px rgba(196,25,62,0.55); font-family: inherit; }
        .login-btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: left 0.5s ease; }
        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 30px -8px rgba(196,25,62,0.5); }
        .login-btn:hover::before { left: 100%; }
        .login-btn:active { transform: translateY(0); }

        .card-foot { text-align: center; margin-top: 24px; font-size: 12px; color: #A8A29E; }

        .error-banner { background: rgba(196,25,62,0.08); border: 1px solid rgba(196,25,62,0.28); color: var(--accent-hover); padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; }
        .error-banner ul { margin: 0; padding-left: 18px; }
        .error-banner li + li { margin-top: 4px; }

        .modal-backdrop { position: fixed; inset: 0; background: rgba(40,20,25,0.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal-box { background: #fff; border: 1px solid var(--border); border-radius: 20px; padding: 34px 30px 28px; width: 100%; max-width: 400px; box-shadow: 0 25px 70px rgba(0,0,0,0.18); text-align: center; position: relative; }
        .modal-close { position: absolute; top: 14px; right: 16px; background: none; border: none; color: #A8A29E; font-size: 22px; cursor: pointer; line-height: 1; font-family: inherit; }
        .modal-close:hover { color: var(--fg); }
        .modal-icon { width: 60px; height: 60px; border-radius: 50%; background: rgba(196,25,62,0.10); color: var(--accent); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px; }
        .modal-box h4 { font-size: 18px; font-weight: 600; margin-bottom: 8px; color: var(--fg); }
        .modal-box p { font-size: 14px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
        .modal-box .form-input { padding-left: 16px; margin-bottom: 14px; }
        .modal-box .login-btn { padding: 13px; }

        .stagger-1 { animation-delay: 0.1s; } .stagger-2 { animation-delay: 0.2s; } .stagger-3 { animation-delay: 0.3s; } .stagger-4 { animation-delay: 0.4s; }
        .fade-up { opacity: 0; transform: translateY(20px); animation: fadeUp 0.6s ease forwards; }
        @keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 991px) {
            .login-inner { align-items: flex-start; padding-top: 48px; padding-bottom: 120px; }
        }
        @media (max-width: 640px) {
            .login-card { padding: 30px 22px; border-radius: 20px; }
            .brand-title { margin-top: 14px; }
            .brand-desc { font-size: 15px; margin-bottom: 24px; }
            .features { grid-template-columns: 1fr; max-width: 280px; gap: 12px; }
            .feature-card { min-height: auto; display: flex; align-items: center; justify-content: center; gap: 12px; padding: 14px 16px; }
            .feature-ic { margin: 0; }
            .feature-card .fs { margin-top: 2px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; } }
    </style>
</head>
<body>
<main class="login-page">
    <canvas id="particles"></canvas>
    <div class="warm-glow"></div>

    <svg class="ribbon" viewBox="0 0 520 300" fill="none">
        <path d="M-20 60 C120 20 260 120 420 60 C480 40 520 80 540 60" stroke="#C4193E" stroke-width="26" stroke-linecap="round" opacity="0.12"/>
        <path d="M-20 110 C140 70 280 160 440 100 C500 80 540 120 560 100" stroke="#E8B53A" stroke-width="16" stroke-linecap="round" opacity="0.14"/>
        <path d="M-20 150 C120 120 300 200 460 150" stroke="#C4193E" stroke-width="10" stroke-linecap="round" opacity="0.10"/>
    </svg>

    <div class="floating-food" style="top: 15%; left: 8%; animation-delay: 0s;">
        <svg viewBox="0 0 64 64" fill="none" stroke="#C4193E" stroke-width="1.5"><circle cx="32" cy="32" r="24"/><path d="M20 28 Q32 18 44 28 Q32 38 20 28" fill="rgba(196,25,62,0.1)"/><circle cx="26" cy="30" r="3" fill="rgba(196,25,62,0.2)"/><circle cx="38" cy="30" r="3" fill="rgba(196,25,62,0.2)"/></svg>
    </div>
    <div class="floating-food" style="top: 30%; right: 10%; animation-delay: 4s;">
        <svg viewBox="0 0 64 64" fill="none" stroke="#E03A4E" stroke-width="1.5"><rect x="16" y="24" width="32" height="24" rx="4"/><path d="M22 24 V18 Q22 12 32 12 Q42 12 42 18 V24" fill="none"/><circle cx="26" cy="36" r="4" fill="rgba(224,58,78,0.15)"/><circle cx="38" cy="36" r="4" fill="rgba(224,58,78,0.15)"/></svg>
    </div>
    <div class="floating-food" style="bottom: 25%; left: 12%; animation-delay: 8s;">
        <svg viewBox="0 0 64 64" fill="none" stroke="#E8B53A" stroke-width="1.5"><path d="M32 8 Q44 16 44 28 Q44 44 32 52 Q20 44 20 28 Q20 16 32 8"/><circle cx="32" cy="30" r="8" fill="rgba(232,181,58,0.15)"/></svg>
    </div>

    <svg class="city-skyline" viewBox="0 0 1440 300" preserveAspectRatio="xMidYMax slice">
        <path fill="#292524" d="M0,300 L0,250 L40,250 L40,200 L60,200 L60,180 L80,180 L80,200 L100,200 L100,250 L140,250 L140,220 L160,220 L160,150 L180,150 L180,130 L200,130 L200,150 L220,150 L220,220 L260,220 L260,250 L300,250 L300,180 L320,180 L320,160 L340,160 L340,140 L360,140 L360,160 L380,160 L380,180 L400,180 L400,250 L440,250 L440,200 L480,200 L480,120 L500,120 L500,100 L520,100 L520,120 L540,120 L540,200 L580,200 L580,250 L620,250 L620,190 L660,190 L660,250 L700,250 L700,160 L720,160 L720,140 L740,140 L740,160 L760,160 L760,250 L800,250 L800,180 L820,180 L820,150 L840,150 L840,180 L860,180 L860,250 L900,250 L900,200 L940,200 L940,250 L980,250 L980,170 L1000,170 L1000,140 L1020,140 L1020,170 L1040,170 L1040,250 L1080,250 L1080,210 L1100,210 L1100,250 L1140,250 L1140,180 L1160,180 L1160,160 L1180,160 L1180,180 L1200,180 L1200,250 L1240,250 L1240,200 L1280,200 L1280,250 L1320,250 L1320,220 L1360,220 L1360,250 L1440,250 L1440,300 Z"/>
    </svg>

    <div class="login-inner">
        <div class="login-columns">
            <!-- 品牌区 -->
            <div class="brand-col">
                @php($systemlogo = \App\Models\BusinessSetting::where(['key' => 'logo'])->first())
                <div class="brand-logo fade-up stagger-1">
                    <img src="{{ dynamicAsset('assets/admin/img/nezha-brand-logo.png') }}"
                         onerror="this.onerror=null;this.src='{{ \App\CentralLogics\Helpers::get_full_url('business', $systemlogo?->value, $systemlogo?->storage[0]?->value ?? 'public', 'authfav') }}'"
                         alt="{{ $app_name ?? '哪吒外卖' }}">
                </div>
                <h1 class="font-display brand-title fade-up stagger-2">{{ $isVendor ? '商家管理后台' : '管理后台' }}</h1>
                <p class="brand-desc fade-up stagger-3">
                    @if ($isVendor)
                        <span class="desc-main">专为华人餐厅打造的一站式经营后台</span>
                        <span class="desc-sub">订单、菜品、数据与营销，轻松触达每一位食客</span>
                    @else
                        {{ translate('Manage_your_app_&_website_easily') }}
                    @endif
                </p>
                @if ($isVendor)
                <div class="features fade-up stagger-4">
                    <div class="feature-card">
                        <div class="feature-ic" style="background: rgba(196,25,62,0.08);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4193E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        </div>
                        <div class="ft">实时接单</div><div class="fs">秒级响应</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-ic" style="background: rgba(232,181,58,0.12);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4193E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                        </div>
                        <div class="ft">数据分析</div><div class="fs">智能洞察</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-ic" style="background: rgba(196,25,62,0.08);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C4193E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m7.08 7.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m7.08-7.08l4.24-4.24"/></svg>
                        </div>
                        <div class="ft">营销工具</div><div class="fs">引流获客</div>
                    </div>
                </div>
                @endif
            </div>

            <!-- 登录卡片 -->
            <div class="card-col">
                <div class="login-card fade-up stagger-3">
                    <h2 class="card-title">{{ $isVendor ? '登录商家后台' : '登录管理后台' }}</h2>
                    <p class="card-sub">{{ $isVendor ? '管理您的餐厅，触达更多食客' : translate('messages.Signin_To_Your_Panel') }}</p>

                    @if ($errors->any())
                        <div class="error-banner">
                            <span style="flex-shrink:0;">⚠️</span>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ translate($error) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form class="login_form" action="{{ in_array($role ?? null, ['admin', 'admin_employee'], true) ? route('admin.login_post') : route('login_post') }}" method="post" id="form-id" autocomplete="off">
                        @csrf
                        <input type="hidden" name="role" value="{{ $role ?? null }}">

                        <div class="input-group">
                            <label class="field-label" for="signinSrEmail">{{ translate('messages.your_email') }}</label>
                            <div class="input-wrapper">
                                <input type="email" class="form-input" name="email" id="signinSrEmail" value="{{ $email ?? '' }}"
                                       tabindex="1" required placeholder="name@restaurant.com"
                                       data-msg="Please enter a valid email address.">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="field-label" for="signupSrPassword">{{ translate('messages.password') }}</label>
                            <div class="input-wrapper">
                                <input type="password" class="form-input" name="password" id="signupSrPassword"
                                       tabindex="2" required placeholder="{{ translate('messages.password') }}"
                                       style="padding-right: 48px;"
                                       data-msg="{{ translate('messages.invalid_password_warning') }}">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <button type="button" class="pass-toggle" id="togglePass" aria-label="{{ translate('messages.show') ?? '显示密码' }}">
                                    <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="field-label" for="custome_recaptcha">{{ translate('Enter recaptcha value') }}</label>
                            @if (isset($recaptcha) && $recaptcha['status'] == 1)
                                <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                                <input type="hidden" name="set_default_captcha" id="set_default_captcha_value" value="1">
                            @endif
                            <div class="captcha-row" id="reload-captcha">
                                <div class="input-wrapper">
                                    <input type="text" class="form-input" name="custome_recaptcha" id="custome_recaptcha"
                                           placeholder="{{ translate('Enter recaptcha value') }}" required autocomplete="off" tabindex="3"
                                           value="{{ env('APP_MODE')=='dev' ? session('six_captcha') : '' }}">
                                    <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                                </div>
                                <div class="captcha-img-wrap reloadCaptcha" title="{{ translate('messages.refresh') ?? '刷新' }}">
                                    <img src="<?php echo $custome_recaptcha->inline(); ?>" alt="captcha">
                                    <span class="captcha-reload">⟳</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-meta">
                            @if ($isVendor)
                                <span class="remember">For your security, merchant login is not kept across browser sessions.</span>
                            @else
                                <label class="remember" for="termsCheckbox">
                                    <input type="checkbox" id="termsCheckbox" name="remember" {{ ($remember ?? false) ? 'checked' : '' }}>
                                    {{ translate('messages.remember_me') }}
                                </label>
                            @endif
                            @if (($role ?? '') === 'vendor')
                                <button type="button" class="link" id="openForgetModal">{{ translate('Forget_Password') }}?</button>
                            @endif
                        </div>

                        <button type="submit" class="login-btn" id="signInBtn" tabindex="4">{{ translate('messages.sign_in') }}</button>
                    </form>
                </div>

                <div class="card-foot fade-up stagger-4">
                    © {{ date('Y') }} {{ $app_name ?? '哪吒外卖' }}
                </div>
            </div>
        </div>
    </div>
</main>

{{-- 忘记密码 Modal (商家: 邮箱找回) --}}
@if (($role ?? '') === 'vendor')
<div class="modal-backdrop" id="forgetPassModal">
    <div class="modal-box">
        <button type="button" class="modal-close" data-close>×</button>
        <div class="modal-icon">✉</div>
        <h4>{{ translate('messages.Send_Mail_to_Your_Email_?') }}</h4>
        <p>{{ translate('A_mail_will_be_send_to_your_registered_email_with_a_link_to_change_passowrd') }}</p>
        <form action="{{ route('vendor-reset-password') }}" method="post">
            @csrf
            <input type="email" name="email" class="form-input" required placeholder="name@restaurant.com">
            <button type="submit" class="login-btn">{{ translate('messages.Send_Mail') }}</button>
        </form>
    </div>
</div>
@endif

{{-- 邮件已发送成功提示 Modal --}}
@if ($log_email_succ)
    @php(session()->forget('log_email_succ'))
    <div class="modal-backdrop show" id="successMailModal">
        <div class="modal-box">
            <button type="button" class="modal-close" data-close>×</button>
            <div class="modal-icon" style="background:rgba(46,204,113,0.12);color:#2ecc71;">✓</div>
            <h4>{{ translate('Mail Sent to Registered Email Successfully') }}</h4>
            <p>{{ translate('An email with password recovery instructions has been sent to your registered email address. Follow the link to reset your password.') }}</p>
        </div>
    </div>
@endif

<script>
    // 粒子背景
    (function initParticles() {
        var canvas = document.getElementById('particles');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var particles = [];
        function resize() { canvas.width = canvas.parentElement.offsetWidth; canvas.height = canvas.parentElement.offsetHeight; }
        function create() {
            particles = [];
            var count = Math.floor((canvas.width * canvas.height) / 30000);
            for (var i = 0; i < count; i++) {
                particles.push({ x: Math.random()*canvas.width, y: Math.random()*canvas.height, radius: Math.max(1, Math.random()*2.5), vx: (Math.random()-0.5)*0.4, vy: (Math.random()-0.5)*0.4, alpha: Math.random()*0.35 + 0.1 });
            }
        }
        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(function(p) {
                p.x += p.vx; p.y += p.vy;
                if (p.x < 0) p.x = canvas.width; if (p.x > canvas.width) p.x = 0;
                if (p.y < 0) p.y = canvas.height; if (p.y > canvas.height) p.y = 0;
                ctx.beginPath(); ctx.arc(p.x, p.y, Math.max(0.5, p.radius), 0, Math.PI*2);
                ctx.fillStyle = 'rgba(196,25,62,' + p.alpha + ')'; ctx.fill();
            });
            requestAnimationFrame(animate);
        }
        resize(); create(); animate();
        window.addEventListener('resize', function() { resize(); create(); });
    })();

    (function() {
        // 显示/隐藏密码
        var pwd = document.getElementById('signupSrPassword');
        var toggle = document.getElementById('togglePass');
        var eyeIcon = document.getElementById('eyeIcon');
        if (toggle && pwd) {
            toggle.addEventListener('click', function() {
                if (pwd.type === 'password') {
                    pwd.type = 'text';
                    eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
                } else {
                    pwd.type = 'password';
                    eyeIcon.innerHTML = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
                }
            });
        }

        // 输入框 label 聚焦变色
        document.querySelectorAll('.form-input').forEach(function(input) {
            input.addEventListener('focus', function() {
                var g = input.closest('.input-group'); if (!g) return;
                var l = g.querySelector('.field-label'); if (l) l.style.color = 'var(--accent)';
            });
            input.addEventListener('blur', function() {
                var g = input.closest('.input-group'); if (!g) return;
                var l = g.querySelector('.field-label'); if (l) l.style.color = 'var(--muted)';
            });
        });

        // 忘记密码 Modal 开关
        var openBtn = document.getElementById('openForgetModal');
        var modal = document.getElementById('forgetPassModal');
        if (openBtn && modal) { openBtn.addEventListener('click', function() { modal.classList.add('show'); }); }
        document.querySelectorAll('[data-close]').forEach(function(btn) {
            btn.addEventListener('click', function() { btn.closest('.modal-backdrop').classList.remove('show'); });
        });
        document.querySelectorAll('.modal-backdrop').forEach(function(m) {
            m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('show'); });
        });

        // 刷新图形验证码 (服务端返回旧版 bootstrap markup, 只抽取 captcha 图片)
        var captchaLoading = false;
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.reloadCaptcha');
            if (!trigger || captchaLoading) return;
            captchaLoading = true;
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';
            fetch("{{ route('reload-captcha') }}", { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token } })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var wrap = document.getElementById('reload-captcha');
                    if (wrap && data.view) {
                        var tmp = document.createElement('div'); tmp.innerHTML = data.view;
                        var img = tmp.querySelector('img');
                        var newImg = wrap.querySelector('.captcha-img-wrap img');
                        if (img && newImg) {
                            newImg.src = img.getAttribute('src');
                            var inputEl = wrap.querySelector('input[name="custome_recaptcha"]');
                            if (inputEl) inputEl.value = '';
                        }
                    }
                })
                .catch(function() {})
                .finally(function() { captchaLoading = false; });
        });
    })();
</script>
</body>
</html>
