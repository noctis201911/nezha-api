<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class APIGuestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if($request->header('Authorization') && $request->header('Authorization') !== 'Bearer null' && app('auth')->guard('api')) {
            $resolvedUser = auth('api')->user();
            // 哪吒[F-2 观测 2026-07-23]: 只记不拦。Authorization 头存在且非 'Bearer null', 但 token 解析成 null(过期/被撤销/伪造)时,
            // 该请求会静默降级为游客。先埋点量化真实发生量(跑数天拿基线), 再决定是否收紧本中间件。绝不在此处拒绝——收紧是
            // 收入路径, 若有真实顾客拿过期 token 在用, 收紧当场即"下不了单"事故。仅日志, path/ip 不含 token 明文。
            if ($resolvedUser === null) {
                try {
                    \Illuminate\Support\Facades\Log::warning('[apiGuestCheck] token-present-but-null', [
                        'path'   => $request->path(),
                        'method' => $request->getMethod(),
                        'ip'     => $request->ip(),
                    ]);
                } catch (\Throwable $e) {
                    // 观测不得影响请求
                }
            }
            $request->merge(['user' => $resolvedUser]);
            return $next($request);
        }elseif($request->guest_id){
            return $next($request);
        }

        return response()->json([
            'errors' => [
                ['code' => 'auth-001', 'message' => 'Unauthorized.']
            ]
        ], 401);
    }
}
