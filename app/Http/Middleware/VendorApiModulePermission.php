<?php

namespace App\Http\Middleware;

use App\Models\VendorEmployee;
use Closure;
use Illuminate\Http\Request;

/**
 * 哪吒 P-3: vendor API 员工模块鉴权。
 * 必须挂在 vendor.api(VendorTokenIsValid) 之后: owner(无 vendor_employee 上下文)放行;
 * 受限员工按其 employee_role.modules 校验, 不含该模块 → JSON 403。
 * 仅挂在 owner 级敏感接口(收款账户/提现/提现方式)上, 防员工经 App API 绕过 web 模块限制(P-3)。
 */
class VendorApiModulePermission
{
    public function handle(Request $request, Closure $next, $module)
    {
        $employee = $request['vendor_employee'] ?? null;
        // owner / 无员工上下文 → 完整权限(instanceof 防 query 参数伪造)
        if (!$employee instanceof VendorEmployee) {
            return $next($request);
        }
        $modules = (array) json_decode($employee->role?->modules ?? '[]');
        if (in_array($module, $modules, true)) {
            return $next($request);
        }
        return response()->json([
            'errors' => [
                ['code' => 'permission_denied', 'message' => translate('messages.access_denied')],
            ],
        ], 403);
    }
}