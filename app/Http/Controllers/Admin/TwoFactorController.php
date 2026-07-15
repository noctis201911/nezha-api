<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\NezhaTotp;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PaymentAddressReviewerScopeMiddleware;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * 哪吒 - Admin 后台两步验证 (TOTP)。
 * 安全设计: 全程 opt-in, 默认关闭; 必须扫码+输入有效码确认后才会启用 ->
 * 部署后线上登录行为零变化, 不会把管理员锁在外面。
 * 应急: 若认证器丢失, 用 SSH 跑 `php artisan nezha:2fa-disable <email>` 关闭。
 */
class TwoFactorController extends Controller
{
    private const ISSUER = 'Nezha Admin';

    /** 设置页(已登录管理员) */
    public function setup(Request $request)
    {
        $admin = auth('admin')->user();

        if ($admin->two_factor_enabled) {
            // 已启用: 只显示状态 + 关闭表单; 若刚启用则把一次性恢复码闪现
            return view('admin-views.two-factor.setup', [
                'enabled'        => true,
                'recovery_codes' => session('2fa:recovery_plain'),
            ]);
        }

        // 未启用: 生成新密钥存 session(确认前不落库)
        $secret = NezhaTotp::generateSecret();
        $request->session()->put('2fa:setup_secret', $secret);

        $label = $admin->email ?: ('admin-' . $admin->id);
        $uri = NezhaTotp::otpauthUri($secret, $label, self::ISSUER);
        $qrSvg = base64_encode(QrCode::format('svg')->size(220)->margin(1)->generate($uri));

        return view('admin-views.two-factor.setup', [
            'enabled' => false,
            'secret'  => $secret,
            'qr_svg'  => $qrSvg,
        ]);
    }

    /** 启用(校验一个验证码再落库) */
    public function enable(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $admin = auth('admin')->user();
        $secret = $request->session()->get('2fa:setup_secret');

        if (!$secret) {
            return redirect()->route('admin.two-factor.setup')
                ->withErrors(['code' => translate('messages.session_expired_please_retry') ?: '会话过期, 请重试']);
        }
        if (!NezhaTotp::verify($secret, $request->code)) {
            return redirect()->route('admin.two-factor.setup')
                ->withErrors(['code' => '验证码错误, 请用认证器最新的6位码重试']);
        }

        // 生成 8 个一次性恢复码
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4))); // 8 位 hex
            $plain[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
            $hashed[] = Hash::make($code);
        }

        $admin->two_factor_secret = $secret;
        $admin->two_factor_enabled = true;
        $admin->two_factor_recovery_codes = $hashed;
        $admin->save();

        $request->session()->forget('2fa:setup_secret');
        $request->session()->flash('2fa:recovery_plain', $plain);
        // 刚用有效码启用 = 本会话已过第二因子, 标记它, 否则 AdminMiddleware 硬门会立刻登出
        // 并把恢复码 flash 吃掉(用户就再也看不到恢复码了)。
        $request->session()->put('2fa_passed', true);

        return redirect()->route('admin.two-factor.setup');
    }

    /** 关闭(需重输当前密码确认本人操作) */
    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        $admin = auth('admin')->user();

        if (!Hash::check($request->password, $admin->password)) {
            return redirect()->route('admin.two-factor.setup')
                ->withErrors(['password' => '密码不正确, 未关闭两步验证']);
        }

        $admin->two_factor_secret = null;
        $admin->two_factor_enabled = false;
        $admin->two_factor_recovery_codes = null;
        $admin->save();

        return redirect()->route('admin.two-factor.setup')
            ->with('2fa:disabled', true);
    }

    /** 登录第二步: 挑战页(此时未完成登录, 仅 session 有 pending id) */
    public function challenge(Request $request)
    {
        if (!$request->session()->get('2fa:pending_admin_id')) {
            return redirect()->route('login', ['tab' => $this->adminLoginUrl()]);
        }
        return view('auth.two-factor-challenge', [
            'site_direction' => session('site_direction', 'ltr'),
            'locale'         => session('local', 'en'),
        ]);
    }

    /** 登录第二步: 校验 TOTP 或恢复码 */
    public function verifyChallenge(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $key = '2fa-challenge:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return redirect()->route('admin.2fa.challenge')
                ->withErrors(['code' => '尝试次数过多, 请 ' . ceil($seconds / 60) . ' 分钟后再试']);
        }

        $id = $request->session()->get('2fa:pending_admin_id');
        $admin = $id ? Admin::find($id) : null;
        if (!$admin || !$admin->two_factor_enabled) {
            return redirect()->route('login', ['tab' => $this->adminLoginUrl()]);
        }

        $ok = NezhaTotp::verify((string) $admin->two_factor_secret, $request->code)
            || $this->consumeRecoveryCode($admin, $request->code);

        if (!$ok) {
            RateLimiter::hit($key, 120);
            return redirect()->route('admin.2fa.challenge')
                ->withErrors(['code' => '验证码或恢复码错误']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->get('2fa:remember', false);
        auth('admin')->loginUsingId($admin->id, $remember);
        // 本会话已通过第二因子: AdminMiddleware 硬门据此放行(下次新会话/新 cookie 仍会重新挑战)。
        $request->session()->put('2fa_passed', true);
        $request->session()->forget(['2fa:pending_admin_id', '2fa:remember']);

        return redirect()->route(
            PaymentAddressReviewerScopeMiddleware::isReviewer($admin)
                ? 'admin.payment-address-review.pending'
                : 'admin.dashboard'
        );
    }

    /** 比对并消耗一个一次性恢复码 */
    private function consumeRecoveryCode(Admin $admin, string $input): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if ($normalized === '') {
            return false;
        }
        $codes = $admin->two_factor_recovery_codes ?: [];
        foreach ($codes as $idx => $hash) {
            if (Hash::check($normalized, $hash)) {
                unset($codes[$idx]);
                $admin->two_factor_recovery_codes = array_values($codes);
                $admin->save();
                return true;
            }
        }
        return false;
    }

    private function adminLoginUrl(): string
    {
        return \App\Models\DataSetting::where('key', 'admin_login_url')->value('value') ?: 'admin';
    }
}
