<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 N+1 / 慢查询护栏 (2026-06-17 性能QA系统化收口).
 *
 * 单台生产服务器、无独立 dev/staging, 故不能用 Model::preventLazyLoading()(只在非生产生效=这里没处跑,
 * 且 APP_ENV 万一配错会全站抛异常). 改用「数每个 API 请求发了多少条 SQL, 超阈值只记 warning 不抛」——
 * 可安全常驻生产, 把 N+1 回归在日志里暴露出来(grep '[N+1-guard]'), 而不是等用户体感到慢才发现.
 *
 * 设计:
 *  - 仅挂 api 组(顾客端), 不碰 admin/vendor 面板(仪表盘正常就多查询, 会误报).
 *  - 永不抛异常(记日志包 try/catch), 失败也不影响响应 —— fail-safe.
 *  - DB::listen 只 +1 计数, 不存查询文本 => 内存/CPU 开销可忽略.
 *  - 阈值默认 120, 可用 .env NEZHA_QUERY_WARN_THRESHOLD 调(config:cache 后 env 读不到则回落默认值).
 *  - 阈值 <=0 时整体关闭(留一个总开关).
 */
class QueryCountGuard
{
    public function handle(Request $request, Closure $next)
    {
        $threshold = (int) (env('NEZHA_QUERY_WARN_THRESHOLD') ?: 120);
        if ($threshold <= 0) {
            return $next($request);
        }

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $response = $next($request);

        try {
            if ($count > $threshold) {
                Log::warning('[N+1-guard] high query count', [
                    'count'     => $count,
                    'threshold' => $threshold,
                    'method'    => $request->getMethod(),
                    'path'      => $request->path(),
                    'route'     => optional($request->route())->getName(),
                ]);
            }
        } catch (\Throwable $e) {
            // 护栏自身永不影响请求
        }

        return $response;
    }
}
