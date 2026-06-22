<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\OrderTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function dashboard(Request $request)
    {

        if(auth('vendor')->check()){
            Vendor::where('id', Helpers::get_vendor_id())->update(['current_language_key' => app()->getLocale() ?? 'en']);
        }

        $params = [
            'statistics_type' => $request['statistics_type'] ?? 'overall'
        ];
        session()->put('dash_params', $params);

        $data = self::dashboard_order_stats_data();
        $earning = [];
        $commission = [];
        $delivery_earning= [];
        $from = Carbon::now()->startOfYear()->format('Y-m-d');
        $to = Carbon::now()->endOfYear()->format('Y-m-d');
        $restaurant_earnings = OrderTransaction::NotRefunded()->where(['vendor_id' => Helpers::get_vendor_id()])->select(
            DB::raw('IFNULL(sum(restaurant_amount),0) as earning'),
            DB::raw('IFNULL(sum(admin_commission + admin_expense),0) as commission'),
            DB::raw('IFNULL(sum(delivery_charge),0) as delivery_earning'),
            DB::raw('YEAR(created_at) year, MONTH(created_at) month'),
        )->whereBetween('created_at', [$from, $to])->groupby('year', 'month')->get()->toArray();
        // dd($restaurant_earnings);
        for ($inc = 1; $inc <= 12; $inc++) {
            $earning[$inc] = 0;
            $commission[$inc] = 0;
            $delivery_earning[$inc] = 0;
            foreach ($restaurant_earnings as $match) {
                if ($match['month'] == $inc) {
                    $earning[$inc] = $match['earning'];
                    $commission[$inc] = $match['commission'];
                    $delivery_earning[$inc] = $match['delivery_earning'];
                }
            }
        }


        $top_sell = Food::where('order_count','>',0)
        ->orderBy("order_count", 'desc')
        ->take(6)
        ->get();
        $most_rated_foods = Food::where('rating_count','>',0)
        ->orderBy('rating_count','desc')
        ->take(6)
        ->get();
        $data['top_sell'] = $top_sell;
        $data['most_rated_foods'] = $most_rated_foods;


        $out_out_count =  Food::where('stock_type','!=' ,'unlimited' )->where(function($query){
            $query->whereRaw('item_stock - sell_count <= 0')->orWhereHas('newVariationOptions',function($query){
                $query->whereRaw('total_stock - sell_count <= 0');
            });
            })->count();

            $food = null;
            if($out_out_count == 1 ){
                $food = Food::where('stock_type','!=' ,'unlimited' )->where(function($query){
                    $query->whereRaw('item_stock - sell_count <= 0')->orWhereHas('newVariationOptions',function($query){
                        $query->whereRaw('total_stock - sell_count <= 0');
                    });
                    })->first();
            }

        return view('vendor-views.dashboard', compact('data', 'earning', 'commission', 'params','delivery_earning','out_out_count','food'));
    }

    public function restaurant_data()
    {
        $restaurant = Helpers::get_restaurant_data();
        $rid = $restaurant?->id;

        $data = 0;
        if (($restaurant->restaurant_model == 'subscription'  && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1)) {
            $data = 1;
        }

        // 哪吒: 横幅计数逐字复刻侧栏徽标口径(_sidebar.blade.php), 只取未读 checked=0, 保证横幅集合 ⊆ 徽标可见集合,
        // 根除"横幅报数/列表却空"的幽灵单死胡同(原用裸 DB::table 不套 NotDigitalOrder 等作用域)。
        // 待确认收款(离线已传凭证待核验)
        $offline_ids = \App\Models\Order::where(['order_status' => 'pending', 'payment_method' => 'offline_payment', 'restaurant_id' => $rid])
            ->where('checked', 0)
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->Notpos()->HasSubscriptionToday()->pluck('id')->values();

        // 待处理
        $pending_q = \App\Models\Order::where(['order_status' => 'pending', 'restaurant_id' => $rid])
            ->where('checked', 0)
            ->Notpos()->NotDigitalOrder()->HasSubscriptionToday()->OrderScheduledIn(30);
        if (!(config('order_confirmation_model') == 'restaurant' || $data)) {
            $pending_q = $pending_q->whereIn('order_type', ['take_away', 'dine_in']);
        }
        $pending_ids = $pending_q->pluck('id')->values();

        // 已确认
        $confirmed_ids = \App\Models\Order::whereIn('order_status', ['confirmed'])
            ->where(['restaurant_id' => $rid])->where('checked', 0)->whereNotNull('confirmed')
            ->NotDigitalOrder()->Notpos()->HasSubscriptionToday()->OrderScheduledIn(30)->pluck('id')->values();

        $new_offline_order   = $offline_ids->count();
        $new_pending_order   = $pending_ids->count();
        $new_confirmed_order = $confirmed_ids->count();

        // 智能落点 + 计数对齐: 横幅一次只聚焦"最该先处理"的那个非空桶, 计数与未读id 都取该桶,
        // 保证横幅数字 == 点进去那个列表的单数(避免再现"横幅数 vs 列表数对不上")。优先级: 离线待收款(B方案主流) > 待处理 > 已确认。
        $target = 'pending'; $target_label = '待处理'; $target_ids = collect([]);
        if ($new_offline_order > 0)       { $target = 'offline_pending'; $target_label = '待收款'; $target_ids = $offline_ids; }
        elseif ($new_pending_order > 0)   { $target = 'pending';         $target_label = '待处理'; $target_ids = $pending_ids; }
        elseif ($new_confirmed_order > 0) { $target = 'confirmed';       $target_label = '待处理'; $target_ids = $confirmed_ids; }

        // 哪吒: 超时提醒(系统/面板渠道)——独立于「新订单」横幅, 专门兜住现有横幅照不到的「备餐超时」等;
        // 复用 NezhaOrderTimeout::describe 的 severity(warning/error)判定是否需商家立即处理,
        // 排除「未上传凭证的待确认收款单」(那是等顾客付款, 商家无可为)。
        $timeout_alerts = [];
        try {
            $open = \App\Models\Order::with(['offline_payments'])
                ->where('restaurant_id', $rid)
                ->whereIn('order_status', ['pending', 'confirmed', 'processing'])
                ->Notpos()->get();
            foreach ($open as $o) {
                $phase = \App\CentralLogics\NezhaOrderTimeout::phase($o);
                if (! $phase) { continue; }
                if ($phase === \App\CentralLogics\NezhaOrderTimeout::PHASE_PROOF
                    && ! \App\CentralLogics\NezhaOrderTimeout::hasProofImage($o)) { continue; }
                $d = \App\CentralLogics\NezhaOrderTimeout::describe($o);
                if (! $d || ($d['severity'] ?? 'info') === 'info') { continue; }
                $bucket = 'pending';
                if ($phase === \App\CentralLogics\NezhaOrderTimeout::PHASE_ACCEPT)    { $bucket = 'confirmed'; }
                elseif ($phase === \App\CentralLogics\NezhaOrderTimeout::PHASE_PREP)  { $bucket = 'processing'; }
                elseif ($phase === \App\CentralLogics\NezhaOrderTimeout::PHASE_PROOF) { $bucket = 'offline_pending'; }
                $timeout_alerts[] = [
                    'order_id' => $o->id,
                    'minutes'  => (int) ($d['elapsed_minutes'] ?? 0),
                    'bucket'   => $bucket,
                ];
            }
        } catch (Throwable $e) {
            IlluminateSupportFacadesLog::warning('NEZHA_TIMEOUT panel alert: ' . $e->getMessage());
        }
        $timeout_total  = count($timeout_alerts);
        $timeout_ids    = array_map(function ($a) { return $a['order_id']; }, $timeout_alerts);
        $timeout_target = $timeout_total > 0 ? $timeout_alerts[0]['bucket'] : 'pending';

        // 哪吒[配送链接催办 2026-06-22]: 顾客在追踪页戳「提醒商家分享配送进度」后, 原仅发 Telegram(多数商家未配=失声)
        // + 订单详情页一个被动徽标(商家不会主动回看已推「配送中」的单)。这里接进商家本就在盯的面板轮询渠道:
        // 配送单已到 picked_up(配送中) 且顾客已催 且商家还没贴 Yandex 链接 → 响铃+浮窗叫到商家(贴上链接即消)。
        $deliv_link_ids = \App\Models\Order::where('restaurant_id', $rid)
            ->where('order_type', 'delivery')
            ->where('order_status', 'picked_up')
            ->whereNotNull('delivery_link_reminded_at')
            ->where(function ($q) { $q->whereNull('yandex_tracking_url')->orWhere('yandex_tracking_url', ''); })
            ->Notpos()->pluck('id')->values();
        $deliv_link_total = $deliv_link_ids->count();

        return response()->json([
            'success' => 1,
            'data' => [
                'new_pending_order'   => $new_pending_order,
                'new_confirmed_order' => $new_confirmed_order,
                'new_offline_order'   => $new_offline_order,
                'new_total'           => $target_ids->count(),
                'new_order_ids'       => $target_ids->values(),
                'target'              => $target,
                'target_label'        => $target_label,
                'timeout_total'       => $timeout_total,
                'timeout_order_ids'   => $timeout_ids,
                'timeout_target'      => $timeout_target,
                'deliv_link_total'    => $deliv_link_total,
                'deliv_link_order_ids'=> $deliv_link_ids,
            ]
        ]);
    }

    public function order_stats(Request $request)
    {
        $params = session('dash_params');
        foreach ($params as $key => $value) {
            if ($key == 'statistics_type') {
                $params['statistics_type'] = $request['statistics_type'];
            }
        }
        session()->put('dash_params', $params);

        $data = self::dashboard_order_stats_data();
        return response()->json([
            'view' => view('vendor-views.partials._dashboard-order-stats', compact('data'))->render()
        ], 200);
    }

    public function dashboard_order_stats_data()
    {
        $params = session('dash_params');
        $today = $params['statistics_type'] == 'today' ? 1 : 0;
        $this_month = $params['statistics_type'] == 'this_month' ? 1 : 0;
        $restaurant =Helpers::get_restaurant_data();

        $confirmed = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['restaurant_id' => $restaurant?->id])->whereIn('order_status',['confirmed', 'accepted'])->whereNotNull('confirmed')->OrderScheduledIn(30)->Notpos()->count();

        $cooking = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['order_status' => 'processing', 'restaurant_id' => $restaurant?->id])->Notpos()->count();

        $ready_for_delivery = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['order_status' => 'handover', 'restaurant_id' => $restaurant?->id])->Notpos()->count();

        $food_on_the_way = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->FoodOnTheWay()->where(['restaurant_id' => $restaurant?->id])->Notpos()->count();

        $delivered = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['order_status' => 'delivered', 'restaurant_id' => $restaurant?->id])->Notpos()->count();

        $refunded = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['order_status' => 'refunded', 'restaurant_id' => $restaurant?->id])->Notpos()->count();

        $data =0;
        if (($restaurant->restaurant_model == 'subscription'  && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1) ){
            $data =1;
        }

        $scheduled = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->Scheduled()->where(['restaurant_id' => $restaurant?->id])->where(function($query) use($data){
            $query->Scheduled()->where(function($q) use($data){
                if(config('order_confirmation_model') == 'restaurant' || $data){
                    $q->whereNotIn('order_status',['failed','canceled', 'refund_requested', 'refunded']);
                }
                else{
                    $q->whereNotIn('order_status',['pending','failed','canceled', 'refund_requested', 'refunded'])->orWhere(function($query){
                        $query->where('order_status','pending')->where('order_type', 'take_away');
                    });
                }
            });

        })->Notpos()->count();

        $all = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['restaurant_id' => $restaurant?->id])
        ->where(function($query) use($data){
            return $query->whereNotIn('order_status',(config('order_confirmation_model') == 'restaurant'|| $data)?['failed','canceled', 'refund_requested', 'refunded']:['pending','failed','canceled', 'refund_requested', 'refunded'])
            ->orWhere(function($query){
                return $query->where('order_status','pending')->where('order_type', 'take_away');
            });
        })
        ->Notpos()->count();

        $offline_pending = Order::when($today, function ($query) {
            return $query->whereDate('created_at', Carbon::today());
        })->when($this_month, function ($query) {
            return $query->whereMonth('created_at', Carbon::now());
        })->where(['order_status' => 'pending', 'payment_method' => 'offline_payment', 'restaurant_id' => $restaurant?->id])
        ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })->Notpos()->count();

        $data = [
            'offline_pending' => $offline_pending,
            'confirmed' => $confirmed,
            'cooking' => $cooking,
            'ready_for_delivery' => $ready_for_delivery,
            'food_on_the_way' => $food_on_the_way,
            'delivered' => $delivered,
            'refunded' => $refunded,
            'scheduled' => $scheduled,
            'all' => $all,
        ];

        return $data;
    }

    public function updateDeviceToken(Request $request)
    {
        $vendor = Vendor::find(Helpers::get_vendor_id());
        $vendor->fcm_token_web =  $request->token;
        $vendor->save();

        return response()->json(['Token successfully stored.']);
    }
}
