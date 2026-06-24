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
        // 哪吒 QA[看板年度图表改造]: B方案商家直收全额, 平台不按单抽佣(佣金走保证金扣)。
        // 原图表读 OrderTransaction.restaurant_amount/admin_commission = StackFood 扣佣净额模型, 在 B方案下误导
        // (且 demo 直插单无 OT 行致严重低估)。改为按月聚合真实「已收款营业额」, 与今日经营卡 today_collected 同源
        // (Order.payment_status=paid + 排除终态负向单)。$commission/$delivery_earning 保留空数组(视图已不再使用)。
        $earning = [];
        $commission = [];
        $delivery_earning = [];
        $from = Carbon::now()->startOfYear()->format('Y-m-d 00:00:00');
        $to = Carbon::now()->endOfYear()->format('Y-m-d 23:59:59');
        $monthly_sales = Order::where('restaurant_id', Helpers::get_restaurant_id())
            ->where('payment_status', 'paid')
            ->whereNotIn('order_status', ['canceled', 'failed', 'refunded'])
            ->Notpos()
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('IFNULL(sum(order_amount),0) as sales'), DB::raw('MONTH(created_at) month'))
            ->groupBy('month')->get()->toArray();
        for ($inc = 1; $inc <= 12; $inc++) {
            $earning[$inc] = 0;
            foreach ($monthly_sales as $match) {
                if ($match['month'] == $inc) {
                    $earning[$inc] = $match['sales'];
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

        // 哪吒 M-01: 首屏工作台 —— 待办行动条 + 今日经营卡, 与轮询端点 restaurant_data() 同源(nezha_todo_counts)。
        $nz_todo  = self::nezha_todo_counts();
        $nz_today = self::nezha_today_summary();

        return view('vendor-views.dashboard', compact('data', 'earning', 'commission', 'params','delivery_earning','out_out_count','food','nz_todo','nz_today'));
    }

    public function restaurant_data()
    {
        // 哪吒 M-01: 待办计数收口到单一同源方法, 供轮询(JSON)与首屏(dashboard 视图)共用,
        // 杜绝"首屏数字 vs 轮询数字"两套口径打架(参考 [[nezha-merchant-sees-offline-order]])。
        return response()->json([
            'success' => 1,
            'data'    => self::nezha_todo_counts(),
        ]);
    }

    /**
     * 哪吒 M-01: 商家待办行动条计数(同源唯一出口)。
     * 全部复用既有作用域/口径, 不新建状态机、不改任何业务规则(L3 只读聚合)。
     * 返回: 待确认收款/待处理/已确认/待退款/超时/配送催办 各计数 + 跳转落点 key。
     */
    private function nezha_todo_counts()
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

        // 哪吒 M-02: 超时提醒(系统/面板渠道)的计数与 ID 收口到 NezhaOrderTimeout::alertOrderIds()
        // ——只读聚合·单一口径, 与 OrderController::list('timeout') 过滤完全同源,
        // 保证「超时卡数字」==「点进去 /list/timeout 列表条数」(根除 M-01 过渡期"卡数 vs 列表数"漂移)。
        // 集合 = offline_pending(已传凭证且超时) + confirmed(待接单超时) + processing(备餐超时) 三类并集,
        // 已排除「未传凭证待付款单」(等顾客付款, 商家无可为)与 info 级未到阈值单。
        $timeout_ids   = \App\CentralLogics\NezhaOrderTimeout::alertOrderIds($rid);
        $timeout_total = count($timeout_ids);

        // 哪吒 M-02: timeout_target 仅供 vendor 布局超时弹窗(app.blade.php「去处理」按钮 location.href=listBase+toTarget)落点,
        // 统一指向虚拟过滤 /list/timeout —— 与超时卡同源同落点。(之前取"第一条超时单的桶名"会落到无过滤的整桶甚至全部单,
        // 如 processing 落 /list/processing=全部单, 与卡不一致; 现对齐。timeout_target 现仅此一个消费方。)
        $timeout_target = 'timeout';

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

        // 哪吒 M-01[待退款]: 与侧栏徽标/列表过滤同源 —— 本店有 pending_merchant_refund 留痕的单
        // (平台已取消/退款, 等商家原路退)。口径=订单存在的 nezha_refund_records.status=pending_merchant_refund,
        // 与 _sidebar.blade.php 角标 + OrderController::list('refund_pending') 完全一致。
        // 注意: 这是 B方案"待商家原路退"(refund_pending), 不是原生"顾客向平台申请退款"(refund_requested), 两者语义不同勿混。
        $refund_pending = \App\Models\Order::where('restaurant_id', $rid)
            ->whereIn('id', \App\Models\NezhaRefundRecord::where('status', 'pending_merchant_refund')->pluck('order_id'))
            ->count();

        // 哪吒 M-02: 超时卡落点已改为虚拟过滤 /list/timeout(同源), M-01 过渡 hack timeout_list_map/timeout_list_key 已删。

        return [
            'new_pending_order'    => $new_pending_order,
            'new_confirmed_order'  => $new_confirmed_order,
            'new_offline_order'    => $new_offline_order,
            'new_total'            => $target_ids->count(),
            'new_order_ids'        => $target_ids->values(),
            'target'               => $target,
            'target_label'         => $target_label,
            'timeout_total'        => $timeout_total,
            'timeout_order_ids'    => $timeout_ids,
            'timeout_target'       => $timeout_target,
            'deliv_link_total'     => $deliv_link_total,
            'deliv_link_order_ids' => $deliv_link_ids,
            'refund_pending'       => $refund_pending,
        ];
    }

    /**
     * 哪吒 M-01: 今日经营卡数据(今日订单数 / 今日已确认到账 / 保证金健康四档 / 店铺评分累计)。
     * 全部 L3 只读聚合, 口径已拍板(见规格 §0.1/§5):
     *  - 今日已确认到账: payment_status='paid'(离线确认收款会原子写 paid+offline_payments.verified,
     *    在线已付同样 paid); 自然排除"已传凭证未确认"(仍 unpaid), 不误导商家。再排除终态负向单(退款/取消/失败)。
     *  - 保证金健康: 与接单闸 nezha_deposit_below_threshold 同源(开关 nezha_deposit_mode_status + 下线阈值
     *    nezha_min_deposit_threshold), 不显精确 N 天。
     *  - 店铺评分: Restaurant::withAvg/withCount('reviews') 累计, 与顾客端餐厅页同源, 不造"今日评分"维度。
     */
    private function nezha_today_summary()
    {
        $restaurant = Helpers::get_restaurant_data();
        $rid = $restaurant?->id;
        $vendorId = Helpers::get_vendor_id();

        $today_orders = Order::whereDate('created_at', Carbon::today())
            ->where('restaurant_id', $rid)->Notpos()->count();

        $today_collected = (float) Order::whereDate('created_at', Carbon::today())
            ->where('restaurant_id', $rid)
            ->where('payment_status', 'paid')
            ->whereNotIn('order_status', ['canceled', 'failed', 'refunded'])
            ->Notpos()->sum('order_amount');

        // 保证金余额 + 健康四档
        $balance = (float) (\App\Models\RestaurantWallet::where('vendor_id', $vendorId)->value('deposit_balance') ?? 0);
        $mode = \App\Models\BusinessSetting::where('key', 'nezha_deposit_mode_status')->first()?->value;
        $threshold = (float) (\App\Models\BusinessSetting::where('key', 'nezha_min_deposit_threshold')->first()?->value ?? 0);
        $hasHistory = \App\Models\RestaurantDepositTransaction::where('vendor_id', $vendorId)->exists();

        if ($mode != 1 || ! $hasHistory) {
            // 未启用佣金预存扣佣 / 无扣佣历史 → 无从评估健康, 诚实显"样本不足", 不伪造"充足"。
            $deposit_tier = 'sample';
        } elseif ($balance <= $threshold) {
            // 已达下线阈值 → 可能无法接新单(与接单闸 nezha_deposit_below_threshold 同源)。
            $deposit_tier = 'insufficient';
        } else {
            // 偏低线: 用商家自设的低额告警阈值(deposit_alert_threshold)作"偏低"区; 未设则只分充足/不足。
            $alertT = ($restaurant && $restaurant->deposit_alert_enabled && $restaurant->deposit_alert_threshold !== null)
                ? (float) $restaurant->deposit_alert_threshold : null;
            $deposit_tier = ($alertT !== null && $balance <= $alertT) ? 'low' : 'sufficient';
        }

        // 店铺评分(累计) — 与顾客端餐厅页同源
        $rating_agg = \App\Models\Restaurant::where('id', $rid)
            ->withAvg('reviews', 'rating')->withCount('reviews')->first();
        $rating_avg = round((float) ($rating_agg->reviews_avg_rating ?? 0), 1);
        $rating_count = (int) ($rating_agg->reviews_count ?? 0);

        // ≈¥/≈$ 折算汇率(与商家端保证金页/全站顾客折算同源, 缺省回退)
        $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
        $rateUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);

        return [
            'today_orders'    => $today_orders,
            'today_collected' => $today_collected,
            'deposit_balance' => $balance,
            'deposit_tier'    => $deposit_tier,
            'rating_avg'      => $rating_avg,
            'rating_count'    => $rating_count,
            'rate_cny'        => $rateCny,
            'rate_usd'        => $rateUsd,
        ];
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
