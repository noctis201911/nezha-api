<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 B方案 组4 — 商家端「预存佣金余额」+「低额邮件告警」自助设置.
 * 商家可查看自己的预存佣金余额/流水, 并自选 低于多少 触发告警 + 告警发到哪个邮箱。
 */
class NezhaDepositController extends Controller
{
    public function index()
    {
        $vendorId = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->first();

        $wallet = RestaurantWallet::where('vendor_id', $vendorId)->first();
        $balance = (float) ($wallet->deposit_balance ?? 0);

        $transactions = RestaurantDepositTransaction::where('vendor_id', $vendorId)
            ->orderByDesc('id')->paginate(20);

        // 结算汇率(与全站顾客折算同源, 仅用于把余额展示成 ≈¥/≈$; 缺省回退默认值)
        $rateCny = (float) (\Illuminate\Support\Facades\DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
        $rateUsd = (float) (\Illuminate\Support\Facades\DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);

        return view('vendor-views.nezha-deposit.index', compact('restaurant', 'balance', 'transactions', 'rateCny', 'rateUsd'));
    }

    public function update_alert(Request $request)
    {
        $request->validate([
            'deposit_alert_enabled'   => 'nullable|boolean',
            'deposit_alert_threshold' => 'nullable|numeric|min:0',
            'deposit_alert_email'     => 'nullable|email',
        ]);

        $vendorId = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->firstOrFail();

        $enabled = (int) ($request->deposit_alert_enabled ? 1 : 0);
        // 开启告警时, 阈值与邮箱必填
        if ($enabled) {
            if ($request->deposit_alert_threshold === null || $request->deposit_alert_threshold === '') {
                Toastr::error(translate('开启告警需填写余额阈值'));
                return back();
            }
            if (empty($request->deposit_alert_email)) {
                Toastr::error(translate('开启告警需填写接收邮箱'));
                return back();
            }
        }

        // 直接赋属性(Restaurant 模型 fillable 很严, 绕过 mass-assign)
        $restaurant->deposit_alert_enabled = $enabled;
        $restaurant->deposit_alert_threshold = ($request->deposit_alert_threshold === null || $request->deposit_alert_threshold === '') ? null : (float) $request->deposit_alert_threshold;
        $restaurant->deposit_alert_email = $request->deposit_alert_email ?: null;
        // 阈值/邮箱变更后清冷却, 让新设置下次检查能即时触发
        $restaurant->deposit_alert_last_sent_at = null;
        $restaurant->save();

        Toastr::success(translate('告警设置已保存'));
        return back();
    }
}
