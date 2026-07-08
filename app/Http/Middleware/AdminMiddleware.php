<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\CentralLogics\Helpers;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('admin')->check()) {
            // 哪吒 2FA 硬门: 已登录管理员若开了两步验证, 但本会话尚未通过第二因子, 强制回挑战页。
            // 关键: 这同时堵住「记住我(remember-me) cookie 自动登录绕过 LoginController::submit() 的
            // 一次性接线」—— 那条路径不经过登录表单, 只有这里的门能拦住。
            $admin = Auth::guard('admin')->user();
            if ($admin && $admin->two_factor_enabled && !$request->session()->get('2fa_passed')) {
                Auth::guard('admin')->logout();
                $request->session()->put('2fa:pending_admin_id', $admin->id);
                return redirect()->route('admin.2fa.challenge');
            }
            return $next($request);
        }
        return redirect()->route('login', ['admin']);
    }
}