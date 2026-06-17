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

        $data = [
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
