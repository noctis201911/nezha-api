<?php

namespace App\Http\Middleware;

use App\CentralLogics\Helpers;
use Brian2694\Toastr\Facades\Toastr;
use Closure;

class ModulePermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next, $module)
    {
        if (auth('admin')->check() && Helpers::module_permission_check($module)) {
            return $next($request);
        }
        else if (auth('vendor_employee')->check() || auth('vendor')->check()) {
            if(Helpers::employee_module_permission_check($module))
            {
                return $next($request);
            }
        }

        Toastr::error(translate('messages.access_denied'));
        if (auth('admin')->check()) {
            // 安全(P2): admin 无权限落正式品牌化中文 403 页, 不糊回上一页
            return response()->view('admin-views.errors.no-permission', ['module' => $module], 403);
        }
        return back();
    }
}
