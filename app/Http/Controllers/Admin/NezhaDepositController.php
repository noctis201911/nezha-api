<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Brian2694\Toastr\Facades\Toastr;
use App\CentralLogics\NezhaDepositLedger;

/**
 * 哪吒 B方案 组4 — 商家预存佣金(充值/扣佣)管理 + 组⑤ 押金账户(缴纳/档位核对).
 * 一览各商家预存佣金余额、累计充值/扣佣/退还, 并支持管理员记录线下充值。
 * 押金(guarantee): 独立可退质押账户(wallet.guarantee_balance), 超管据实入账法币缴纳 + 按应缴档核对缺口。
 * 资金性质: 商家预存佣金/押金=合法 B2B(平台向商家收的佣金预付 / 商家自有质押), 非顾客资金, 不涉二清(L1-1/L1-5)。
 *   押金红线 L1-8: ①法币-only(不收 USDT) ②退还只退缴纳主体本人 KYC 账户(本控制器只管缴纳方向, 退还在 offboard 结算).
 */
class NezhaDepositController extends Controller
{
    /** 押金应缴档枚举 -> 人民币金额(元)。'exempt'=豁免。见 docs/PLAN_merchant_offboard.md §7-①。 */
    public const GUARANTEE_TIERS_CNY = [
        'exempt' => 0,
        '500'    => 500,
        '1000'   => 1000,
        '5000'   => 5000,
    ];

    /** CNY→AMD 折算率(与全站顾客折算同源; 缺则回退 55)。 */
    private function rateCny(): float
    {
        return (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
    }

    // 充值佣金一览(支持按 当前余额/累计充值/扣佣/退还/上次充值/商家名 排序)
    public function index(Request $request)
    {
        $search = $request->get('search');
        $sort = $request->get('sort', 'name');
        $dir = strtolower($request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $rechargeSub = "(SELECT COALESCE(SUM(amount),0) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='recharge')";
        $deductSub   = "(SELECT COALESCE(SUM(commission),0) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='commission_deduction')";
        $reversalSub = "(SELECT COALESCE(SUM(commission),0) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='refund_reversal')";
        $lastSub     = "(SELECT MAX(created_at) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='recharge')";
        // 押金(guarantee): 累计缴纳 / 上次缴纳时间(独立账户, 不与预存佣金混算)
        $guaranteeSub     = "(SELECT COALESCE(SUM(amount),0) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='guarantee_deposit')";
        $lastGuaranteeSub = "(SELECT MAX(created_at) FROM restaurant_deposit_transactions d WHERE d.vendor_id = restaurants.vendor_id AND d.type='guarantee_deposit')";

        $query = Restaurant::query()
            ->leftJoin('restaurant_wallets as rw', 'rw.vendor_id', '=', 'restaurants.vendor_id')
            ->when($search, fn ($q) => $q->where('restaurants.name', 'like', "%{$search}%"))
            ->select('restaurants.*')
            ->selectRaw('COALESCE(rw.deposit_balance,0) as bal')
            ->selectRaw('COALESCE(rw.guarantee_balance,0) as g_bal')
            ->selectRaw("{$rechargeSub} as total_recharge")
            ->selectRaw("{$deductSub} as total_deduction")
            ->selectRaw("{$reversalSub} as total_reversal")
            ->selectRaw("{$lastSub} as last_recharge")
            ->selectRaw("{$guaranteeSub} as total_guarantee_paid")
            ->selectRaw("{$lastGuaranteeSub} as last_guarantee");

        $sortMap = [
            'name'          => 'restaurants.name',
            'balance'       => 'bal',
            'recharge'      => 'total_recharge',
            'deduction'     => 'total_deduction',
            'reversal'      => 'total_reversal',
            'last_recharge' => 'last_recharge',
            'guarantee'     => 'g_bal',
        ];
        $sortCol = $sortMap[$sort] ?? 'restaurants.name';
        $query->orderBy(DB::raw($sortCol), $dir);
        if ($sort !== 'name') {
            $query->orderBy('restaurants.name', 'asc'); // 次级稳定排序
        }

        $restaurants = $query->paginate(25)->appends($request->all());

        $summary = [
            'total_balance'   => (float) RestaurantWallet::sum('deposit_balance'),
            'negative_count'  => RestaurantWallet::where('deposit_balance', '<', 0)->count(),
            'total_recharge'  => (float) RestaurantDepositTransaction::where('type', 'recharge')->sum('amount'),
            'total_deduction' => (float) RestaurantDepositTransaction::where('type', 'commission_deduction')->sum('commission'),
            'total_guarantee' => (float) RestaurantWallet::sum('guarantee_balance'),
        ];

        return view('admin-views.nezha-deposit.index', [
            'restaurants' => $restaurants,
            'summary'     => $summary,
            'search'      => $search,
            'sort'        => $sort,
            'dir'         => $dir,
            'rateCny'     => $this->rateCny(),
            'tiersCny'    => self::GUARANTEE_TIERS_CNY,
        ]);
    }

    // 预存佣金流水
    public function transactions(Request $request)
    {
        $restaurant_id = $request->get('restaurant_id');
        $type = $request->get('type');
        $transactions = RestaurantDepositTransaction::with('restaurant')
            ->when($restaurant_id, fn ($q) => $q->where('restaurant_id', $restaurant_id))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(30)
            ->appends($request->all());
        $restaurants = Restaurant::orderBy('name')->get(['id', 'name']);

        return view('admin-views.nezha-deposit.transactions', compact('transactions', 'restaurants', 'restaurant_id', 'type'));
    }

    // 记录一笔线下充值(商家把预存佣金打给平台后, 运营在此入账)
    public function store_recharge(Request $request)
    {
        $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',
            'amount'        => 'required|numeric|min:0.01',
            'note'          => 'nullable|string|max:255',
        ]);

        $restaurant = Restaurant::findOrFail($request->restaurant_id);

        if (\App\CentralLogics\NezhaOffboard::is_frozen($restaurant)) {
            Toastr::error(translate('该商家正在办理退出结算, 结算期间不可变动预存佣金'));
            return back();
        }

        try {
            DB::beginTransaction();
            // 入账走单一落点 NezhaDepositLedger(与自助充值审核流 S3 共用, 不造第二套); 事务由本方法开启
            NezhaDepositLedger::recordRecharge(
                $restaurant,
                (float) $request->amount,
                $request->note,
                auth('admin')->id()
            );
            DB::commit();
            Toastr::success(translate('充值已入账, 商家预存佣金已增加'));
        } catch (\Throwable $e) {
            DB::rollBack();
            info('nezha deposit recharge failed: ' . $e->getMessage());
            Toastr::error(translate('充值入账失败, 请重试'));
        }

        return back();
    }

