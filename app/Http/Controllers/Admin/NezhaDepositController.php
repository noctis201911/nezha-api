<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 B方案 组4 — 商家预存佣金(充值/扣佣)管理.
 * 一览各商家预存佣金余额、累计充值/扣佣/退还, 并支持管理员记录线下充值.
 * 资金性质: 商家预存佣金=合法 B2B(平台向商家收佣的预付), 非顾客资金, 不涉二清(L1-1/L1-5)。
 */
class NezhaDepositController extends Controller
{
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

        $query = Restaurant::query()
            ->leftJoin('restaurant_wallets as rw', 'rw.vendor_id', '=', 'restaurants.vendor_id')
            ->when($search, fn ($q) => $q->where('restaurants.name', 'like', "%{$search}%"))
            ->select('restaurants.*')
            ->selectRaw('COALESCE(rw.deposit_balance,0) as bal')
            ->selectRaw("{$rechargeSub} as total_recharge")
            ->selectRaw("{$deductSub} as total_deduction")
            ->selectRaw("{$reversalSub} as total_reversal")
            ->selectRaw("{$lastSub} as last_recharge");

        $sortMap = [
            'name'          => 'restaurants.name',
            'balance'       => 'bal',
            'recharge'      => 'total_recharge',
            'deduction'     => 'total_deduction',
            'reversal'      => 'total_reversal',
            'last_recharge' => 'last_recharge',
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
        ];

        return view('admin-views.nezha-deposit.index', compact('restaurants', 'summary', 'search', 'sort', 'dir'));
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
        $vendorId = $restaurant->vendor_id;

        try {
            DB::beginTransaction();
            // 行锁防并发(与扣佣同口径), 读最新余额后累加
            $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
            if (!$wallet) {
                $wallet = new RestaurantWallet();
                $wallet->vendor_id = $vendorId;
                $wallet->deposit_balance = 0;
                $wallet->save();
                $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
            }
            $newBalance = (float) ($wallet->deposit_balance ?? 0) + (float) $request->amount;
            $wallet->deposit_balance = $newBalance;
            $wallet->save();

            RestaurantDepositTransaction::create([
                'vendor_id'     => $vendorId,
                'restaurant_id' => $restaurant->id,
                'order_id'      => null,
                'type'          => 'recharge',
                'amount'        => (float) $request->amount,
                'commission'    => 0,
                'balance_after' => $newBalance,
                'note'          => $request->note ?: '管理员记录充值',
                'created_by'    => auth('admin')->id(),
            ]);
            DB::commit();
            Toastr::success(translate('充值已入账, 商家预存佣金已增加'));
        } catch (\Throwable $e) {
            DB::rollBack();
            info('nezha deposit recharge failed: ' . $e->getMessage());
            Toastr::error(translate('充值入账失败, 请重试'));
        }

        return back();
    }
}
