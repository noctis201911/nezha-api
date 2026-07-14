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
    @endphp
    <title>{{ translate('messages.login') }} | {{ $app_name ?? '哪吒外卖' }}</title>
    <link rel="shortcut icon" href="{{ asset($icon ? 'storage/app/public/business/'.$icon : 'public/favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: #0a0a0f;
            color: #ffffff;
            overflow: hidden;
            height: 100vh;
            position: relative;
        }

        .ui-container {
            position: relative;
            z-index: 10;
            display: flex;
            height: 100vh;
            padding: 40px;
        }

        .visual-section {
            flex: 1.2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding-right: 60px;
            position: relative;
            z-index: 5;
        }

        /* 斜向混天绫 - 下方 */
        .ribbon-static {
            position: absolute;
            bottom: -50px;
            left: -100px;
            width: 1600px;
            height: 400px;
            z-index: 2;
            transform: rotate(-35deg);
            transform-origin: left bottom;
            pointer-events: none;
            filter:
                drop-shadow(0 0 8px rgba(255, 59, 48, 0.5))
                drop-shadow(0 0 20px rgba(255, 107, 74, 0.3))
                drop-shadow(0 0 40px rgba(255, 59, 48, 0.2))
                drop-shadow(0 0 80px rgba(255, 107, 74, 0.1));
        }

        /* 横向混天绫 - 上方 */
        .ribbon-horizontal {
            position: absolute;
            bottom: 35%;
            left: -150px;
            width: calc(100vw + 300px);
            height: 400px;
            z-index: 3;
            transform: rotate(-13deg);
            pointer-events: none;
            filter:
                drop-shadow(0 0 8px rgba(255, 59, 48, 0.5))
                drop-shadow(0 0 20px rgba(255, 107, 74, 0.3))
                drop-shadow(0 0 40px rgba(255, 59, 48, 0.2))
                drop-shadow(0 0 80px rgba(255, 107, 74, 0.1));
        }

        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 8;
            pointer-events: none;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 200, 100, 0.9) 0%, rgba(255, 107, 74, 0.5) 40%, rgba(255, 59, 48, 0) 70%);
            animation: floatParticle linear infinite;
        }

        @keyframes floatParticle {
            0%   { transform: translate(0, 0) scale(0.5); opacity: 0; }
            10%  { opacity: 1; }
            50%  { transform: translate(var(--dx, 30px), var(--dy, -60px)) scale(1); opacity: 0.8; }
            90%  { opacity: 0.3; }
            100% { transform: translate(var(--dx2, 60px), var(--dy2, -120px)) scale(0.3); opacity: 0; }
        }

        .particle-slow { animation: floatParticleSlow ease-in-out infinite; }

        @keyframes floatParticleSlow {
            0%   { transform: translate(0, 0) scale(0.3); opacity: 0; }
            15%  { opacity: 0.7; }
            50%  { transform: translate(var(--dx, 20px), var(--dy, -40px)) scale(1.2); opacity: 0.5; }
            85%  { opacity: 0.2; }
            100% { transform: translate(var(--dx2, 50px), var(--dy2, -90px)) scale(0.2); opacity: 0; }
        }

        .particle-gold {
            background: radial-gradient(circle, rgba(255, 215, 0, 0.9) 0%, rgba(255, 180, 50, 0.4) 40%, rgba(255, 215, 0, 0) 70%);
            animation: floatParticleGold ease-in-out infinite;
        }

        @keyframes floatParticleGold {
            0%   { transform: translate(0, 0) scale(0.2); opacity: 0; }
            20%  { opacity: 0.8; }
            60%  { transform: translate(var(--dx, 15px), var(--dy, -50px)) scale(0.8); opacity: 0.6; }
            100% { transform: translate(var(--dx2, 40px), var(--dy2, -100px)) scale(0.1); opacity: 0; }
        }

        .visual-content {
            max-width: 540px;
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            height: 100%;
            margin: 0 auto;
        }

        .visual-logo {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            margin-bottom: 28px;
            box-shadow: 0 8px 32px rgba(255, 59, 48, 0.35), 0 0 0 1px rgba(255, 215, 0, 0.2);
            background: #14141f;
            object-fit: cover;
        }

        .main-title {
            font-size: 48px;
            font-weight: 300;
            line-height: 1.2;
            margin-bottom: 18px;
            letter-spacing: -1px;
        }
        .main-title strong {
            font-weight: 700;
            background: linear-gradient(90deg, #ff3b30, #ffd700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .visual-sub {
            font-size: 16px;
            color: #b8b8c8;
            margin-bottom: 28px;
            letter-spacing: 0.3px;
        }

        .badge-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        .badge-row span {
            background: rgba(255, 59, 48, 0.12);
            color: #ff9d85;
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid rgba(255, 59, 48, 0.18);
        }

        .login-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 10;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: rgba(20, 20, 35, 0.82);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        .form-header { margin-bottom: 28px; }
        .form-title { font-size: 28px; font-weight: 600; margin-bottom: 8px; }
        .form-subtitle { font-size: 15px; color: #a0a0a0; }
        .input-group { margin-bottom: 20px; }
        .input-label {
            display: block;
            font-size: 13px;
            color: #a0a0a0;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
        .input-wrapper { position: relative; }
        .input-field {
            width: 100%;
            padding: 14px 18px;
            background: rgba(30, 30, 45, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #ffffff;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.25s ease;
        }
        .input-field::placeholder { color: #555570; }
        .input-field:focus {
            outline: none;
            border-color: #ff3b30;
            background: rgba(40, 40, 60, 0.8);
            box-shadow: 0 0 0 3px rgba(255, 59, 48, 0.18);
        }
        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0a0a0;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .input-icon:hover { color: #ffd700; }

        /* 图形验证码 */
        .captcha-row {
            display: flex;
            gap: 12px;
            align-items: stretch;
        }
        .captcha-row .input-wrapper { flex: 1.1; }
        .captcha-img-wrap {
            flex: 1;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 10px 4px 4px;
            min-width: 0;
            cursor: pointer;
        }
        .captcha-img-wrap img {
            height: 44px;
            max-width: 100%;
            border-radius: 6px;
            display: block;
        }
        .captcha-reload {
            color: #555570;
            font-size: 18px;
            line-height: 1;
            padding: 0 6px;
            flex-shrink: 0;
            transition: color 0.2s, transform 0.4s;
        }
        .captcha-img-wrap:hover .captcha-reload { color: #ff3b30; transform: rotate(180deg); }

        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .checkbox-wrapper { display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
        .custom-checkbox {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.25);
            border-radius: 4px;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            position: relative;
        }
        .checkbox-wrapper input:checked + .custom-checkbox { background: #ff3b30; border-color: #ff3b30; }
        .checkbox-wrapper input:checked + .custom-checkbox::after {
            content: '';
            width: 5px;
            height: 9px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg) translate(-1px, -1px);
        }
        .checkbox-wrapper input { display: none; }
        .checkbox-label { font-size: 14px; color: #a0a0a0; }
        .forgot-link {
            font-size: 14px;
            color: #a0a0a0;
            text-decoration: none;
            transition: color 0.2s ease;
            cursor: pointer;
            background: none;
            border: none;
            font-family: inherit;
            padding: 0;
        }
        .forgot-link:hover { color: #ffd700; }

        .btn-primary {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #ff3b30, #ff6b4a);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 10px 30px rgba(255, 59, 48, 0.3);
            font-family: inherit;
            letter-spacing: 0.5px;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(255, 59, 48, 0.4); }
        .btn-primary:active { transform: translateY(0); }

        .copyright { text-align: center; margin-top: 26px; font-size: 12px; color: #666666; line-height: 1.6; }

        /* 错误条 */
        .error-banner {
            background: rgba(255, 59, 48, 0.12);
            border: 1px solid rgba(255, 59, 48, 0.35);
            color: #ff9d85;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .error-banner ul { margin: 0; padding-left: 18px; }
        .error-banner li + li { margin-top: 4px; }

        /* 忘记密码 modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-backdrop.show { display: flex; }
        .modal-box {
            background: rgba(20, 20, 35, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 36px 32px 30px;
            width: 92%;
            max-width: 420px;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            color: #888;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            font-family: inherit;
        }
        .modal-close:hover { color: #fff; }
        .modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(255, 59, 48, 0.12);
            color: #ff6b4a;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 28px;
        }
        .modal-box h4 { font-size: 19px; font-weight: 600; margin-bottom: 10px; }
        .modal-box p { font-size: 14px; color: #a0a0a0; margin-bottom: 22px; line-height: 1.5; }
        .modal-box .btn-primary { width: 100%; padding: 13px; }

        @media (max-width: 1200px) {
            .ui-container { flex-direction: column; padding: 20px; overflow-y: auto; }
            .visual-section { padding-right: 0; margin-bottom: 30px; min-height: 280px; }
            .main-title { font-size: 36px; }
            .login-card { max-width: 100%; padding: 32px 24px; }
        }
        @media (max-width: 600px) {
            .ui-container { padding: 16px; }
            .main-title { font-size: 30px; }
            .captcha-row { flex-direction: column; }
            .captcha-img-wrap { width: 100%; }
        }
    </style>
</head>
<body>
<div class="ui-container">
    <div class="visual-section">
        <!-- 斜向混天绫 — 主带改为闭合带, 右端伸到屏幕外不再收尖 -->
        <svg class="ribbon-static" viewBox="0 0 1200 400" preserveAspectRatio="none" style="overflow:visible">
            <defs>
                <linearGradient id="ribbonGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#ff3b30" stop-opacity="0.8"/>
                    <stop offset="50%" stop-color="#ff6b4a" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="#ff9d85" stop-opacity="0.3"/>
                </linearGradient>
                <linearGradient id="goldGlow" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#ffd700" stop-opacity="0"/>
                    <stop offset="50%" stop-color="#ffd700" stop-opacity="0.3"/>
                    <stop offset="100%" stop-color="#ffd700" stop-opacity="0"/>
                </linearGradient>
                <pattern id="subtlePattern" patternUnits="userSpaceOnUse" width="100" height="100">
                    <path d="M0,50 Q25,30 50,50 T100,50" stroke="#ff3b30" stroke-width="0.5" fill="none" opacity="0.2"/>
                </pattern>
            </defs>
            {{-- 主带: 闭合带 (~25 单位带宽, 接近原楔形最厚处), 右端 (1800,-110) 屏幕外 --}}
            <path d="M-30,380 C200,270 400,380 600,240 S900,130 1800,-110 L1800,-85 C1400,55 900,165 600,265 C400,315 200,305 -30,405 Z" fill="url(#ribbonGradient)" stroke="url(#goldGlow)" stroke-width="2" filter="url(#subtlePattern)"/>
            {{-- 三条细线尾迹: 端点伸到 x=1800 屏幕外; 第一条兼作粒子采样导引线 --}}
            <path id="guideLower" d="M50,330 C250,260 450,360 650,230 S950,130 1800,-140" fill="none" stroke="#ff6b4a" stroke-width="1" opacity="0.3"/>
            <path d="M100,310 C300,240 500,340 700,210 S1000,110 1800,-170" fill="none" stroke="#ff9d85" stroke-width="1" opacity="0.2"/>
            <path d="M150,290 C350,220 550,320 750,190 S1050,90 1800,-200" fill="none" stroke="#ff9d85" stroke-width="0.8" opacity="0.15"/>
        </svg>

        <!-- 横向混天绫 — 闭合带 + 端点伸到屏幕外右上, 不再露出收尖楔形 -->
        <svg class="ribbon-horizontal" viewBox="0 0 1200 450" preserveAspectRatio="none" style="overflow:visible">
            <defs>
                <linearGradient id="ribbonGradientH" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#ff3b30" stop-opacity="0.8"/>
                    <stop offset="50%" stop-color="#ff6b4a" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="#ff9d85" stop-opacity="0.3"/>
                </linearGradient>
                <linearGradient id="goldGlowH" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#ffd700" stop-opacity="0"/>
                    <stop offset="50%" stop-color="#ffd700" stop-opacity="0.3"/>
                    <stop offset="100%" stop-color="#ffd700" stop-opacity="0"/>
                </linearGradient>
                <pattern id="subtlePatternH" patternUnits="userSpaceOnUse" width="100" height="100">
                    <path d="M0,50 Q25,30 50,50 T100,50" stroke="#ff3b30" stroke-width="0.5" fill="none" opacity="0.2"/>
                </pattern>
            </defs>
            {{-- 主带: 闭合带 (~25 单位带宽, 接近原楔形最厚处), 右端 (1800,-80) 屏幕外 --}}
            <path d="M-30,440 C200,330 400,400 600,260 S900,160 1800,-80 L1800,-55 C1400,55 900,185 600,285 C400,335 200,375 -30,465 Z" fill="url(#ribbonGradientH)" stroke="url(#goldGlowH)" stroke-width="2" filter="url(#subtlePatternH)"/>
            {{-- 三条细线尾迹: 端点都伸到 x=1800 屏幕外; 第一条兼作粒子采样导引线 --}}
            <path id="guideUpper" d="M40,420 C250,300 450,380 650,230 S950,130 1800,-130" fill="none" stroke="#ff6b4a" stroke-width="1" opacity="0.3"/>
            <path d="M90,410 C300,280 500,360 700,210 S1000,110 1800,-160" fill="none" stroke="#ff9d85" stroke-width="1" opacity="0.2"/>
            <path d="M140,400 C350,260 550,340 750,190 S1050,90 1800,-190" fill="none" stroke="#ff9d85" stroke-width="0.8" opacity="0.15"/>
        </svg>

        <div class="visual-content">
            @php($systemlogo=\App\Models\BusinessSetting::where(['key'=>'logo'])->first())
            <img class="visual-logo onerror-image"
                 src="{{ \App\CentralLogics\Helpers::get_full_url('business', $systemlogo?->value, $systemlogo?->storage[0]?->value ?? 'public', 'authfav') }}"
                 onerror="this.onerror=null;this.src='{{ dynamicAsset('assets/admin/img/auth-fav.png') }}'"
                 alt="logo">
            <h1 class="main-title">
                <strong>{{ $app_name ?? '哪吒外卖' }}管理后台</strong>
            </h1>
            <p class="visual-sub">{{ translate('Manage_your_app_&_website_easily') }}</p>
        </div>
    </div>

    {{-- 光晕粒子层：仅沿混天绫线条分布，从线条中向右上方流出；右下角区域已清空 --}}
    <div class="particles">
        {{-- 粒子全部由页尾 JS genRibbonHaloParticles() 沿真实 SVG 路径屏幕坐标生成 --}}
        {{-- (手写静态粒子已删除: 直线公式跟 CSS rotate 后的真实线条对不齐, 会飘到左上角空白处) --}}
    </div>

    <div class="login-section">
        <div class="login-card">
            <div class="form-header">
                <h2 class="form-title">管理员登录</h2>
                <p class="form-subtitle">{{ translate('messages.Signin_To_Your_Panel') }}</p>
            </div>

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

            <form class="login_form" action="{{ route('admin.login_post') }}" method="post" id="form-id" autocomplete="off">
                @csrf
                <input type="hidden" name="role" value="{{ $role ?? 'admin' }}">

                <div class="input-group">
                    <label class="input-label" for="signinSrEmail">{{ translate('messages.your_email') }}</label>
                    <div class="input-wrapper">
                        <input type="email" class="input-field" name="email" id="signinSrEmail"
                               value="{{ $email ?? '' }}" tabindex="1" required
                               placeholder="admin@nezha.am"
                               data-msg="Please enter a valid email address.">
                    </div>
                </div>

                <div class="input-group">
                    <label class="input-label" for="signupSrPassword">{{ translate('messages.password') }}</label>
                    <div class="input-wrapper">
                        <input type="password" class="input-field" name="password" id="signupSrPassword"
                               value="{{ $password ?? '' }}" tabindex="2" required
                               placeholder="••••••••••••"
                               data-msg="{{ translate('messages.invalid_password_warning') }}"
                               style="padding-right: 48px;">
                        <span class="input-icon" id="togglePass" title="{{ translate('messages.show') ?? '显示' }}">
                            <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="input-group">
                    <label class="input-label">{{ translate('Enter recaptcha value') }}</label>
                    @if (isset($recaptcha) && $recaptcha['status'] == 1)
                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                        <input type="hidden" name="set_default_captcha" id="set_default_captcha_value" value="1">
                    @endif
                    <div class="captcha-row" id="reload-captcha">
                        <div class="input-wrapper">
                            <input type="text" class="input-field" name="custome_recaptcha" id="custome_recaptcha"
                                   placeholder="{{ translate('Enter recaptcha value') }}" required autocomplete="off"
                                   tabindex="3"
                                   value="{{ env('APP_MODE')=='dev' ? session('six_captcha') : '' }}">
                        </div>
                        <div class="captcha-img-wrap reloadCaptcha" title="{{ translate('messages.refresh') ?? '刷新' }}">
                            <img src="<?php echo $custome_recaptcha->inline(); ?>" alt="captcha">
                            <span class="captcha-reload">⟳</span>
                        </div>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-wrapper" for="termsCheckbox">
                        <input type="checkbox" id="termsCheckbox" name="remember" {{ $password ? 'checked' : '' }}>
                        <span class="custom-checkbox"></span>
                        <span class="checkbox-label">{{ translate('messages.remember_me') }}</span>
                    </label>
                    @if (($role ?? 'admin') === 'admin')
                        <button type="button" class="forgot-link" id="openForgetModal">
                            {{ translate('Forget_Password') }}?
                        </button>
                    @endif
                </div>

                <button type="submit" class="btn-primary" id="signInBtn" tabindex="4">
                    {{ translate('messages.sign_in') }}
                </button>
            </form>

            <div class="copyright">
                {{ translate('Manage_your_app_&_website_easily') }}<br>
                © {{ date('Y') }} {{ $app_name ?? '哪吒外卖' }}
            </div>
        </div>
    </div>
</div>

{{-- 忘记密码 Modal --}}
@if (($role ?? 'admin') === 'admin')
<div class="modal-backdrop" id="forgetPassModal">
    <div class="modal-box">
        <button type="button" class="modal-close" data-close>×</button>
        <div class="modal-icon">✉️</div>
        <h4>{{ translate('Send_Mail_to_Your_Email_?') }}</h4>
        <p>{{ translate('A_mail_will_be_send_to_your_registered_email_with_a_link_to_change_passowrd') }}</p>
        <a class="btn-primary" style="display:block;text-decoration:none;text-align:center;" href="{{ route('reset-password') }}">
            {{ translate('Send_Mail') }}
        </a>
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
// 沿混天绫轨迹生成额外光晕粒子: 上方横向混天绫对角线 + 下方斜向混天绫
// 密度向轨迹中心倾斜(核心带密 / 远处稀), 大小随距离衰减; 右下死区跳过
// 治本方案: 不再用手写直线公式(跟 CSS rotate 后的真实线条对不齐),
// 而是直接采样 SVG 混天绫导引线的【真实渲染屏幕坐标】(getBoundingClientRect, 跨浏览器都含 CSS 变换),
// 粒子沿线条法向小幅散开 → 一定贴着线条, 永远不会飘到左上角空白处。
(function genRibbonHaloParticles(){
    var container = document.querySelector('.particles');
    if (!container) return;
    function rand(min, max){ return Math.random()*(max-min)+min; }
    function pick(arr){ return arr[Math.floor(Math.random()*arr.length)]; }

    var SVGNS = 'http://www.w3.org/2000/svg';
    // 采样导引路径在屏幕上的真实像素坐标: 临时放 circle → 读 getBoundingClientRect 中心 → 删
    function sampleScreen(pathId, n){
        var path = document.getElementById(pathId);
        if (!path) return [];
        var svg = path.ownerSVGElement;
        var len = path.getTotalLength();
        var tmp = [];
        for (var i = 0; i < n; i++){
            var p = path.getPointAtLength(len * i / (n - 1));
            var c = document.createElementNS(SVGNS, 'circle');
            c.setAttribute('cx', p.x); c.setAttribute('cy', p.y);
            c.setAttribute('r', '0.5'); c.setAttribute('fill', 'none');
            svg.appendChild(c); tmp.push(c);
        }
        var pts = tmp.map(function(c){
            var r = c.getBoundingClientRect();
            return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
        });
        tmp.forEach(function(c){ c.remove(); });
        return pts;
    }

    var crect = container.getBoundingClientRect();
    function toLeftPct(x){ return (x - crect.left) / crect.width * 100; }
    function toBottomPct(y){ return (1 - (y - crect.top) / crect.height) * 100; }

    // 在屏幕点 (sx,sy) 附近沿法向撒粒子; offBias 大=密度更集中在线上
    function spawnAt(sx, sy, nx, ny, opts){
        var leftPct = toLeftPct(sx);
        var bottomPct = toBottomPct(sy);
        // 边界 + 死区
        if (bottomPct < 3 || bottomPct > 97 || leftPct < 1 || leftPct > 99) return;
        if (leftPct > 52 && bottomPct < 18) return;            // 右下死区(卡片下方)
        // 左上死区: 下方斜向混天绫会爬进左上角(实采样 L21B52/L30B78), 用户要求左上清空 → 砍掉这截
        if (leftPct < 40 && bottomPct > 50) return;
        var div = document.createElement('div');
        var mag = Math.pow(Math.random(), 1.7) * (opts.maxOff || 34); // px, 集中在小→贴线
        var near = mag < 9, mid = mag < 20;
        var variant = near
            ? pick(['particle','particle','particle-gold','particle','particle-gold'])
            : (mid ? pick(['particle','particle-slow','particle','particle-gold'])
                   : pick(['particle','particle-slow','particle']));
        div.className = variant;
        var size = near ? Math.floor(rand(3,7)) : (mid ? Math.floor(rand(2,5)) : Math.floor(rand(2,3)));
        var dx = Math.floor(rand(14, 28));
        var dy = Math.floor(rand(40, 60));
        var dur = (rand(6, 12)).toFixed(1);
        var delay = (rand(0, 5)).toFixed(1);
        div.style.cssText =
            'left:'+leftPct.toFixed(2)+'%;bottom:'+bottomPct.toFixed(2)+'%;'+
            'width:'+size+'px;height:'+size+'px;'+
            'animation-duration:'+dur+'s;animation-delay:'+delay+'s;'+
            '--dx:'+dx+'px;--dy:-'+dy+'px;--dx2:'+(dx*2)+'px;--dy2:-'+(dy*2)+'px;';
        container.appendChild(div);
    }

    // 沿一条导引线撒粒子: perSample=每个采样点撒几颗
    function seedAlong(pathId, samples, perSample, opts){
        var pts = sampleScreen(pathId, samples);
        if (!pts.length) return;
        for (var i = 0; i < pts.length; i++){
            var prev = pts[Math.max(0, i-1)], next = pts[Math.min(pts.length-1, i+1)];
            var tx = next.x - prev.x, ty = next.y - prev.y;
            var tl = Math.hypot(tx, ty) || 1;
            var nx = -ty / tl, ny = tx / tl;            // 法向单位向量
            var alongJit = (opts.along || 14);          // 沿线方向抖动(避免成珠链)
            for (var k = 0; k < perSample; k++){
                var perpSign = Math.random() < 0.5 ? -1 : 1;
                var perpMag = Math.pow(Math.random(), 1.7) * (opts.maxOff || 34);
                var aj = rand(-alongJit, alongJit);
                var sx = pts[i].x + nx * perpMag * perpSign + (tx/tl) * aj;
                var sy = pts[i].y + ny * perpMag * perpSign + (ty/tl) * aj;
                spawnAt(sx, sy, nx, ny, opts);
            }
        }
    }

    function run(){
        crect = container.getBoundingClientRect();
        // 上方横向混天绫(从左下斜穿到右上, 是视觉主角): 用户要"周边再加粒子" → 高密度 (44 采样点 × 5 颗)
        seedAlong('guideUpper', 44, 5, { maxOff: 38, along: 16 });
        // 下方斜向混天绫: 适度 (28 采样点 × 2 颗); 爬进左上的那截被死区自动砍掉
        seedAlong('guideLower', 28, 2, { maxOff: 30, along: 14 });
    }

    // SVG 需排版完成才有正确 getBoundingClientRect
    if (document.readyState === 'complete') { run(); }
    else { window.addEventListener('load', run); }
    // 窗口尺寸变化重算(响应式; 防 resize 后粒子跑偏)
    var rT;
    window.addEventListener('resize', function(){
        clearTimeout(rT);
        rT = setTimeout(function(){
            container.querySelectorAll('div').forEach(function(d){ d.remove(); });
            run();
        }, 300);
    });
})();

(function(){
    // 显示/隐藏密码
    var pwd = document.getElementById('signupSrPassword');
    var toggle = document.getElementById('togglePass');
    var eyeIcon = document.getElementById('eyeIcon');
    if (toggle && pwd) {
        toggle.addEventListener('click', function(){
            if (pwd.type === 'password') {
                pwd.type = 'text';
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                pwd.type = 'password';
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        });
    }

    // Modal 开关
    var openBtn = document.getElementById('openForgetModal');
    var modal = document.getElementById('forgetPassModal');
    if (openBtn && modal) {
        openBtn.addEventListener('click', function(){ modal.classList.add('show'); });
    }
    document.querySelectorAll('[data-close]').forEach(function(btn){
        btn.addEventListener('click', function(){
            btn.closest('.modal-backdrop').classList.remove('show');
        });
    });
    document.querySelectorAll('.modal-backdrop').forEach(function(m){
        m.addEventListener('click', function(e){
            if (e.target === m) m.classList.remove('show');
        });
    });

    // 刷新验证码
    var captchaLoading = false;
    document.addEventListener('click', function(e){
        var trigger = e.target.closest('.reloadCaptcha');
        if (!trigger || captchaLoading) return;
        captchaLoading = true;
        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        fetch("{{ route('reload-captcha') }}", {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token }
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            var wrap = document.getElementById('reload-captcha');
            if (wrap && data.view) {
                // 服务端返回的是 bootstrap 旧版 markup；只抽出 captcha 图片
                var tmp = document.createElement('div');
                tmp.innerHTML = data.view;
                var img = tmp.querySelector('img');
                var newImg = wrap.querySelector('.captcha-img-wrap img');
                if (img && newImg) {
                    newImg.src = img.getAttribute('src');
                    var inputEl = wrap.querySelector('input[name="custome_recaptcha"]');
                    if (inputEl) inputEl.value = '';
                }
            }
        })
        .catch(function(){ /* 静默失败 */ })
        .finally(function(){ captchaLoading = false; });
    });
})();
</script>
</body>
</html>
