<?php

namespace App\Http\Controllers\LocalMerchant;

use App\Http\Controllers\Controller;
use App\Models\LocalLifeMerchantAccount;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 本地生活商户轻管理面 —— 鉴权（自包含，不碰 StackFood 共享 LoginController）。
 * 邮箱自助设密/找回复用 Laravel 密码 broker（local_merchants）。
 */
class AuthController extends Controller
{
    private const GUARD  = 'local_merchant';
    private const BROKER = 'local_merchants';

    public function showLogin()
    {
        if (Auth::guard(self::GUARD)->check()) {
            return redirect()->route('local-merchant.home');
        }
        return view('local_merchant.auth.login');
    }

    public function login(Request $request)
    {
        $request->validate(
            ['email' => 'required|email', 'password' => 'required|string'],
            [],
            ['email' => '邮箱', 'password' => '密码']
        );

        $key = 'llm-login:' . Str::lower((string) $request->input('email')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => '尝试过于频繁，请约 ' . max(1, (int) ceil($seconds / 60)) . ' 分钟后再试。',
            ]);
        }

        $ok = Auth::guard(self::GUARD)->attempt([
            'email'    => $request->input('email'),
            'password' => $request->input('password'),
        ], $request->boolean('remember'));

        if (!$ok) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => '邮箱或密码不正确。']);
        }

        $account = Auth::guard(self::GUARD)->user();
        if (!$account->status) {
            Auth::guard(self::GUARD)->logout();
            throw ValidationException::withMessages(['email' => '账号已被停用，请联系平台。']);
        }

        RateLimiter::clear($key);
        $account->forceFill(['last_login_at' => now()])->save();
        $request->session()->regenerate();

        return redirect()->intended(route('local-merchant.home'));
    }

    public function logout(Request $request)
    {
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('local-merchant.login');
    }

    public function showForgot()
    {
        return view('local_merchant.auth.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => 'required|email'], [], ['email' => '邮箱']);

        $key = 'llm-forgot:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => '请求过于频繁，请稍后再试。']);
        }
        RateLimiter::hit($key, 900);

        Password::broker(self::BROKER)->sendResetLink(['email' => (string) $request->input('email')]);

        // 中性提示：不泄露该邮箱是否已注册
        return back()->with('status', '若该邮箱已注册，我们已发送设置密码的邮件，请查收（含垃圾箱）。');
    }

    public function showReset(Request $request, string $token)
    {
        return view('local_merchant.auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate(
            [
                'token'    => 'required',
                'email'    => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ],
            [],
            ['password' => '密码']
        );

        $status = Password::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (LocalLifeMerchantAccount $account, string $password) {
                $account->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($account));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('local-merchant.login')->with('status', '密码已设置，请用新密码登录。');
        }

        throw ValidationException::withMessages([
            'email' => '链接无效或已过期，请重新在「忘记密码」申请设置链接。',
        ]);
    }
}
