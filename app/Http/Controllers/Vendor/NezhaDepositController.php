<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use App\Exports\NezhaReconciliationExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 B方案 — 商家端「对账中心」.
 * 把商家与平台之间的 B2B 资金往来按「账户」分开对账 + 导出:
 *   - 预存佣金账户(deposit_balance): recharge / commission_deduction / refund_reversal / advertisement_fee
 *   - 广告账户(ad_balance): ad_recharge / ad_click_fee
 * 每个账户各自的 balance_after 是独立运行余额, 绝不混列(修正历史无 type 过滤的串味隐患)。
 * 押金账户为独立可退质押账户, 尚未建, 前端灰置占位。
 * 纯 L3 只读呈现 + 导出, 不碰任何资金机制(L1)。也含商家自助「低额邮件告警」设置。
 */
class NezhaDepositController extends Controller
{
    /** 账户 -> 归属该账户的流水 type(按每个 type 影响哪个余额划分) */
    private const ACCOUNTS = [
        'deposit' => ['recharge', 'commission_deduction', 'refund_reversal', 'advertisement_fee'],
        'ad'      => ['ad_recharge', 'ad_click_fee'],
    ];

    private function accountTypes(string $account): array
    {
        return self::ACCOUNTS[$account] ?? self::ACCOUNTS['deposit'];
    }

    private function normalizeAccount($account): string
    {
        return in_array($account, ['deposit', 'ad'], true) ? $account : 'deposit';
    }

    /** 解析日期范围(默认本月至今, 埃里温本地时区; 非法输入回退, from>to 自动对调) */
    private function resolveRange(Request $request): array
    {
        $tz = 'Asia/Yerevan';
        try {
            $fromC = $request->filled('from') ? Carbon::parse($request->get('from'), $tz)->startOfDay() : Carbon::now($tz)->startOfMonth();
        } catch (\Throwable $e) {
            $fromC = Carbon::now($tz)->startOfMonth();
        }
        try {
            $toC = $request->filled('to') ? Carbon::parse($request->get('to'), $tz)->endOfDay() : Carbon::now($tz)->endOfDay();
        } catch (\Throwable $e) {
            $toC = Carbon::now($tz)->endOfDay();
        }
        if ($toC->lt($fromC)) {
            $tmp   = $fromC->copy()->startOfDay();
            $fromC = $toC->copy()->startOfDay();
            $toC   = $tmp->endOfDay();
        }
        return [$fromC, $toC];
    }

    /**
     * 锚定「当前真实账户余额」往回推算期初/期末, 保证对账页与余额卡永远一致:
     *   期末 = 当前余额 − 区间结束后的净变动; 期初 = 期末 − 区间内净变动。
     * 即便历史流水不全(如种子直接写余额没留流水), 也不会出现"余额≠期末"的矛盾。
     */
    private function anchoredBounds(int $vendorId, array $types, float $currentBalance, Carbon $toC, float $sumInRange): array
    {
        $sumAfterTo = (float) RestaurantDepositTransaction::where('vendor_id', $vendorId)
            ->whereIn('type', $types)
            ->where('created_at', '>', $toC)->sum('amount');
        $closing = $currentBalance - $sumAfterTo;
        $opening = $closing - $sumInRange;
        return [$opening, $closing];
    }

    /** 按 type 汇总区间内 amount(带符号) */
    private function sumsByType(int $vendorId, array $types, Carbon $fromC, Carbon $toC): array
    {
        $raw = RestaurantDepositTransaction::where('vendor_id', $vendorId)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$fromC, $toC])
            ->select('type', DB::raw('SUM(amount) as s'))
            ->groupBy('type')->pluck('s', 'type');
        $out = [];
        foreach ($raw as $t => $s) {
            $out[$t] = (float) $s;
        }
        return $out;
    }

    public function index(Request $request)
    {
        $vendorId   = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->first();
        $wallet     = RestaurantWallet::where('vendor_id', $vendorId)->first();

        $account = $this->normalizeAccount($request->get('account'));
        [$fromC, $toC] = $this->resolveRange($request);
        $types = $this->accountTypes($account);

        $depositBalance = (float) ($wallet->deposit_balance ?? 0);
        $adBalance      = (float) ($wallet->ad_balance ?? 0);
        $currentBalance = $account === 'ad' ? $adBalance : $depositBalance;

        $byType  = $this->sumsByType($vendorId, $types, $fromC, $toC);
        [$opening, $closing] = $this->anchoredBounds($vendorId, $types, $currentBalance, $toC, array_sum($byType));

        $transactions = RestaurantDepositTransaction::where('vendor_id', $vendorId)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$fromC, $toC])
            ->orderByDesc('id')
            ->paginate(20)
            ->appends(['account' => $account, 'from' => $fromC->toDateString(), 'to' => $toC->toDateString()]);

        // 结算汇率(与全站顾客折算同源; 仅展示成 ≈¥/≈$, 不碰钱)
        $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
        $rateUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);

        return view('vendor-views.nezha-deposit.index', [
            'restaurant'     => $restaurant,
            'account'        => $account,
            'depositBalance' => $depositBalance,
            'adBalance'      => $adBalance,
            'opening'        => $opening,
            'closing'        => $closing,
            'byType'         => $byType,
            'transactions'   => $transactions,
            'from'           => $fromC->toDateString(),
            'to'             => $toC->toDateString(),
            'rateCny'        => $rateCny,
            'rateUsd'        => $rateUsd,
        ]);
    }

    /** 导出当前账户+区间的对账单(Excel/CSV) */
    public function export(Request $request)
    {
        $vendorId   = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->first();

        $wallet  = RestaurantWallet::where('vendor_id', $vendorId)->first();
        $account = $this->normalizeAccount($request->get('account'));
        [$fromC, $toC] = $this->resolveRange($request);
        $types = $this->accountTypes($account);
        $currentBalance = $account === 'ad' ? (float) ($wallet->ad_balance ?? 0) : (float) ($wallet->deposit_balance ?? 0);

        $rows = RestaurantDepositTransaction::where('vendor_id', $vendorId)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$fromC, $toC])
            ->orderBy('id')  // 升序: 对账单从期初往后读
            ->get();
        [$opening, $closing] = $this->anchoredBounds($vendorId, $types, $currentBalance, $toC, (float) $rows->sum('amount'));

        $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
        $rateUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);

        $data = [
            'account'         => $account,
            'account_label'   => $account === 'ad' ? translate('广告账户') : translate('预存佣金账户'),
            'restaurant_name' => $restaurant->name ?? '',
            'from'            => $fromC->toDateString(),
            'to'             => $toC->toDateString(),
            'opening'         => $opening,
            'closing'         => $closing,
            'rows'            => $rows,
            'rate_cny'        => $rateCny,
            'rate_usd'        => $rateUsd,
        ];

        $type = $request->get('type') === 'csv' ? 'csv' : 'xlsx';
        $slug = $account === 'ad' ? 'ad' : 'deposit';
        $fname = 'reconciliation_' . $slug . '_' . $fromC->toDateString() . '_' . $toC->toDateString() . '.' . $type;

        return Excel::download(new NezhaReconciliationExport($data), $fname);
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