    /**
     * 记录一笔押金缴纳(商家把押金以法币打给平台后, 超管据实入账)。
     * 对称 store_recharge, 但:
     *  - 币种 currency 限法币 AMD/CNY(L1-8① 平台不持任何加密资产, 拒 USDT);
     *  - 记原币种/原额/回执号(L1-4 留痕), 入账 amount 一律为 AMD 折算单值(按当刻汇率), 与预存佣金账目口径一致;
     *  - 走 guarantee_balance 独立子余额, 绝不动 deposit_balance/ad_balance(INV-1 / L1-8④ 资金隔离)。
     */
    public function store_guarantee(Request $request)
    {
        $request->validate([
            'restaurant_id'   => 'required|exists:restaurants,id',
            'currency'        => 'required|in:AMD,CNY',           // 法币-only, 拒 USDT(L1-8①)
            'original_amount' => 'required|numeric|min:0.01',      // 商家实际缴纳的原币金额
            'original_ref'    => 'required|string|max:255',        // 缴纳回执/凭证号(L1-4 留痕)
            'note'            => 'nullable|string|max:255',
        ]);

        $restaurant = Restaurant::findOrFail($request->restaurant_id);

        if (\App\CentralLogics\NezhaOffboard::is_frozen($restaurant)) {
            Toastr::error(translate('该商家正在办理退出结算, 结算期间不可缴纳押金'));
            return back();
        }

        // 入账 AMD 折算单值: CNY 按当刻汇率折算, AMD 原样(避免手填 amount 与回执原额分叉)
        $amount = $this->guaranteeAmountAmd($request->currency, (float) $request->original_amount, $this->rateCny());
        if ($amount < 0.01) {
            Toastr::error(translate('折算后入账金额过小, 请检查原额与币种'));
            return back();
        }

        try {
            DB::beginTransaction();
            NezhaDepositLedger::recordGuaranteeDeposit(
                $restaurant,
                $amount,
                $request->currency,
                (float) $request->original_amount,
                $request->original_ref,
                $request->note,
                auth('admin')->id()
            );
            DB::commit();
            Toastr::success(translate('押金已入账, 商家押金余额已增加'));
        } catch (\Throwable $e) {
            DB::rollBack();
            info('nezha guarantee deposit failed: ' . $e->getMessage());
            Toastr::error(translate('押金入账失败, 请重试'));
        }

        return back();
    }

    /** 押金原币折 AMD 入账值: CNY×汇率, AMD 原样(均四舍五入到分)。 */
    private function guaranteeAmountAmd(string $currency, float $originalAmount, float $rateCny): float
    {
        return $currency === 'CNY'
            ? round($originalAmount * $rateCny, 2)
            : round($originalAmount, 2);
    }

    /**
     * 设定/调整商家押金应缴档(L2 业务参数, 非 L1)。留痕: 写系统日志(旧->新+操作人)。
     * 档位口径见 GUARANTEE_TIERS_CNY; 供押金档位核对(应缴/实缴/缺口)使用。
     */
    public function set_tier(Request $request)
    {
        $request->validate([
            'restaurant_id'  => 'required|exists:restaurants,id',
            'guarantee_tier' => 'required|in:' . implode(',', array_keys(self::GUARANTEE_TIERS_CNY)),
        ]);

        $restaurant = Restaurant::findOrFail($request->restaurant_id);
        $old = $restaurant->guarantee_tier;
        // Restaurant 模型 fillable 很严, 直接赋属性绕过 mass-assign
        $restaurant->guarantee_tier = $request->guarantee_tier;
        $restaurant->save();

        Log::info('nezha guarantee tier set', [
            'restaurant_id' => $restaurant->id,
            'vendor_id'     => $restaurant->vendor_id,
            'old'           => $old,
            'new'           => $request->guarantee_tier,
            'by_admin'      => auth('admin')->id(),
        ]);

        Toastr::success(translate('押金应缴档已更新'));
        return back();
    }
}
