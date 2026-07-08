<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 商户面板登录门：未登录跳登录页；账号被停用则强制登出。
 * 作用域全程锚定登录账号（merchant_id 从会话账号取，绝不从请求参数取 → 结构性防 IDOR）。
 */
class EnsureLocalMerchant
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('local_merchant');

        if (!$guard->check()) {
            return redirect()->route('local-merchant.login');
        }

        $account = $guard->user();
        if (!$account || !$account->status) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('local-merchant.login')->with('error', '账号已被停用，请联系平台。');
        }

        return $next($request);
    }
}
