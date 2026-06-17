<?php

namespace App\Http\Controllers\Vendor;

use App\Models\WithdrawRequest;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\CentralLogics\Helpers;

class SystemController extends Controller
{
    public function dashboard()
    {
        $withdraw_req=WithdrawRequest::where('vendor_id',Helpers::get_restaurant_id())->latest()->paginate(10);
        return view('vendor-views.dashboard', compact('withdraw_req'));
    }

    public function restaurant_data()
    {
        // 哪吒: 该方法当前无路由引用(商家端实际用 DashboardController::restaurant_data, 见 e2633db);
        // 保留并对齐口径防未来误用 —— 计数与侧栏徽标同口径(Eloquent + Notpos + NotDigitalOrder), 只取未读 checked=0,
        // 不用裸 DB::table 报幽灵数(离线待付款单会被列表 NotDigitalOrder 隐藏)。
        $new_order = \App\Models\Order::where('checked', 0)->where('restaurant_id', Helpers::get_restaurant_id())->Notpos()->NotDigitalOrder()->count();
        return response()->json([
            'success' => 1,
            'data' => ['new_order' => $new_order]
        ]);
    }
}
