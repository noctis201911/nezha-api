<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;

/**
 * 本地生活商户轻管理面总闸（服务端强制·默认关 dormant）。
 * nezha_local_merchant_selfserve_status != 1 → 整个 /m 面板 404（含登录页），业主翻闸才生效。
 */
class LocalMerchantFeatureGate
{
    public function handle(Request $request, Closure $next)
    {
        if ((int) Helpers::get_business_settings('nezha_local_merchant_selfserve_status') !== 1) {
            abort(404);
        }
        return $next($request);
    }
}
