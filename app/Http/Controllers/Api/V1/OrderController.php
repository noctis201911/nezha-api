<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\ZoneDeliveryOption;
use App\Traits\PlaceNewOrder;
use Carbon\Carbon;
use App\Models\Cart;
use App\Models\Food;
use App\Models\User;
use App\Models\Zone;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Review;
use App\Mail\PlaceOrder;
use App\Models\DMReview;
use App\Models\Restaurant;
use App\Mail\RefundRequest;
use App\Models\OrderDetail;
use App\Models\ItemCampaign;
use App\Models\RefundReason;
use App\Models\Subscription;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\OrderReference;
use App\Models\BusinessSetting;
use App\Models\CashBackHistory;
use App\Models\OfflinePayments;
use App\CentralLogics\OrderLogic;
use App\Models\OrderCancelReason;
use App\CentralLogics\CouponLogic;
use Illuminate\Support\Facades\DB;
use App\Mail\OrderVerificationMail;
use App\CentralLogics\CustomerLogic;
use App\Http\Controllers\Controller;
use App\Models\OfflinePaymentMethod;
use App\Models\SubscriptionSchedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use MatanYadaev\EloquentSpatial\Objects\Point;

class OrderController extends Controller
{
    use PlaceNewOrder;
    public function track_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
            'contact_number' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];

        if (isset($request['contact_number'])) {
            $contact = trim($request['contact_number']);
            if (substr($contact, 0, 1) !== '+') {
                $contact = '+' . $contact;
            }
            $request['contact_number'] = $contact;
        }

        $order = Order::with(['restaurant','restaurant.restaurant_sub', 'refund', 'delivery_man', 'delivery_man.rating','subscription','payments','OrderReference'])->withCount('details')->where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!$request->user, function ($query) use ($request) {
                return $query->whereJsonContains('delivery_address->contact_person_number', $request['contact_number']);
            })
            ->Notpos()->first();

        if($order){
            // 哪吒B方案: 完成/送达元数据 + 收据(必须在 restaurant 被格式化为数组、offline_payments 被 unset 之前, 关系仍为模型时计算)
            $order['nezha_completion'] = OrderLogic::completion_meta($order);
            $order['nezha_receipt'] = OrderLogic::receipt_meta($order);
            $order['restaurant'] = $order['restaurant'] ? Helpers::restaurant_data_formatting($order['restaurant']): $order['restaurant'];
            $order['delivery_address'] = $order['delivery_address']?json_decode($order['delivery_address'],true):$order['delivery_address'];
            $order['delivery_man'] = $order['delivery_man']?Helpers::deliverymen_data_formatting([$order['delivery_man']]):$order['delivery_man'];
            $order['offline_payment'] =  isset($order->offline_payments) ? Helpers::offline_payment_formater($order->offline_payments) : null;
            $order['is_reviewed'] =   $order->details_count >  Review::whereOrderId($request->order_id)->count() ? False :True ;
            $order['is_dm_reviewed'] =  $order?->delivery_man ? DMReview::whereOrderId($order->id)->exists()  : True ;

            if($order->subscription){
                $order->subscription['delivered_count']= (int) $order->subscription->logs()->whereOrderStatus('delivered')->count();
                $order->subscription['canceled_count']= (int) $order->subscription->logs()->whereOrderStatus('canceled')->count();
            }

            unset($order['offline_payments']);
            unset($order['details']);
        } else{
            return response()->json([
                'errors' => [
                    ['code' => 'order_not_found', 'message' => translate('messages.Order_not_found')]
                ]
            ], 404);
        }
        $order['saver_delivery_time'] = $this->get_saver_delivery_time($order);
        // 哪吒B方案: 商家出餐(handover)后向顾客暴露取餐号(复用订单otp; 顾客下单时已经 OrderVerificationMail 收到该码作配送验证码, 非新增泄露)。出餐前为空字符串, 前端显示"等待商家出餐"。供顾客自取或交给 Yandex 骑手到店向商家核对。
        $order['pickup_code'] = in_array($order->order_status, ['handover', 'picked_up', 'delivered'], true) ? (string) $order->otp : '';
        // 哪吒 B方案(QA 2026-06-18): 暴露「待退款留痕」状态给顾客,使订单页能回显退款进度(待商家退款/商家已退款)。平台不碰钱,仅展示状态。
        $nezha_rr = \App\Models\NezhaRefundRecord::where('order_id', $order->id)->whereIn('status', ['pending_merchant_refund', 'merchant_refunded'])->orderByDesc('id')->first();
        $order['nezha_refund'] = $nezha_rr ? [
            'status'        => $nezha_rr->status,
            'refund_amount' => $nezha_rr->refund_amount,
            'channel'       => $nezha_rr->payment_channel,
            'refunded'      => $nezha_rr->status === 'merchant_refunded',
        ] : null;
        // 哪吒: 顾客「接单后申请取消」状态(申请→商家裁决)。前端据此显示申请入口/申请中/被拒卡。
        $nz_cancel_stage_ok = in_array($order->order_status, ['confirmed', 'processing'], true);
        $order['nezha_cancel'] = [
            'status'        => $order->nezha_cancel_request,            // null|requested|approved|rejected
            'can_request'   => $nz_cancel_stage_ok && $order->nezha_cancel_request !== 'requested',
            'reason'        => $order->nezha_cancel_request_reason,
            'response_note' => $order->nezha_cancel_response_note,
            'requested_at'  => $order->nezha_cancel_requested_at,
            'responded_at'  => $order->nezha_cancel_responded_at,
        ];
        // 哪吒: 订单超时状态(集中规则, 见 docs/ORDER_TIMEOUT_RULES.md)。前端只渲染, 不写散落计时器。
        $order['nezha_timeout'] = \App\CentralLogics\NezhaOrderTimeout::describe($order);
        return response()->json($order, 200);
    }

    public function place_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_amount' => 'nullable', // 哪吒[资金完整性·子项B]: 前端金额永不被信任, 服务端按DB商品价重算覆盖(见下方order_amount收口); 保留键兼容旧前端但不再required
            'payment_method'=>'required|in:cash_on_delivery,digital_payment,wallet,offline_payment',
            'order_type' => 'required|in:take_away,delivery,dine_in',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric',
            'guest_id' => $request->user ? 'nullable' : 'required',
            'contact_person_name' => $request->user ? 'nullable' : 'required',
            'contact_person_number' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $order_validation_check =  $this->order_validation_check($request);

        if(data_get($order_validation_check,'status_code') === 403 ){
            return response()->json([
                'errors' => [
                    ['code' => data_get($order_validation_check,'code'), 'message' => data_get($order_validation_check,'message')]
                ]
            ], data_get($order_validation_check,'status_code'));
        } else{
            $restaurant = $order_validation_check;
        }

        // ▼ 哪吒风控① 兜底: 防止前端绕过预检直接下单. 命中 reject→拒单 / review→转人工审核, 两种都不创建订单.
        $nezha_risk_ctx = \App\Http\Controllers\Api\V1\NezhaRiskController::build_context($request);
        $nezha_risk = \App\CentralLogics\NezhaRiskControl::evaluate($nezha_risk_ctx);
        if ($nezha_risk['action'] !== 'pass') {
            \App\CentralLogics\NezhaRiskControl::record($nezha_risk_ctx, $nezha_risk);
            return response()->json([
                'errors' => [
                    ['code' => $nezha_risk['action'] === 'reject' ? 'risk_reject' : 'risk_review', 'message' => $nezha_risk['message']]
                ]
            ], 403);
        }
        // ▲ 哪吒风控① 兜底结束

        $coupon = null;
        $coupon_created_by = null;
        $delivery_charge = null;
        $free_delivery_by = null;
        $free_delivery_min_purchase = 0;

        $schedule_at = $request->schedule_at?Carbon::parse($request->schedule_at):now();

        DB::beginTransaction();

        if ($request['coupon_code']) {
            // 哪吒[券限领 race]: 事务内先对券行加排他锁, 串行化"同一券"的并发下单, 使 is_valide 的每人限领 count() 在锁内权威(防 TOCTOU 超限)。锁随 commit/rollBack 释放。
            \App\Models\Coupon::where('code', $request['coupon_code'])->lockForUpdate()->first();
            $coupon_check = Helpers::coupon_check(coupon_code:$request['coupon_code'],restaurant_id:$restaurant->id,user_id:$request?->user?->id, is_guest:$request->user ? false : true);
            if(data_get($coupon_check,'code') === 'coupon' ){
                return response()->json([
                    'errors' => [
                        ['code' => data_get($coupon_check,'code'), 'message' => data_get($coupon_check,'message')]
                    ]
                ], data_get($coupon_check,'status_code'));
            } else{
                $coupon = data_get($coupon_check,'coupon');
                $coupon_created_by = data_get($coupon_check,'coupon_created_by');
                $delivery_charge = data_get($coupon_check,'delivery_charge');
                $free_delivery_by = data_get($coupon_check,'free_delivery_by');
                $free_delivery_min_purchase = data_get($coupon_check,'free_delivery_min_purchase', 0);
            }
        }


        $claculate_original_delivery_fee= $this->claculate_original_delivery_fee(request:$request ,restaurant: $restaurant, delivery_charge:$delivery_charge, free_delivery_by:$free_delivery_by);

        $max_cod_order_amount_value = data_get($claculate_original_delivery_fee,'max_cod_order_amount_value');
        $vehicle_id = data_get($claculate_original_delivery_fee,'vehicle_id');
        $original_delivery_charge = data_get($claculate_original_delivery_fee,'original_delivery_charge');
        $delivery_charge = data_get($claculate_original_delivery_fee,'delivery_charge');

        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : ($request->user?$request->user->f_name . ' ' . $request->user->f_name:''),
            'contact_person_number' => $request->contact_person_number ?  $request->contact_person_number: $request->user?->phone,
            'contact_person_email' => $request->contact_person_email ? $request->contact_person_email : ($request->user?$request->user->email:''),
            'address_type' => $request->address_type?$request->address_type:'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string)$request->longitude,
            'latitude' => (string)$request->latitude,
        ];

        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $taxMap = [];
        $orderTaxIds = [];

        $order_details = [];


        $lastId = Order::max('id') ?? 99999;
        $order = new Order();
        $order->id = $lastId + 1;

        $order_status ='pending';
        if(($request->partial_payment && $request->payment_method != 'offline_payment') || $request->payment_method == 'wallet' ){
            $order_status ='confirmed';
        }

        $order->bring_change_amount = $request['bring_change_amount'] ?? 0 ;

        $order->distance = $request->distance ?? 0;
        $order->user_id = $request->user ? $request->user->id : $request['guest_id'];
        // 哪吒[资金完整性·子项B]: 不读前端 order_amount。先置0占位, 真值由服务端在下方按 DB 商品价/折扣/券/税/配送/小费重算后唯一赋值($order->order_amount = $order_amount + dm_tips), 入口结构上不依赖客户端值, 杜绝篡改。
        $order->order_amount = 0;
        $order->payment_status = ($request->partial_payment ? 'partially_paid' : ($request['payment_method'] == 'wallet' ? 'paid' : 'unpaid'));
        $order->order_status = $order_status;
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->partial_payment? 'partial_payment' :$request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit'))??0;
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at && $request->order_type != 'dine_in' ?1:0;
        $order->is_guest = $request->user ? 0 : 1;
        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $order->vehicle_id = $vehicle_id;
        $order->pending = now();

        if ($order_status == 'confirmed') {
            $order->confirmed = now();
        }

        $order->created_at = now();
        $order->updated_at = now();

        $order->cutlery = $request->cutlery ? 1 : 0;
        $order->unavailable_item_note = $request->unavailable_item_note ?? null ;
        $order->delivery_instruction = $request->delivery_instruction ?? null ;
        // 哪吒: 固化"谁呼叫 Yandex 配送"为结构化字段 delivery_arranger, 不再靠中文字符串判断业务(需求2)。
        //   delivery -> 当前产品新单一律商家代叫(merchant); 但接受请求显式值以便将来恢复二选一。
        //   take_away / dine_in -> null (完全不涉及 Yandex, 需求6)。
        if ($order->order_type === 'delivery') {
            // 哪吒[v6]: 平台已放弃顾客自叫, 配送单一律商家代叫 Yandex。
            $order->delivery_arranger = 'merchant';
        } else {
            $order->delivery_arranger = null;
        }

        // $order->tax_percentage = $restaurant->tax ;


        $carts = Cart::where('user_id', $order->user_id)->where('is_guest',$order->is_guest)
            // 哪吒[多店购物车]: 只取本次下单餐厅的车项,防止多店共存购物车把别家商品混入同一订单/下单后误删别家车
            ->where('restaurant_id', $request['restaurant_id'])
            ->when(isset($request->is_buy_now) && $request->is_buy_now == 1 && $request->cart_id, function ($query) use ($request) {
                return $query->where('id',$request->cart_id);
            })
            ->get()->map(function ($data) {
                $data->add_on_ids = (is_string($data->add_on_ids) ? json_decode($data->add_on_ids,true) : $data->add_on_ids) ?? [];
                $data->add_on_qtys = (is_string($data->add_on_qtys) ? json_decode($data->add_on_qtys,true) : $data->add_on_qtys) ?? [];
                $data->variations = is_string($data->variations) ? json_decode($data->variations,true) : $data->variations;
                return $data;
            });

        if(isset($request->is_buy_now) && $request->is_buy_now == 1){
            $carts = $request['cart'];
        }

        if(count($carts) == 0 ){
            return response()->json([
                'errors' => [
                    ['code' => 'empty_order', 'message' => translate('you_can_not_place_empty_order')]
                ]
            ], 403);
        }

        foreach ($carts as $c) {

            // 哪吒[轴K 数量篡改]: buy_now(活动商品)走 $request['cart'] 绕过 Cart 表的 quantity min:1 校验。
            //   此处对所有下单路径补数量下界: 必须为 >=1 的整数, 防负数/0/小数 篡改 product_price 少付。
            $nz_qty = data_get($c, 'quantity');
            if (!is_numeric($nz_qty) || floor((float) $nz_qty) != (float) $nz_qty || (int) $nz_qty < 1) {
                DB::rollBack();
                return response()->json([
                    'errors' => [
                        ['code' => 'quantity', 'message' => '所选商品数量不正确，请重新选择']
                    ]
                ], 406);
            }

            if ($c['item_type'] === 'App\Models\ItemCampaign' || $c['item_type'] === 'AppModelsItemCampaign')  {
                $product = ItemCampaign::active()->find($c['item_id']);
                $campaign_id = $c['item_id'];
                $code = 'campaign';
            } else{
                $product = Food::active()->find($c['item_id']);
                $food_id = $c['item_id'];
                $code = 'food';
            }

            if($product->restaurant_id != $request['restaurant_id']){
                return response()->json([
                    'errors' => [
                        ['code' => 'restaurant', 'message' => translate('messages.you_need_to_order_food_from_single_restaurant')],
                    ]
                ], 406);
            }

            if ($product) {
                if($product->maximum_cart_quantity && ($c['quantity'] > $product->maximum_cart_quantity)){
                    return response()->json([
                        'errors' => [
                            ['code' => 'quantity', 'message' =>$product?->name ?? $product?->title ?? $code.' '.translate('messages.has_reached_the_maximum_cart_quantity_limit')]
                        ]
                    ], 406);
                }

                $addon_data = Helpers::calculate_addon_price(addons: \App\Models\AddOn::whereIn('id', (gettype($c['add_on_ids']) == 'array' ? $c['add_on_ids'] : json_decode($c['add_on_ids'], true)) ?? [])->get(), add_on_qtys: (gettype($c['add_on_qtys']) == 'array' ? $c['add_on_qtys'] : json_decode($c['add_on_qtys'], true)) ?? []);

                if($code == 'food'){
                    $variation_options =  is_string(data_get($c,'variation_options')) ? json_decode(data_get($c,'variation_options') ,true) : [];
                    $addonAndVariationStock= Helpers::addonAndVariationStockCheck(product:$product,quantity: $c['quantity'],add_on_qtys:$c['add_on_qtys'], variation_options:$variation_options,add_on_ids:$c['add_on_ids'],incrementCount: true );
                    if(data_get($addonAndVariationStock, 'out_of_stock') != null) {
                        return response()->json([
                            'errors' => [
                                ['code' => data_get($addonAndVariationStock, 'type') ?? 'food', 'message' =>data_get($addonAndVariationStock, 'out_of_stock') ],
                            ]
                        ], 406);
                    }
                }

                $product_variations = json_decode($product->variations, true);
                $variations=[];
                if (count($product_variations)) {
                    $variation_data = Helpers::get_varient($product_variations, isset($c['variations']) ? $c['variations'] : []);
                    $price = $product['price'] + $variation_data['price'];
                    $variations = $variation_data['variations'];
                } else {
                    $price = $product['price'];
                }

                $product->tax = $restaurant->tax;
                $product = Helpers::product_data_formatting(data:$product,multi_data: false,trans: false,local: app()->getLocale(),maxDiscount:false);
                $product_discount = Helpers::food_discount_calculate($product, $price, $restaurant, false);
                $or_d = [
                    'food_id' => $food_id ??  null,
                    'item_campaign_id' => $campaign_id ?? null,
                    'food_details' => json_encode($product),
                    'quantity' => $c['quantity'],
                    'price' => round($price, config('round_up_to_digit')),
                    'category_id' => collect(is_string($product->category_ids) ? json_decode($product->category_ids, true) : $product->category_ids)->firstWhere('position', 1)['id'] ?? null,
                    'tax_amount' => 0,
                    'tax_status' => null,
                    'discount_type' => 'discount_on_product',
                    'discount_on_product_by' => 'vendor',
                    'discount_on_food' => $product_discount['discount_amount'],
                    'discount_percentage' => $product_discount['discount_percentage'],
                    'variation' => json_encode($variations),
                    'add_ons' => json_encode($addon_data['addons']),
                    'total_add_on_price' => $addon_data['total_add_on_price'],
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $order_details[] = $or_d;
                $total_addon_price += $or_d['total_add_on_price'];
                $product_price += $price*$or_d['quantity'];
                $restaurant_discount_amount += $or_d['discount_on_food']*$or_d['quantity'];

            } else {
                return response()->json([
                    'errors' => [
                        ['code' => $code ?? null, 'message' => translate('messages.product_unavailable_warning')]
                    ]
                ], 404);
            }
        }

        $order->discount_on_product_by = 'vendor';


        $discount = $restaurant_discount_amount;
        $restaurantDiscount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurantDiscount)) {
            $admin_discount = Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase']);
            $discount= $admin_discount;
             $order->discount_on_product_by = 'admin';
            foreach ($order_details as $key => $detail_data) {
                if($admin_discount>0){
                    $order_details[$key]['discount_on_product_by'] = 'admin';
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = $restaurantDiscount['discount'];
                    $order_details[$key]['discount_on_food'] =  Helpers::checkAdminDiscount(price: $product_price , discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase'], item_wise_price: $detail_data['price'] * $detail_data['quantity']);
                } else {
                    $order_details[$key]['discount_on_product_by'] = null;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = 0;
                    $order_details[$key]['discount_on_food'] =  0;
                }
            }
        }



        $restaurant_discount_amount= $discount;


        // 哪吒[券 min_purchase 强制]: coupon_check 调 CouponLogic::is_valide 时只传3参(order_amount=null), 致最低消费门槛(409)被跳过。
        //   这里按券的购物车口径金额(商品+加料-商家折扣, 与下方 get_discount 同源)补强制门槛; 低于 min_purchase 则拒绝用券(不静默放行)。
        //   注: free_delivery 型券在 coupon_check 已置 $coupon=null, 此处覆盖不到(其 min_purchase 为独立残留, 待单独修)。
        $nezha_coupon_basis = $product_price + $total_addon_price - $restaurant_discount_amount;
        if ($coupon && $coupon->min_purchase > 0 && $nezha_coupon_basis < $coupon->min_purchase) {
            DB::rollBack();
            return response()->json([
                'errors' => [
                    ['code' => 'coupon', 'message' => translate('messages.you_need_to_order_at_least').' '.$coupon->min_purchase.' '.Helpers::currency_code()]
                ]
            ], 403);
        }
        // 哪吒[免运费券 min_purchase 强制]: free_delivery 券在 coupon_check 已 nulled 且置 delivery_charge=0; 此处按购物车口径(与折扣券同源 basis)补门槛。
        //   此处 $free_delivery_by 只可能来自券(admin/vendor 免运费规则在后面 L520+ 才评估), 故可据此判定为"券免运费"。不达标直接拒绝(与折扣券一致, 不回退运费)。
        if ($free_delivery_by && $free_delivery_min_purchase > 0 && $nezha_coupon_basis < $free_delivery_min_purchase) {
            DB::rollBack();
            return response()->json([
                'errors' => [
                    ['code' => 'coupon', 'message' => translate('messages.you_need_to_order_at_least').' '.$free_delivery_min_purchase.' '.Helpers::currency_code()]
                ]
            ], 403);
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount(coupon:$coupon, order_amount: $nezha_coupon_basis) : 0;

        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount ;

        if($order->is_guest  == 0 && $order->user_id  && !($request->subscription_order && $request->subscription_quantity) ){
            $user= User::withcount('orders')->find($order->user_id);
            $discount_data= Helpers::getCusromerFirstOrderDiscount(order_count:$user->orders_count ,user_creation_date:$user->created_at,  refby:$user->ref_by, price: $total_price);
            if(data_get($discount_data,'is_valid') == true &&  data_get($discount_data,'calculated_amount') > 0){
                $total_price = $total_price - data_get($discount_data,'calculated_amount');
                $order->ref_bonus_amount = data_get($discount_data,'calculated_amount');
            }
        }

        $total_price = max($total_price, 0);
        $totalDiscount = $restaurant_discount_amount + $coupon_discount_amount +  $order->ref_bonus_amount;

        $additionalCharges = [];

        $settings = BusinessSetting::whereIn('key', [
            'dm_tips_status',
            'additional_charge_status',
            'additional_charge',
            'extra_packaging_charge',
        ])->pluck('value', 'key');

        $dm_tips_manage_status     = $settings['dm_tips_status'] ?? null;
        $additional_charge_status  = $settings['additional_charge_status'] ?? null;
        $additional_charge         = $settings['additional_charge'] ?? null;

        $extra_packaging_data  = $settings['extra_packaging_charge'] ?? 0;

        //Added DM TIPS
        $order->dm_tips = 0;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        }

        //Added service charge
        $order->additional_charge = 0;

        if ($additional_charge_status == 1) {
            $order->additional_charge = $additional_charge ?? 0;
        }

        $order->extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $request?->extra_packaging_amount > 0)?$restaurant?->restaurant_config?->extra_packaging_amount:0;

        if ($order->extra_packaging_amount > 0) {
            $additionalCharges['tax_on_packaging_charge'] =  $order->extra_packaging_amount;
        }

        $finalCalculatedTax =  Helpers::getFinalCalculatedTax($order_details, $additionalCharges, $totalDiscount, $total_price, $restaurant->id);
        $taxType=  data_get($finalCalculatedTax ,'taxType');
        $tax_amount = $finalCalculatedTax['tax_amount'];
        $tax_status = $finalCalculatedTax['tax_status'];
        $taxMap = $finalCalculatedTax['taxMap'];
        $orderTaxIds = data_get($finalCalculatedTax, 'taxData.orderTaxIds', []);

        $order->tax_status = $tax_status;
        $order->tax_type = $taxType;


        // 哪吒[起送价口径·券后]: 起送门槛按"商品券后金额"判定(商品+加料 - 商家活动折扣 - 优惠券),
        //   与前端餐厅页购物车条 cartPayableAmount 同源, 避免折前/折后分叉(临界区白丢单/最后一步被拒).
        //   不含配送费/税/小费/打包费; 首单返现(ref_bonus)不计入起送判定.
        $nezha_min_order_comparable = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;
        if($restaurant->minimum_order > $nezha_min_order_comparable )
        {
            return response()->json([
                'errors' => [
                    ['code' => 'order_amount', 'message' => translate('messages.you_need_to_order_at_least').' '. $restaurant->minimum_order.' '.Helpers::currency_code()],
                ]
            ], 406);
        }



        $businessSettings = BusinessSetting::whereIn('key', [ 'free_delivery_over', 'free_delivery_distance','admin_free_delivery_status', 'admin_free_delivery_option'])->pluck('value', 'key');

        $free_delivery_over = (float) ($businessSettings['free_delivery_over'] ?? 0);
        $free_delivery_distance = (float) ($businessSettings['free_delivery_distance'] ?? 0);
        $admin_free_delivery_status = (int) ($businessSettings['admin_free_delivery_status'] ?? 0);
        $admin_free_delivery_option = $businessSettings['admin_free_delivery_option'] ?? null;


        if ($admin_free_delivery_status === 1) {
            $eligibleAmount = $total_price;
            if ($admin_free_delivery_option === 'free_delivery_to_all_store' ||($admin_free_delivery_option === 'free_delivery_by_specific_criteria' && ($free_delivery_distance > 0 && $request->distance <= $free_delivery_distance) || ($free_delivery_over > 0  && $eligibleAmount >= $free_delivery_over)) ) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }


        if($restaurant->free_delivery){
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if($restaurant->self_delivery_system == 1 && $restaurant->free_delivery_distance_status == 1 && $restaurant->free_delivery_distance_value && ($request->distance <= $restaurant->free_delivery_distance_value)){
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        // Delivery type charge

        $additional_delivery_option_status = $order?->zone?->additional_delivery_option_status;

        if($additional_delivery_option_status == 1 && isset($request->delivery_type) && $order->order_type == 'delivery'){
            $deliveryOption = is_numeric($request->delivery_type)
                ? ZoneDeliveryOption::find($request->delivery_type)
                : ZoneDeliveryOption::where('delivery_type', $request->delivery_type)->where('zone_id', $order->zone_id)->first();

            if($deliveryOption && $deliveryOption->delivery_type != 'standard'){
                $order->delivery_type = $deliveryOption->delivery_type;
                $order->delivery_type_charge = $deliveryOption->delivery_type == 'express' ? $deliveryOption->extra_charge : $deliveryOption->reduce_charge;
            }
        }

        $order->coupon_created_by = $coupon_created_by;
        $order_amount = round($total_price + $tax_amount + $order->delivery_charge + $order->additional_charge + $order->extra_packaging_amount, config('round_up_to_digit'));
        $order->total_tax_amount= round($tax_amount, config('round_up_to_digit'));
        $order->order_amount = $order_amount + $order->dm_tips;
        if($request->delivery_type == 'slightly_delay'){
            $order->order_amount -= $order->delivery_type_charge;
        }else{
            $order->order_amount += $order->delivery_type_charge;
        }
        // 哪吒[资金完整性·风控入参]: 用服务端重算的权威订单金额复评风控, 堵住"前端低报 order_amount
        //   绕过单笔/大额风控阈值"的缺口(L157 build_context 早评用的是客户端 $request->order_amount)。
        //   早评对诚实顾客已是权威; 低报者会在此处被真金额拦下。仍在事务内, 命中即记录+rollBack 不建单。
        $nezha_risk_ctx_authoritative = $nezha_risk_ctx;
        $nezha_risk_ctx_authoritative['order_amount'] = (float) $order->order_amount;
        // 哪吒[风控通道收口]: 不信任客户端 payment_channel(已在 ctx 内但此处忽略), 由服务端 order->payment_method 权威判定通道; 线下支付通道未定则对两套阈值取最严。
        $nezha_risk_authoritative = \App\CentralLogics\NezhaRiskControl::evaluate_server_authoritative($nezha_risk_ctx_authoritative, (string) $order->payment_method);
        if ($nezha_risk_authoritative['action'] !== 'pass') {
            DB::rollBack();
            \App\CentralLogics\NezhaRiskControl::record($nezha_risk_ctx_authoritative, $nezha_risk_authoritative);
            return response()->json([
                'errors' => [
                    ['code' => $nezha_risk_authoritative['action'] === 'reject' ? 'risk_reject' : 'risk_review', 'message' => $nezha_risk_authoritative['message']]
                ]
            ], 403);
        }
        if($request->payment_method == 'wallet' && $request->user->wallet_balance < $order_amount)
        {
            DB::rollBack();
            return response()->json([
                'errors' => [
                    ['code' => 'order_amount', 'message' => translate('messages.insufficient_balance')]
                ]
            ], 203);
        }
        if ($request->partial_payment && $request->user->wallet_balance > $order->order_amount) {
            DB::rollBack();
            return response()->json([
                'errors' => [
                    ['code' => 'partial_payment', 'message' => translate('messages.order_amount_must_be_greater_than_wallet_amount')]
                ]
            ], 203);
        }
        try {
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount= round($restaurant_discount_amount, config('round_up_to_digit'));



            if( $max_cod_order_amount_value > 0 && $order->payment_method == 'cash_on_delivery' && $order->order_amount > $max_cod_order_amount_value){
                DB::rollBack();
                return response()->json([
                    'errors' => [
                        ['code' => 'order_amount', 'message' => translate('messages.You can not Order more then ').$max_cod_order_amount_value .Helpers::currency_symbol().' '. translate('messages.on COD order.')]
                    ]
                ], 203);
            }

            // new Order Subscription create
            if($request->subscription_order && $request->subscription_quantity){
                $subscription = new Subscription();
                $subscription->start_at = $request->subscription_start_at;
                $subscription->status = 'active';
                $subscription->end_at = $request->subscription_end_at;
                $subscription->type = $request->subscription_type;
                $subscription->quantity = $request->subscription_quantity;
                $subscription->user_id = $request->user->id;
                $subscription->restaurant_id = $restaurant->id;
                $subscription->save();
                $order->subscription_id = $subscription->id;

                $days = array_map(function($day)use($subscription){
                    $day['subscription_id'] = $subscription->id;
                    $day['type'] = $subscription->type;
                    $day['created_at'] = now();
                    $day['updated_at'] = now();
                    return $day;
                },json_decode($request->subscription_days, true));
                SubscriptionSchedule::insert($days);
            }

            $order->save();

            OrderLogic::create_subscription_log(id:$order->id);
            // End Order Subscription.

            $taxMapCollection = collect($taxMap);
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;

                if($restaurant_discount_amount <= 0 ){
                    $order_details[$key]['discount_on_food'] = 0;
                }

                if ($item['food_id']) {
                    $item_id = $item['food_id'];
                } else {
                    $item_id = $item['item_campaign_id'];
                }
                $index = $taxMapCollection->search(function ($tax) use ($item_id) {
                    return $tax['product_id'] == $item_id;
                });
                if ($index !== false) {
                    $matchedTax = $taxMapCollection->pull($index);
                    $order_details[$key]['tax_status'] = $matchedTax['include'] == 1 ? 'included' : 'excluded';
                    $order_details[$key]['tax_amount'] = $matchedTax['totalTaxamount'];
                }
            }

            OrderDetail::insert($order_details);

            if (count($orderTaxIds)) {
                \Modules\TaxModule\Services\CalculateTaxService::updateOrderTaxData(
                    orderId: $order->id,
                    orderTaxIds: $orderTaxIds,
                );
            }

            if(!isset($request->is_buy_now) || (isset($request->is_buy_now) && $request->is_buy_now == 0 )){
                foreach ($carts as $cart) {
                    $cart->delete();
                }
            }

            $restaurant->increment('total_order');

            if($request->user){
                $customer = $request->user;
                $customer->zone_id = $restaurant->zone_id;
                $customer->save();

                Helpers::visitor_log(model: 'restaurant', user_id:$customer->id, visitor_log_id:$restaurant->id, order_count:true);
            }
            if($request->payment_method == 'wallet') CustomerLogic::create_wallet_transaction(user_id:$order->user_id, amount:$order->order_amount, transaction_type:'order_place', referance:$order->id);

            if ($request->partial_payment) {
                if ($request->user->wallet_balance<=0) {
                    DB::rollBack();
                    return response()->json([
                        'errors' => [
                            ['code' => 'order_amount', 'message' => translate('messages.insufficient_balance_for_partial_amount')]
                        ]
                    ], 203);
                }
                $p_amount = min($request->user->wallet_balance, $order->order_amount);
                $unpaid_amount = $order->order_amount - $p_amount;
                $order->partially_paid_amount = $p_amount;
                $order->save();
                CustomerLogic::create_wallet_transaction($order->user_id, $p_amount, 'partial_payment', $order->id);
                OrderLogic::create_order_payment(order_id:$order->id, amount:$p_amount, payment_status:'paid', payment_method:'wallet');
                OrderLogic::create_order_payment(order_id:$order->id, amount:$unpaid_amount, payment_status:'unpaid',payment_method:$request->payment_method);
            }


            if($order->is_guest  == 0 && $order->user_id && !($request->subscription_order && $request->subscription_quantity) ){
                $this->createCashBackHistory($order->order_amount, $order->user_id,$order->id);
            }
            if(in_array($request['order_type'],['dine_in']) ){
                $OrderReference = new OrderReference();
                $OrderReference->order_id = $order->id;
                $OrderReference->save();
            }

            DB::commit();
            //PlaceOrderMail

            return response()->json([
                'message' => translate('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price+$order->delivery_charge+$tax_amount
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            info($e->getMessage());
            return response()->json([$e->getMessage()], 403);
        }

        return response()->json([
            'errors' => [
                ['code' => 'order_time', 'message' => translate('messages.failed_to_place_order')]
            ]
        ], 403);
    }

    public function get_order_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];

        $paginator = Order::with(['restaurant', 'delivery_man.rating', 'details:id,order_id,item_campaign_id'])->withCount('details')->where(['user_id' => $user_id])->
        whereIn('order_status', ['delivered','canceled','refund_requested','refund_request_canceled','refunded','failed'])->Notpos()
            ->whereNull('subscription_id')
            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })

            ->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address']?json_decode($data['delivery_address']):$data['delivery_address'];
            $data['restaurant'] = $data['restaurant']?Helpers::restaurant_data_formatting($data['restaurant']):$data['restaurant'];
            $data['delivery_man'] = $data['delivery_man']?Helpers::deliverymen_data_formatting([$data['delivery_man']]):$data['delivery_man'];
            $data['is_reviewed'] =   $data['details_count'] >  Review::whereOrderId($data->id)->count() ? False :True ;
            $data['is_dm_reviewed'] = $data['delivery_man'] ? DMReview::whereOrderId($data->id)->exists()  : True ;
            $data['item_campaign_id'] = $data['details'] ? $data['details'][0]['item_campaign_id'] : True ;
            unset($data['details']);
            return $data;
        }, $paginator->items());
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }
    public function get_order_subscription_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['user_id' => $user_id])
            ->Notpos()
            ->whereNotNull('subscription_id')
            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address']?json_decode($data['delivery_address']):$data['delivery_address'];
            $data['restaurant'] = $data['restaurant']?Helpers::restaurant_data_formatting($data['restaurant']):$data['restaurant'];
            $data['delivery_man'] = $data['delivery_man']?Helpers::deliverymen_data_formatting([$data['delivery_man']]):$data['delivery_man'];
            $data['is_reviewed'] =   $data['details_count'] >  Review::whereOrderId($data->id)->count() ? False :True ;
            $data['is_dm_reviewed'] =  $data['delivery_man'] ? DMReview::whereOrderId($data->id)->exists()  : True ;

            return $data;
        }, $paginator->items());
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }


    public function get_running_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];

        $paginator = Order::with(['restaurant', 'delivery_man.rating', 'offline_payments:order_id,status,note,created_at,updated_at'])->withCount('details')->where(['user_id' => $user_id])
            ->whereNull('subscription_id')
            ->whereNotIn('order_status', ['delivered','canceled','refund_requested','refund_request_canceled','refunded','failed'])
            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->Notpos()->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address']?json_decode($data['delivery_address']):$data['delivery_address'];
            $data['restaurant'] = $data['restaurant']?Helpers::restaurant_data_formatting($data['restaurant']):$data['restaurant'];
            $data['delivery_man'] = $data['delivery_man']?Helpers::deliverymen_data_formatting([$data['delivery_man']]):$data['delivery_man'];
            return $data;
        }, $paginator->items());
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }

    public function get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::with('details','offline_payments','subscription.schedules', 'restaurant')->where('user_id', $user_id)

            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->find($request->order_id);
        $details = $order?->details;

        if ($details != null && $details->count() > 0) {
            $storage = [];
            foreach ($details as $item) {
                $item['add_ons'] = json_decode($item['add_ons']);
                $item['variation'] = json_decode($item['variation']);
                $item['food_details'] = json_decode($item['food_details'], true);
                $item['zone_id'] = (int) (isset($order->zone_id) ? $order->zone_id :  $order->restaurant->zone_id);
                array_push($storage, $item);
            }
            $data = $storage;
            $subscription_schedules =  $order?->subscription?->schedules;
            $offline_payment = isset($order->offline_payments) ? Helpers::offline_payment_formater($order->offline_payments) : null;

            return response()->json(['details'=>$data, 'subscription_schedules'=> $subscription_schedules, 'offline_payment' => $offline_payment, 'saver_delivery_time' => $this->get_saver_delivery_time($order)
            ], 200);
        }

        else {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
    }

    public function cancel_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['user_id' => $user_id, 'id' => $request['order_id']])

            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->with('details')
            ->Notpos()->first();
        if(!$order){
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        else if ($order->order_status == 'pending' || $order->order_status == 'failed' || $order->order_status == 'canceled'  ) {

            // 哪吒(幂等守卫 2026-06-22 QA辩论): 已是 canceled 的单重复调用 cancel_order → 直接幂等返回,
            //   不再二次 decreaseSellCount / 重复 send_order_notification / 重复 increment_order_count(防销量被错误下扣、重复推送)。
            //   退款留痕本就幂等(offline_payments 已 canceled → 下方 whereIn 取不到, 不重复 record_direct_pay_refund_pending)。
            if ($order->order_status == 'canceled') {
                return response()->json(['message' => translate('messages.order_canceled_successfully')], 200);
            }

            if(!$request->reason && !$request->note){
                return response()->json([
                    'errors' => [
                        ['code' => 'order', 'message' => translate('messages.cancellation_reason_required')]
                    ]
                ], 403);
            }

            $order->order_status = 'canceled';
            $order->canceled = now();
            $order->cancellation_reason = $request->reason;
            $order->cancellation_note = $request->note;
            $order->canceled_by = 'customer';
            $order->save();

            Helpers::decreaseSellCount(order_details:$order->details);
            Helpers::send_order_notification($order);
            Helpers::increment_order_count($order->restaurant); //for subscription package order increase


            // 哪吒 B方案(QA 2026-06-18): 直付单且顾客已提交付款凭证(=钱已直付商家本人) ——
            //   (1) 清理 offline_payments->canceled(防"已取消单仍被确认收款"复活, H3)
            //   (2) 生成「待退款」留痕 + 通知商家原路退款(平台不碰钱 L1-1)
            //   (3) 给顾客发"请联系商家原路退款"通知(平台/admin 退不了直付的钱)。
            $nezha_offline_proof = $order->payment_method == 'offline_payment'
                ? \App\Models\OfflinePayments::where('order_id', $order->id)->whereIn('status', ['pending', 'verified', 'denied'])->first()
                : null;
            if ($nezha_offline_proof) {
                \App\Models\OfflinePayments::where('order_id', $order->id)->update(['status' => 'canceled']);
                \App\CentralLogics\OrderLogic::record_direct_pay_refund_pending($order, 'customer', $order->user_id, '顾客取消订单，已支付款项需商家原路退回', true);
                try {
                    $nezha_zh = stripos(($order->customer?->current_language_key ?: 'zh'), 'zh') === 0;
                    $nezha_ntitle = $nezha_zh ? '订单已取消' : 'Order canceled';
                    $nezha_nmsg = $nezha_zh
                        ? '你的订单 #' . $order->id . ' 已取消。你此前直接支付给商家的款项，请联系商家按原路退回（平台不经手此款）。'
                        : 'Your order #' . $order->id . ' is canceled. For the amount paid directly to the restaurant, please contact the restaurant for an original-route refund.';
                    $nezha_fcm = $order->is_guest == 0 ? $order?->customer?->cm_firebase_token : null;
                    $nezha_ndata = Helpers::makeDataForPushNotification(title: $nezha_ntitle, message: $nezha_nmsg, orderId: $order->id, type: 'order_status', orderStatus: 'canceled');
                    if ($nezha_fcm && Helpers::customerWantsPush($order->customer, 'refund')) { Helpers::send_push_notif_to_device($nezha_fcm, $nezha_ndata); }
                    if ($order->is_guest == 0) { Helpers::insertDataOnNotificationTable($nezha_ndata, 'user', $order->user_id); }
                } catch (\Throwable $e) { info('nezha cancel refund-notice failed: ' . $e->getMessage()); }
                return response()->json(['message' => translate('messages.order_canceled_contact_restaurant_for_refund')], 200);
            }

            $wallet_status= BusinessSetting::where('key','wallet_status')->first()?->value;
            $refund_to_wallet= BusinessSetting::where('key', 'wallet_add_refund')->first()?->value;

            if($order?->payments && $order?->is_guest == 0){
                $refund_amount =$order->payments()->where('payment_status','paid')->sum('amount');
                if($wallet_status &&  $refund_to_wallet && $refund_amount > 0){
                    CustomerLogic::create_wallet_transaction(user_id:$order->user_id, amount:$refund_amount,transaction_type: 'order_refund',referance: $order->id);

                    return response()->json(['message' => translate('messages.order_canceled_successfully_and_refunded_to_wallet')], 200);
                } else {
                    return response()->json(['message' => translate('messages.order_canceled_successfully_and_for_refund_amount_contact_admin')], 200);
                }
            }


            return response()->json(['message' => translate('messages.order_canceled_successfully')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.you_can_not_cancel_after_confirm')]
            ]
        ], 403);
    }

    /**
     * 哪吒 B方案 — 顾客「接单后申请取消」(申请 → 商家裁决, 非自助即时取消)。
     * 适用阶段: confirmed / processing(已接单/备餐中)。pending 仍走 cancel_order 自助即时取消;
     * handover/picked_up(已出餐/配送中)一律拒绝(饭已出, 见 docs/ORDER_TIMEOUT_RULES.md D/E)。
     * 本端点只「登记申请 + 通知商家」, 绝不改 order_status, 订单继续履约直到商家同意/拒绝。
     * 防刷: 已有 requested 申请→拦; 被拒后 10 分钟内不可再申请。
     * 顾客可见消息按 current_language_key 直出中英文(不经 messages.php, 避热点文件并发覆盖)。
     */
    public function cancel_request(Request $request)
    {
        $lang = $request->user?->current_language_key ?: 'zh';
        $zh = stripos($lang, 'zh') === 0;
        $validator = Validator::make($request->all(), [
            'guest_id' => $request->user ? 'nullable' : 'required',
            'reason'   => 'required|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['user_id' => $user_id, 'id' => $request['order_id']])
            ->when(!isset($request->user), function ($query) { $query->where('is_guest', 1); })
            ->when(isset($request->user), function ($query) { $query->where('is_guest', 0); })
            ->Notpos()->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        // 仅 confirmed/processing 可申请(已出餐/配送中/已送达不可)
        if (!in_array($order->order_status, ['confirmed', 'processing'], true)) {
            $m = $zh
                ? '当前订单状态无法申请取消。商家未接单时可直接取消；已出餐或配送中请直接联系商家。'
                : 'You cannot request cancellation at this stage. Please contact the restaurant directly.';
            return response()->json(['errors' => [['code' => 'order', 'message' => $m]]], 403);
        }
        // 已有待处理申请 → 不重复(防刷)
        if ($order->nezha_cancel_request === 'requested') {
            $m = $zh ? '你的取消申请正在等待商家处理，请勿重复提交。' : 'Your cancellation request is pending. Please do not submit again.';
            return response()->json(['errors' => [['code' => 'order', 'message' => $m]]], 403);
        }
        // 被拒后 10 分钟节流
        if ($order->nezha_cancel_request === 'rejected' && $order->nezha_cancel_responded_at
            && \Carbon\Carbon::parse($order->nezha_cancel_responded_at)->gt(now()->subMinutes(10))) {
            $m = $zh ? '商家刚拒绝了你的取消申请，请稍后再试或直接联系商家。' : 'The restaurant just declined your request. Please try again later or contact the restaurant.';
            return response()->json(['errors' => [['code' => 'order', 'message' => $m]]], 403);
        }

        $order->nezha_cancel_request = 'requested';
        $order->nezha_cancel_request_reason = mb_substr($request->reason, 0, 500);
        $order->nezha_cancel_requested_at = now();
        $order->nezha_cancel_response_note = null;
        $order->nezha_cancel_responded_at = null;
        $order->save();

        // 通知商家有待处理的取消申请(推送)。失败不阻断。
        try {
            $vendorToken = $order->restaurant?->vendor?->firebase_token;
            if ($vendorToken) {
                $title = '顾客申请取消订单';
                $msg = '订单 #' . $order->id . ' 顾客申请取消，理由：' . mb_substr($request->reason, 0, 120) . '。请在订单详情「同意取消」或「拒绝继续履约」。';
                $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
                Helpers::send_push_notif_to_device($vendorToken, $data);
            }
        } catch (\Throwable $e) { info('cancel_request notify vendor failed: ' . $e->getMessage()); }

        $ok = $zh
            ? '取消申请已提交，等待商家处理。商家同意后订单将取消；若你此前已付款，需联系商家原路退回。'
            : 'Cancellation request submitted. Waiting for the restaurant. If approved and you have paid, contact the restaurant for an original-route refund.';
        return response()->json(['message' => $ok], 200);
    }

    /**
     * 哪吒 B方案 — 顾客「确认收货」(方案A)。
     * 平台不配送(顾客自叫 Yandex/自取), 顾客最清楚何时收到 → 由顾客点确认来收尾订单。
     * 触发与商家「已送达」等价的收尾(OrderLogic::settle_delivered: delivered + 佣金结算恰好一次)。
     * 轴A 对象级鉴权: 仅能确认归属请求者本人的单(登录按 user->id; 游客按 guest_id + is_guest=1)。
     */
    public function remind_delivery_link(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($query) { $query->where('is_guest', 1); })
            ->when(isset($request->user), function ($query) { $query->where('is_guest', 0); })
            ->Notpos()->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        if ($order->order_type !== 'delivery' || $order->order_status !== 'picked_up') {
            return response()->json(['errors' => [['code' => 'order', 'message' => '当前订单状态无需提醒商家']]], 403);
        }
        if (!empty($order->yandex_tracking_url)) {
            return response()->json(['message' => '商家已分享配送进度，请刷新查看'], 200);
        }
        // 防刷: 10 分钟内只记一次提醒
        if ($order->delivery_link_reminded_at && \Carbon\Carbon::parse($order->delivery_link_reminded_at)->gt(now()->subMinutes(10))) {
            return response()->json(['message' => '已提醒商家，请耐心等待商家分享配送进度'], 200);
        }
        $order->delivery_link_reminded_at = now();
        $order->save();
        try {
            Helpers::sendTelegramToRestaurant($order->restaurant,
                "🔔 顾客在催配送进度\n订单 #{$order->id}\n请在 Yandex Go 点「分享 / Поделиться」复制配送追踪链接，回到商家后台「待配送」订单贴上，顾客即可实时查看。");
        } catch (\Throwable $e) {}
        return response()->json(['message' => '已提醒商家分享配送进度，请稍候'], 200);
    }

    /**
     * 哪吒[退款专项2026-06-22 Piece2]: 顾客「催一下商家退款」。
     * 待退款单上点「催一下」→ 平台替顾客提醒商家(站内信进商家消息中心 + Telegram)尽快原路退款。
     * L1-1: 平台不碰钱, 仅转达提醒。对象级鉴权: 只能催【本人】且【确处于待退款】的单。防刷: 6 小时一次。
     */
    public function nudge_refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($q) { $q->where('is_guest', 1); })
            ->when(isset($request->user), function ($q) { $q->where('is_guest', 0); })
            ->Notpos()->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        // 仅「待退款」单可催: 有 pending_merchant_refund 留痕, 或订单态=refund_requested。
        $hasPendingRecord = \App\Models\NezhaRefundRecord::where('order_id', $order->id)
            ->where('status', 'pending_merchant_refund')->exists();
        if (!$hasPendingRecord && $order->order_status !== 'refund_requested') {
            return response()->json(['errors' => [['code' => 'order', 'message' => '当前订单不在待退款状态']]], 403);
        }
        // 防刷: 6 小时内只替顾客转达一次提醒。
        $key = 'nezha_refund_nudge_' . $order->id;
        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return response()->json(['message' => '已提醒商家，请耐心等待；如长时间未退可联系商家或客服'], 200);
        }
        \Illuminate\Support\Facades\Cache::put($key, now()->toDateTimeString(), now()->addHours(6));
        // 给商家发消息: 后台消息中心站内信 + Telegram(商家主渠道)。失败不阻断。
        try {
            $vendorId = $order->restaurant?->vendor_id;
            if ($vendorId) {
                $data = Helpers::makeDataForPushNotification(
                    title: '顾客在催退款',
                    message: '订单 #' . $order->id . ' 的顾客在催退款，请您尽快按原路退还顾客付款，并在「订单→待退款」点「标记已退款」。',
                    orderId: $order->id, type: 'order_status', orderStatus: 'refunded'
                );
                Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId);
                $vendorToken = $order->restaurant?->vendor?->firebase_token;
                if ($vendorToken) {
                    Helpers::send_push_notif_to_device($vendorToken, $data);
                }
            }
        } catch (\Throwable $e) {
            info('nudge_refund vendor notify failed: ' . $e->getMessage());
        }
        try {
            Helpers::sendTelegramToRestaurant($order->restaurant,
                "🔔 顾客在催退款\n订单 #{$order->id}\n请您尽快按原路退还顾客付款（平台不经手此款），退款后在商家后台「订单 → 待退款」点「标记已退款」。");
        } catch (\Throwable $e) {}
        return response()->json(['message' => '已替您提醒商家尽快退款，请稍候'], 200);
    }

    /**
     * 哪吒[退款专项 块3a]: 顾客「确认收到退款」。
     * 仅本人单 + 该单最新退款留痕 status=merchant_refunded(商家已标记退款) + 未确认过 → 置 customer_confirmed=true。
     * 不新增终态/不改 status(merchant_refunded 仍是商家侧终态), 顾客确认只是叠加「已签收」软事实。
     * 通知商家 + 平台超管「顾客已确认收到退款, 此单闭环」。L1-1: 仅状态标记+通知, 不碰钱。
     */
    public function confirm_refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::with('restaurant')->where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($q) { $q->where('is_guest', 1); })
            ->when(isset($request->user), function ($q) { $q->where('is_guest', 0); })
            ->Notpos()->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        $rec = \App\Models\NezhaRefundRecord::where('order_id', $order->id)->orderByDesc('id')->first();
        if (!$rec || $rec->status !== 'merchant_refunded') {
            return response()->json(['errors' => [['code' => 'order', 'message' => '该订单当前不可确认收款']]], 403);
        }
        if ($rec->customer_confirmed) {
            return response()->json(['message' => '您已确认收到退款，感谢反馈。'], 200);
        }
        $rec->customer_confirmed = true;
        $rec->customer_confirmed_at = now();
        $rec->save();

        try {
            $vendorId = $order->restaurant?->vendor_id;
            if ($vendorId) {
                $data = Helpers::makeDataForPushNotification(title: '顾客已确认收到退款', message: '订单 #' . $order->id . ' 的顾客已确认收到您的原路退款，此单退款闭环。感谢您的配合。', orderId: $order->id, type: 'order_status', orderStatus: 'refunded');
                Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId);
                $vendorToken = $order->restaurant?->vendor?->firebase_token;
                if ($vendorToken) { Helpers::send_push_notif_to_device($vendorToken, $data); }
            }
        } catch (\Throwable $e) { info('confirm_refund vendor notify failed: ' . $e->getMessage()); }
        try {
            Helpers::sendTelegramToRestaurant($order->restaurant, "✅ 顾客已确认收到退款\n订单 #" . $order->id . "\n此单退款闭环，感谢配合。");
        } catch (\Throwable $e) {}
        try {
            Helpers::sendTelegramToAdmin("✅ 退款闭环\n订单 #" . $order->id . " 顾客已确认收到商家原路退款。");
        } catch (\Throwable $e) {}

        return response()->json(['message' => '已确认收到退款，感谢反馈！'], 200);
    }

    /**
     * 哪吒[退款专项 块3b]: 顾客「没收到退款」争议。
     * 仅本人单 + 该单最新退款留痕 status=merchant_refunded(商家已声称退款) → 起争议留痕(NezhaDeliveryAppeal, reason_code=refund_not_received)
     *   + 主动通知商家(站内信+Telegram「请核对原路退款」) + 通知平台超管介入(Telegram+邮件)。
     * 🔴 不改退款 status、不撤商家「已退款」标记、不自动重新停接单 —— 防顾客一面之词诬告真退款的商家; 由运营人工裁决。
     * L1-1: 仅留痕+通知, 不碰钱。防刷: 同单 6h 一次。
     */
    public function dispute_refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
            'detail'   => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::with('restaurant')->where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($q) { $q->where('is_guest', 1); })
            ->when(isset($request->user), function ($q) { $q->where('is_guest', 0); })
            ->Notpos()->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        $rec = \App\Models\NezhaRefundRecord::where('order_id', $order->id)->orderByDesc('id')->first();
        if (!$rec || $rec->status !== 'merchant_refunded') {
            return response()->json(['errors' => [['code' => 'order', 'message' => '该订单当前不可发起未收到退款反馈']]], 403);
        }
        $key = 'nezha_refund_dispute_' . $order->id;
        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return response()->json(['message' => '我们已收到您的反馈并在跟进，请耐心等待客服联系。'], 200);
        }
        $existing = \App\Models\NezhaDeliveryAppeal::where('order_id', $order->id)
            ->where('reason_code', 'refund_not_received')
            ->whereIn('status', ['open', 'merchant_contacted'])->orderByDesc('id')->first();
        if (!$existing) {
            $resolve_hours = (int) (DB::table('business_settings')->where('key', 'nezha_appeal_resolve_hours')->value('value') ?? 72);
            \App\Models\NezhaDeliveryAppeal::create([
                'order_id'    => $order->id,
                'user_id'     => $request->user ? $request->user->id : null,
                'reason_code' => 'refund_not_received',
                'detail'      => $request->detail,
                'evidence'    => [
                    'refund_record_id'     => $rec->id,
                    'refund_amount'        => $rec->refund_amount,
                    'payment_channel'      => $rec->payment_channel,
                    'merchant_refunded_at' => (string) $rec->merchant_refunded_at,
                    'submitted_via'        => 'customer_app',
                ],
                'status'      => 'open',
                'sla_due_at'  => now()->addHours($resolve_hours),
            ]);
        }
        \Illuminate\Support\Facades\Cache::put($key, now()->toDateTimeString(), now()->addHours(6));

        try {
            $vendorId = $order->restaurant?->vendor_id;
            if ($vendorId) {
                $data = Helpers::makeDataForPushNotification(title: '顾客反映未收到退款', message: '订单 #' . $order->id . ' 的顾客反映还没收到您的退款，请核对是否已按原路退款成功（如已退请保留凭证），平台正在协助核实。', orderId: $order->id, type: 'order_status', orderStatus: 'refunded');
                Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId);
                $vendorToken = $order->restaurant?->vendor?->firebase_token;
                if ($vendorToken) { Helpers::send_push_notif_to_device($vendorToken, $data); }
            }
        } catch (\Throwable $e) { info('dispute_refund vendor notify failed: ' . $e->getMessage()); }
        try {
            Helpers::sendTelegramToRestaurant($order->restaurant, "⚠️ 顾客反映未收到退款\n订单 #" . $order->id . "\n请核对是否已按原路退款成功（如已退请保留凭证）。平台正在协助核实，请勿忽略。");
        } catch (\Throwable $e) {}
        try {
            Helpers::sendTelegramToAdmin("⚠️ 退款争议: 顾客反映未收到\n订单 #" . $order->id . "\n商家：" . ($order->restaurant?->name ?? '-') . "\n应退：" . \App\CentralLogics\Helpers::format_currency($rec->refund_amount) . "\n请介入核实(商家退款凭证 vs 顾客收款)，平台不代退、由人工裁决。");
        } catch (\Throwable $e) {}
        try {
            if (config('mail.status')) {
                $admin = \App\Models\Admin::where('role_id', 1)->first();
                $adminEmail = $admin ? $admin->getRawOriginal('email') : null;
                if ($adminEmail) {
                    $body = "顾客反映未收到退款, 需平台介入核实。\n\n订单号: #" . $order->id . "\n商家: " . ($order->restaurant?->name ?? '-') . "\n应退金额: " . \App\CentralLogics\Helpers::format_currency($rec->refund_amount) . "\n商家标记退款时间: " . $rec->merchant_refunded_at . "\n顾客补充: " . ($request->detail ?: '-') . "\n\n请凭双方凭证人工核实。平台不经手此款、不自动撤销商家退款标记、不自动停接单——如核实商家确未退, 由运营在后台手动处置。";
                    \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($adminEmail, $order) {
                        $m->to($adminEmail)->subject('【哪吒退款争议】订单 #' . $order->id . ' 顾客反映未收到退款');
                    });
                }
            }
        } catch (\Throwable $e) { info('dispute_refund admin mail failed: ' . $e->getMessage()); }

        return response()->json(['message' => '我们已介入核实并通知商家核对，客服会尽快跟进。请保留您的收款账户信息，钱款以原路渠道到账为准。'], 200);
    }

    public function confirm_delivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($query) {
                $query->where('is_guest', 1);
            })
            ->when(isset($request->user), function ($query) {
                $query->where('is_guest', 0);
            })
            ->Notpos()->first();

        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        if ($order->subscription_id != null) {
            // 订阅单不走顾客确认收货
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 403);
        }
        if ($order->delivered != null || $order->order_status == 'delivered') {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.order_already_delivered')]]], 403);
        }
        if (!in_array($order->order_status, ['handover', 'picked_up'], true)) {
            // 商家尚未出餐交付, 还不能确认收货
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.you_can_confirm_after_handover')]]], 403);
        }

        $ok = OrderLogic::settle_delivered($order, 'customer', $request->user ? $request->user->id : null);
        if (!$ok) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.faield_to_create_order_transaction')]]], 403);
        }

        return response()->json(['message' => translate('messages.order_received_successfully')], 200);
    }

    /**
     * 哪吒 B方案 — 「没有收到餐 / 配送异常」专用申诉(req 4)，区别于通用退款申请。
     * 平台不碰钱：本端点只创建留痕 + 记审计日志，**不触发任何自动退款**(L1-1/L1-2)。
     * 真实退款仍走「联系商家原路退回」。返回申诉时限/处理预期供前端展示(req 5)。
     */
    public function submit_delivery_appeal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id'    => 'required',
            'guest_id'    => $request->user ? 'nullable' : 'required',
            'reason_code' => 'nullable|string|max:64',
            'detail'      => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::with('restaurant')->where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!isset($request->user), function ($q) { $q->where('is_guest', 1); })
            ->when(isset($request->user), function ($q) { $q->where('is_guest', 0); })
            ->Notpos()->first();

        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 404);
        }
        if ($order->order_type !== 'delivery') {
            return response()->json(['errors' => [['code' => 'order', 'message' => '仅配送订单可提交「没有收到餐」申诉。']]], 403);
        }
        if (!in_array($order->order_status, ['handover', 'picked_up', 'delivered'], true)) {
            return response()->json(['errors' => [['code' => 'order', 'message' => '该订单当前状态无法提交配送申诉。']]], 403);
        }

        // 申诉时限(req 5): 送达后 N 小时内可申诉
        $window_hours = (int) (DB::table('business_settings')->where('key', 'nezha_appeal_window_hours')->value('value') ?? 48);
        if ($order->delivered) {
            $deadline = \Carbon\Carbon::parse($order->delivered)->addHours($window_hours);
            if ($deadline->isPast()) {
                return response()->json(['errors' => [['code' => 'order', 'message' => "申诉窗口已过（送达后 {$window_hours} 小时内可申诉），请直接联系商家或客服。"]]], 403);
            }
        }

        // 去重: 已有未结申诉则直接返回, 不重复建单
        $existing = \App\Models\NezhaDeliveryAppeal::where('order_id', $order->id)
            ->whereIn('status', ['open', 'merchant_contacted'])->orderByDesc('id')->first();
        if ($existing) {
            return response()->json([
                'message' => '你已提交过申诉，我们正在处理中。',
                'appeal'  => ['id' => $existing->id, 'status' => $existing->status, 'sla_due_at' => (string) $existing->sla_due_at],
            ], 200);
        }

        $resolve_hours = (int) (DB::table('business_settings')->where('key', 'nezha_appeal_resolve_hours')->value('value') ?? 72);
        $appeal = \App\Models\NezhaDeliveryAppeal::create([
            'order_id'    => $order->id,
            'user_id'     => $request->user ? $request->user->id : null,
            'reason_code' => $request->reason_code ?: 'not_received',
            'detail'      => $request->detail,
            'evidence'    => [
                'has_payment_proof' => $order->offline_payments()->exists(),
                'payment_method'    => $order->payment_method,
                'submitted_via'     => 'customer_app',
            ],
            'status'      => 'open',
            'sla_due_at'  => now()->addHours($resolve_hours),
        ]);

        // 留痕(审计: 谁/何时对哪个单发起配送申诉)
        try {
            \App\Models\Log::create([
                'logable_id'     => $order->id,
                'logable_type'   => \App\Models\Order::class,
                'action_type'    => 'delivery_appeal_opened',
                'model'          => 'Order',
                'model_id'       => $order->id,
                'action_details' => json_encode([
                    'appeal_id'   => $appeal->id,
                    'reason_code' => $appeal->reason_code,
                    'by_id'       => $appeal->user_id,
                    'at'          => now()->toDateTimeString(),
                ]),
                'before_state'   => $order->order_status,
                'after_state'    => $order->order_status,
                'restaurant_id'  => $order->restaurant_id,
            ]);
        } catch (\Throwable $e) {
            info('delivery_appeal log failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => '申诉已提交，平台已留痕。请同时联系商家核实是否已配送；如需平台介入，我们会按预计时间跟进。',
            'appeal'  => ['id' => $appeal->id, 'status' => $appeal->status, 'sla_due_at' => (string) $appeal->sla_due_at],
        ], 200);
    }

    public function refund_reasons(){
        $refund_reasons=RefundReason::where('status',1)->get();
        return response()->json([
            'refund_reasons' => $refund_reasons
        ], 200);
    }

    public function refund_request(Request $request)
    {
        if(BusinessSetting::where(['key'=>'refund_active_status'])->first()->value == false){
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('You can not request for a refund')]
                ]
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'customer_reason' => 'required|string|max:254',
            'refund_method'=>'nullable|string|max:100',
            'customer_note'=>'nullable|string|max:65535',
            'image.*' => 'nullable|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['user_id' => $user_id, 'id' => $request['order_id']])


            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->Notpos()->first();
        if(!$order){
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }

        // 哪吒 B方案 L1-1 纵深封堵(2026-06-22 QA辩论): 直付订单(顾客直付商家本人账户)平台不经手退款,
        // refund_request 钱包代退腿对直付单结构性关闭 —— 即便 refund_active_status 误开也不为直付单建钱包退款记录;
        // 顾客退款统一走「联系商家原路退」(NezhaRefundRecord 闭环)。叠加 refund_active_status=403 + Admin isDirectPay 护栏, 此为第4层纵深。
        if ($order->payment_method == 'offline_payment') {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => '您的款项是直接支付给商家的，退款由商家按您的原付款方式原路退回，平台不经手此款。请联系商家或客服处理。']
                ]
            ], 410);
        }

        if ($order->order_status == 'delivered' && $order->payment_status == 'paid') {

            $id_img_names = [];
            if ($request->has('image')) {
                foreach ($request->file('image') as $img) {
                    $image_name = Helpers::upload(dir:'refund/', format:'png', image:$img);
                    array_push($id_img_names, $image_name);
                }
                $images = json_encode($id_img_names);
            } else {
                $images = json_encode([]);
                // return response()->json(['message' => 'no_image'], 200);
            }

            $refund_amount = round($order->order_amount - $order->delivery_charge- $order->dm_tips , config('round_up_to_digit'));

            $refund = new Refund();
            $refund->order_id = $order->id;
            $refund->user_id = $order->user_id;
            $refund->order_status= $order->order_status;
            $refund->refund_status= 'pending';
            $refund->refund_method= $request->refund_method ?? 'wallet';
            $refund->customer_reason= $request->customer_reason;
            $refund->customer_note= $request->customer_note;
            $refund->refund_amount= $refund_amount;
            $refund->image = $images;
            $refund->save();

            $order->order_status = 'refund_requested';
            $order->refund_requested = now();
            $order->save();
            // Helpers::send_order_notification($order);

            $admin = Admin::where('role_id',1)->first();
            try {
                $notification_status= Helpers::getNotificationStatusData('admin','order_refund_request');

                if($notification_status?->mail_status == 'active' && config('mail.status') && $admin['email'] && Helpers::get_mail_status('refund_request_mail_status_admin') == '1') {
                    Mail::to($admin?->getRawOriginal('email'))->send(new RefundRequest($order->id));
                }
            } catch (\Exception $ex) {
                info($ex->getMessage());
            }
            return response()->json(['message' => translate('messages.refund_request_placed_successfully')], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.you_can_not_request_for_refund_after_delivery')]
            ]
        ], 403);
    }

    public function update_payment_method(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $config=Helpers::get_business_settings('cash_on_delivery');
        if($config['status']==0)
        {
            return response()->json([
                'errors' => [
                    ['code' => 'cod', 'message' => translate('messages.Cash on delivery order not available at this time')]
                ]
            ], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where(['user_id' => $user_id, 'id' => $request['order_id']])->where('is_guest', $request->user ? 0 : 1)->Notpos()->first();
        if ($order) {
            Order::where(['user_id' =>$user_id, 'id' => $request['order_id']])->where('is_guest', $request->user ? 0 : 1)->update([
                'payment_method' => 'cash_on_delivery', 'order_status'=>'pending', 'pending'=> now()
            ]);
            $order_mail_status = Helpers::get_mail_status('place_order_mail_status_user');
            $order_verification_mail_status = Helpers::get_mail_status('order_verification_mail_status_user');
            $address = json_decode($order->delivery_address, true);
            try {

                Helpers::send_order_notification($order);
                $notification_status= Helpers::getNotificationStatusData('customer','customer_order_notification');

                if($notification_status?->mail_status == 'active' && $order->is_guest == 0 && config('mail.status') && $order_mail_status == '1'&& $order->customer) {
                    Mail::to($order->customer?->getRawOriginal('email'))->send(new PlaceOrder($order->id));
                }
                if($notification_status?->mail_status == 'active' && $order->is_guest == 1 && config('mail.status') && $order_mail_status == '1' && isset($address['contact_person_email'])) {
                    Mail::to($address['contact_person_email'])->send(new PlaceOrder($order->id));
                }

            } catch (\Exception $e) {
                info($e->getMessage());
            }
            return response()->json(['message' => translate('messages.payment_method_updated_successfully')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.not_found')]
            ]
        ], 404);
    }

    public function cancellation_reason(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $limit = $request->query('limit', 1);
        $offset = $request->query('offset', 1);

        $reasons = OrderCancelReason::where('status', 1)->when($request->type,function($query) use($request){
            return $query->where('user_type',$request->type);
        })
            ->paginate($limit, ['*'], 'page', $offset);
        $data = [
            'total_size' => $reasons->total(),
            'limit' => $limit,
            'offset' => $offset,
            'reasons' => $reasons->items(),
        ];
        return response()->json($data, 200);
    }


    public function food_list(Request $request){

        $validator = Validator::make($request->all(), [
            'food_id' => 'required',
        ]);

        $food_ids= json_decode($request['food_id'], true);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $product = Food::active()->whereIn('id',$food_ids)->get();
        return response()->json(Helpers::product_data_formatting($product, true, false, app()->getLocale()), 200);
    }


    public function order_notification(Request $request,$order_id){
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where('user_id', $user_id)->where('id',$order_id)->where('is_guest', $request->user ? 0 : 1)->with(['restaurant','customer'])->first();
        if(!$order){
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        $payments = $order->payments()->where('payment_method','cash_on_delivery')->exists();
        $reload_home= false;
        $restaurant= $order?->restaurant;

        if($order && (!in_array($order->payment_method, ['digital_payment', 'partial_payment', 'offline_payment']) || $payments ) ){
             if ($restaurant?->is_valid_subscription == 1 && $restaurant?->restaurant_sub?->max_order != "unlimited" && $restaurant?->restaurant_sub?->max_order > 0) {
                    $restaurant?->restaurant_sub?->decrement('max_order', 1);
                    $reload_home=$restaurant?->restaurant_sub?->max_order <= 0 ?  true : false;
                }
                Helpers::send_order_notification($order);

                $address = json_decode($order['delivery_address'] , true);

                $email = $order->is_guest == 1 ? data_get($address,'contact_person_email')  : $order->customer?->email;
                $name = $order->is_guest == 1 ? data_get($address,'contact_person_name')  : $order->customer?->f_name.' '.$order->customer?->l_name;

                if (config('mail.status') && $email && $order->order_status == 'pending') {
                    try {
                        $notification_status= Helpers::getNotificationStatusData('customer','customer_order_notification');
                        if ($order->order_status == 'pending'  &&  Helpers::get_mail_status('place_order_mail_status_user') == '1' && $notification_status?->mail_status == 'active') {
                            Mail::to($email)->send(new PlaceOrder($order->id));
                        }
                        $notification_status=null;
                        $notification_status= Helpers::getNotificationStatusData('customer','customer_delivery_verification');
                        if (config('order_delivery_verification') == 1 && Helpers::get_mail_status('order_verification_mail_status_user') == '1'  && $notification_status?->mail_status == 'active') {
                            Mail::to($email)->send(new OrderVerificationMail($order->otp, $name));
                        }
                    }catch (\Exception $ex) {
                        info($ex);
                    }
                }
        }

        return response()->json([
            'reload_home' => $reload_home
        ], 200);
    }

    public function most_tips()
    {
        $data = Order::whereNot('dm_tips',0)->get()->mode('dm_tips');
        $data = ($data && (count($data)>0))?$data[0]:null;
        return response()->json([
            'most_tips_amount' => $data
        ], 200);
    }
    public function order_again(Request $request){
        Helpers::getZoneIds($request);

        $longitude= $request->header('longitude') ?? 0;
        $latitude= $request->header('latitude') ?? 0;
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $zone_id= json_decode($request->header('zoneId'), true);
        $data = Restaurant::withOpen($longitude,$latitude)->
        wherehas('orders' ,function($q) use($user_id){
            $q->where('user_id', $user_id)->where('is_guest' , 0)->latest();
        })

            ->withcount('foods')
            ->with(['foods_for_reorder'])
            ->Active()
            ->whereIn('zone_id', $zone_id)
            ->take(20)
            ->orderBy('open', 'desc')
            ->get()
            ->map(function ($data) {
                $data->foods = $data->foods_for_reorder->take(5);
                unset($data->foods_for_reorder);
                return $data;
            });
        return response()->json(Helpers::restaurant_data_formatting($data, true), 200);
    }

    public function offline_payment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'method_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $config = Helpers::get_mail_status('offline_payment_status');
        if ($config == 0) {
            return response()->json([
                'errors' => [
                    ['code' => 'offline_payment_status', 'message' => translate('messages.offline_payment_for_the_order_not_available_at_this_time')]
                ]
            ], 403);
        }
        $nezha_uid = $request->user ? $request->user->id : $request['guest_id'];
        $order = Order::where('id', $request->order_id)->where('user_id', $nezha_uid)
            ->when(!isset($request->user), function($query){ $query->where('is_guest', 1); })
            ->when(isset($request->user), function($query){ $query->where('is_guest', 0); })
            ->first();

        $offline_payment_info = [];
        $method = OfflinePaymentMethod::where(['id'=>$request->method_id,'status'=>1])->first();

        if(!$method || !$order ) {
            return response()->json([
                'errors' => [
                    ['code' => 'offline_payment_order_or_method', 'message' => translate('messages.offline_payment_order_or_method_not_found')]
                ]
            ], 403);
        }

        try{
            // 哪吒: 顾客凭证字段名取自 method_fields 的 input_field_name。
            // 本站 method_informations 列被用作纯文字说明(cast→null),沿用原逻辑会丢弃顾客输入(如USDT交易哈希),
            // 导致退款无法按原始 tx 反查原路。优先 method_fields,空时回退 method_informations(兼容标准StackFood配置)。
            $methodFields = $method->method_fields ?? [];
            $fields = array_column($methodFields, 'input_field_name');
            if (empty($fields)) {
                $fields = array_column($method->method_informations ?? [], 'customer_input');
            }
            // 哪吒: 字段类型/必填表 —— 用来区分文本字段(如USDT哈希)与文件截图字段(input_type==='file')。
            $fieldType = [];
            $fieldRequired = [];
            foreach ($methodFields as $mf) {
                if (isset($mf['input_field_name'])) {
                    $fieldType[$mf['input_field_name']] = $mf['input_type'] ?? 'text';
                    $fieldRequired[$mf['input_field_name']] = (int) ($mf['is_required'] ?? 0);
                }
            }
            $values = $request->all();

            $offline_payment_info['method_id'] = $request->method_id;
            $offline_payment_info['method_name'] = $method->method_name;
            foreach ($fields as $field) {
                if (($fieldType[$field] ?? 'text') === 'file') {
                    // 哪吒: 付款截图属PII。存到 public 磁盘 offline_payment/ 下,
                    // payment_info 内记【完整相对路径 offline_payment/xxx】,这样
                    // PurgePaymentProofs(90天到期清除)可按 public 磁盘路径精确找到并删除该文件。
                    $uploaded = $request->file($field);
                    if ($uploaded) {
                        // 哪吒: 截图只收图片, 且扩展名落在 purge/display 都覆盖的集合内 —— 杜绝 PII 清除盲区(L1-7)。
                        $ext = strtolower($uploaded->getClientOriginalExtension());
                        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                            return response()->json([
                                'errors' => [
                                    ['code' => $field, 'message' => '付款截图仅支持 PNG / JPG / GIF / WEBP 图片']
                                ]
                            ], 403);
                        }
                        $offline_payment_info[$field] = 'offline_payment/'.Helpers::upload('offline_payment/', 'png', $uploaded);
                    } elseif (($fieldRequired[$field] ?? 0) === 1) {
                        return response()->json([
                            'errors' => [
                                ['code' => $field, 'message' => '请上传'.$field]
                            ]
                        ], 403);
                    }
                } elseif (key_exists($field, $values)) {
                    $offline_payment_info[$field] = $values[$field];
                }
            }

            $OfflinePayments= OfflinePayments::firstOrNew(['order_id' => $order->id]);
            $OfflinePayments->payment_info =json_encode($offline_payment_info);
            $OfflinePayments->customer_note = $request->customer_note;
            $OfflinePayments->method_fields = json_encode($method?->method_fields);

            // 哪吒[自动核验]: 法币实付金额比对 + USDT 上链核验 + 图片软门标记。
            // 仅辅助商家判断真到账, 不改资金机制(平台不碰钱), 不阻断下单 —— 任何异常都吞掉。
            try {
                $autoCheck = ['method_name' => $method->method_name, 'checked_at' => now()->toIso8601String()];
                $expectedAmd = (float) ($order->order_amount ?? 0);
                $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
                $rateUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);
                $expectedUsdt = $rateUsd > 0 ? round($expectedAmd / $rateUsd, 2) : 0;
                $expectedRmb  = $rateCny > 0 ? round($expectedAmd / $rateCny) : 0;
                $isUsdtMethod = (bool) preg_match('/usdt/i', $method->method_name);
                $autoCheck['expected_amd'] = $expectedAmd;
                $autoCheck['expected_usdt'] = $expectedUsdt;
                $autoCheck['expected_rmb'] = $expectedRmb;

                // 顾客自报实付金额, 与应付比对(容差 3%)
                $paid = $request->input('nezha_paid_amount');
                if ($paid !== null && $paid !== '' && is_numeric($paid)) {
                    $paid = (float) $paid;
                    $autoCheck['paid_amount'] = $paid;
                    $expect = $isUsdtMethod ? $expectedUsdt : $expectedRmb;
                    $tol = max(1, $expect * 0.03);
                    $autoCheck['amount_match'] = ($expect > 0) ? (abs($paid - $expect) <= $tol) : null;
                }

                // 图片软门标记(前端 canvas 体检: 过小/疑似模糊/接近空白) —— 仅提示不拦
                $flags = json_decode((string) $request->input('nezha_image_flags'), true);
                if (is_array($flags) && !empty($flags)) {
                    $autoCheck['image_flags'] = $flags;
                }

                // USDT 上链核验
                if ($isUsdtMethod) {
                    $hash = null;
                    foreach ($offline_payment_info as $k => $v) {
                        if (in_array($k, ['method_id', 'method_name'], true)) continue;
                        if (is_string($v) && \App\CentralLogics\NezhaChainVerifier::isValidHashFormat($v)) { $hash = $v; break; }
                    }
                    $autoCheck['tx_hash'] = $hash;
                    if ($hash) {
                        $reused = OfflinePayments::where('order_id', '!=', $order->id)
                            ->where('payment_info', 'like', '%'.$hash.'%')->exists();
                        $rest = DB::table('restaurants')->where('id', $order->restaurant_id)->first();
                        // 按所选 USDT 方式确定链与应收地址: BEP20 方式→usdt_bep20_address; 否则波场TRC20→usdt_address。
                        $isBep20 = (bool) preg_match('/bep ?20|bsc|bnb/i', $method->method_name);
                        $expectNet = $isBep20 ? 'BEP20' : 'TRC20';
                        $expectAddr = $isBep20 ? ($rest->usdt_bep20_address ?? '') : ($rest->usdt_address ?? '');
                        $autoCheck['usdt_network'] = $expectNet;
                        $verify = \App\CentralLogics\NezhaChainVerifier::verifyUsdt(
                            $hash,
                            $expectAddr,
                            $expectedUsdt,
                            $expectNet
                        );
                        if ($reused) {
                            $verify['status'] = 'mismatch';
                            $verify['reason'] = '这笔交易哈希此前已被其它订单使用过，疑似重复冒认，请核对';
                            $verify['reused'] = true;
                        }
                        $autoCheck['chain'] = $verify;
                    } else {
                        $autoCheck['chain'] = ['status' => 'invalid_hash', 'reason' => '未填写有效交易哈希'];
                    }
                }
                $OfflinePayments->nezha_auto_check = $autoCheck;
            } catch (\Throwable $acEx) {
                info('nezha auto_check failed: '.$acEx->getMessage());
            }

            DB::beginTransaction();
            $OfflinePayments->save();
            $order->save();
            DB::commit();

            Helpers::sentAdminPanelNotification($order);

            return response()->json([
                'payment' => 'success'
            ], 200);


        } catch (\Exception $e) {
            DB::rollBack();
            // 哪吒(#3): 凭证上传/保存失败 → 后端兜底回滚刚建的订单(防 pending/unpaid 无凭证孤儿单)。
            // 比前端 onError 可靠: place 成功后前端已导航到订单详情、CheckoutPage 卸载致 react-query 的 onError 不触发(实测孤儿单残留)。
            try {
                $order->loadMissing('details');
                if (in_array($order->order_status, ['pending', 'failed'], true)) {
                    $order->order_status = 'canceled';
                    $order->canceled = now();
                    $order->canceled_by = 'system';
                    $order->cancellation_reason = '付款凭证上传失败，订单已自动回滚';
                    $order->save();
                    \App\CentralLogics\Helpers::decreaseSellCount(order_details: $order->details);
                }
            } catch (\Throwable $ex) { info('offline_payment rollback-cancel failed: '.$ex->getMessage()); }
            return response()->json([ 'payment' => $e->getMessage()], 403);
        }
    }


    public function update_offline_payment_info(Request $request){
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $info= OfflinePayments::where('order_id' , $request->order_id)->first();
        $nezha_uid = $request->user ? $request->user->id : $request['guest_id'];
        $order= Order::where('id', $request->order_id)->where('user_id', $nezha_uid)
            ->when(!isset($request->user), function($query){ $query->where('is_guest', 1); })
            ->when(isset($request->user), function($query){ $query->where('is_guest', 0); })
            ->first();

        if(!$info || !$order ) {
            return response()->json([
                'errors' => [
                    ['code' => 'offline_payment_order_or_method', 'message' => translate('messages.offline_payment_order_or_method_not_found')]
                ]
            ], 403);
        }
        $old_data =   json_decode($info->payment_info , true) ;
        $method_id= data_get($old_data,'method_id',null);
        $method = OfflinePaymentMethod::where('id', $method_id)->first();

        if(!$method ) {
            return response()->json([
                'errors' => [
                    ['code' => 'offline_payment_order_or_method', 'message' => translate('messages.offline_payment_method_not_found')]
                ]
            ], 403);
        }
        $offline_payment_info = [];
        // 哪吒: 同 offline_payment(), 字段名优先取 method_fields.input_field_name(回退 method_informations.customer_input)
        $methodFields = $method->method_fields ?? [];
        $fields = array_column($methodFields, 'input_field_name');
        if (empty($fields)) {
            $fields = array_column($method->method_informations ?? [], 'customer_input');
        }
        // 哪吒: 字段类型表(区分文本 vs 文件截图字段)
        $fieldType = [];
        foreach ($methodFields as $mf) {
            if (isset($mf['input_field_name'])) {
                $fieldType[$mf['input_field_name']] = $mf['input_type'] ?? 'text';
            }
        }
        $values = $request->all();
        $offline_payment_info['method_id'] =$method->id;
        $offline_payment_info['method_name'] = $method->method_name;
        foreach ($fields as $field) {
            if (($fieldType[$field] ?? 'text') === 'file') {
                // 哪吒: 截图字段。传了新文件→替换(并删旧文件防孤儿); 没传→保留原截图路径,避免编辑文本时丢截图。
                $uploaded = $request->file($field);
                if ($uploaded) {
                    // 哪吒: 同上, 截图扩展名限定在 purge/display 覆盖集合内(L1-7 防清除盲区)。
                    $ext = strtolower($uploaded->getClientOriginalExtension());
                    if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                        return response()->json([
                            'errors' => [['code' => $field, 'message' => '付款截图仅支持 PNG / JPG / GIF / WEBP 图片']]
                        ], 403);
                    }
                    try {
                        $newPath = 'offline_payment/'.Helpers::upload('offline_payment/', 'png', $uploaded);
                    } catch (\Throwable $e) {
                        return response()->json([
                            'errors' => [['code' => $field, 'message' => '付款截图上传失败，请重试']]
                        ], 403);
                    }
                    $old = data_get($old_data, $field);
                    if (is_string($old) && $old !== '' && $old !== $newPath) {
                        try { \Illuminate\Support\Facades\Storage::disk(Helpers::getDisk())->delete($old); } catch (\Throwable $e) {}
                    }
                    $offline_payment_info[$field] = $newPath;
                } else {
                    $old = data_get($old_data, $field);
                    if (is_string($old) && $old !== '') {
                        $offline_payment_info[$field] = $old;
                    }
                }
            } elseif (key_exists($field, $values)) {
                $offline_payment_info[$field] = $values[$field];
            }
        }

        $info->customer_note = $request->customer_note;
        $info->payment_info =json_encode($offline_payment_info);
        $info->status = 'pending';
        $info->save();

        return response()->json([ 'payment' => 'Payment_Info_Updated_successfully'], 200);
    }

    public function getPendingReviews(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $foodIds=[];
        $itemIds=[];

        $orderDetails= OrderDetail::whereOrderId($request->order_id)->get(['id','food_id', 'item_campaign_id','food_details']);
        foreach($orderDetails as $detail){
            $foodIds[]=$detail->food_id;
            $itemIds[]=$detail->item_campaign_id;
        }
        $reviews =   Review::whereOrderId($request->order_id)->where(function($query) use($foodIds ,$itemIds) {
            $query->whereIn('food_id',$foodIds)->orWhereIn('item_campaign_id',$itemIds);
        })->get(['id','food_id','item_campaign_id'])->toArray();

        $reviewedFoodIds = array_column($reviews, 'food_id');
        $reviewedItemIds = array_column($reviews, 'item_campaign_id');
        $storage = [];
        foreach($orderDetails as $detail){
            if(!in_array($detail->food_id, $reviewedFoodIds) || !in_array($detail->item_campaign_id, $reviewedItemIds)){
                $detail['food_details'] = json_decode($detail['food_details'], true);
                $storage[] = $detail;
            }
        }
        return response()->json(['details'=>$storage], 200);
    }


    private function createCashBackHistory($order_amount, $user_id,$order_id){
        $cashBack =  Helpers::getCalculatedCashBackAmount(amount:$order_amount, customer_id:$user_id);
        if(data_get($cashBack,'calculated_amount') > 0){
            $CashBackHistory = new CashBackHistory();
            $CashBackHistory->user_id = $user_id;
            $CashBackHistory->order_id = $order_id;
            $CashBackHistory->calculated_amount = data_get($cashBack,'calculated_amount');
            $CashBackHistory->cashback_amount = data_get($cashBack,'cashback_amount');
            $CashBackHistory->cash_back_id = data_get($cashBack,'id');
            $CashBackHistory->cashback_type = data_get($cashBack,'cashback_type');
            $CashBackHistory->min_purchase = data_get($cashBack,'min_purchase');
            $CashBackHistory->max_discount = data_get($cashBack,'max_discount');
            $CashBackHistory->save();

            $CashBackHistory?->order()->update([
                'cash_back_id'=> $CashBackHistory->id
            ]);
        }
        return true;
    }
    /**
     * [哪吒 B方案/组4 预存佣金扣佣] 判断某餐馆是否因预存佣金低于阈值而应停止接单。
     * 仅在开关 nezha_deposit_mode_status=1 时生效; 关闭(一阶段免佣免押)或餐馆不存在时返回 false。
     */
    public static function nezha_deposit_below_threshold($restaurant){
        if (!$restaurant) {
            return false;
        }
        $mode = BusinessSetting::where('key','nezha_deposit_mode_status')->first()?->value;
        if ($mode != 1) {
            return false;
        }
        $threshold = (float) (BusinessSetting::where('key','nezha_min_deposit_threshold')->first()?->value ?? 0);
        $balance = (float) (\App\Models\RestaurantWallet::where('vendor_id', $restaurant->vendor_id)->value('deposit_balance') ?? 0);
        return $balance <= $threshold;
    }

    public static function order_validation_check($request){
        $schedule_at = $request->schedule_at? Carbon::parse($request->schedule_at):now();

        $settings_key = ['wallet_status','partial_payment_status','offline_payment_status','digital_payment','guest_checkout_status','home_delivery',
            'take_away','cash_on_delivery','instant_order','dine_in_order_option'];

        $settings =  array_column(BusinessSetting::whereIn('key', $settings_key)->get()->toArray(), 'value', 'key');

        $restaurant = Restaurant::with(['discount', 'restaurant_sub','restaurant_config'])->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = '.$schedule_at->format('w').' and `restaurant_schedule`.`opening_time` < "'.$schedule_at->format('H:i:s').'" and `restaurant_schedule`.`closing_time` >"'.$schedule_at->format('H:i:s').'") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        // [哪吒 B方案/组4 预存佣金扣佣] 开关(nezha_deposit_mode_status)开启时, 预存佣金低于阈值的餐馆停止接收新单。
        // 开关关闭(一阶段免佣免押)时 $nezha_deposit_low 恒为 false, 不影响接单。
        $nezha_deposit_low = self::nezha_deposit_below_threshold($restaurant);
        $nezha_order_suspended = \App\CentralLogics\NezhaRefundOverdue::is_suspended($restaurant); // 哪吒: 退款逾期被运营暂停接单(非资金)

        $response = match (true) {
            !$restaurant => [
                'code' => 'restaurant',
                'message' =>  'restaurant_not_found',
                'status' => 403
            ],
            in_array($restaurant->restaurant_model,['unsubscribed','none']) || ( in_array($restaurant->restaurant_model,['subscription']) && $restaurant?->restaurant_sub == null) || (in_array($restaurant->restaurant_model,['subscription']) && $restaurant?->restaurant_sub?->max_order != "unlimited" && $restaurant?->restaurant_sub?->max_order <= 0 ) => [
                'code' => 'restaurant',
                'message' =>  'Sorry_the_restaurant_is_unable_to_take_any_order',
                'status' => 403
            ],
            $nezha_deposit_low => [
                'code' => 'restaurant',
                'message' => translate('该店休息中，暂时无法下单，请稍后再来'),
                'status' => 403
            ],
            $nezha_order_suspended => [
                'code' => 'restaurant',
                'message' => translate('该商家暂时停止接单，请稍后再试或更换其他商家'),
                'status' => 403
            ],
            $request->schedule_at && $request->order_type != 'dine_in'  && !$restaurant->schedule_order => [
                'code' => 'schedule_at',
                'message' => 'schedule_order_not_available',
                'status' => 403
            ],
            $restaurant->open == false && !$request->subscription_order => [
                'code' => 'schedule_at',
                'message' => 'restaurant_is_closed_at_order_time',
                'status' => 403
            ],

            $request->payment_method == 'wallet' && ($settings['wallet_status'] ?? 0) != 1 => [
                'code' => 'payment_method',
                'message' => 'customer_wallet_disable_warning',
                'status' => 403
            ],
            $request->partial_payment && ($settings['partial_payment_status'] ?? 0) == 0 => [
                'code' => 'order_method',
                'message' => 'partial_payment_is_not_active',
                'status' => 403
            ],
            $request->payment_method == 'offline_payment' && ($settings['offline_payment_status'] ?? 0) == 0 => [
                'code' => 'offline_payment_status',
                'message' =>  'offline_payment_for_the_order_not_available_at_this_time',
                'status' => 403
            ],
            json_decode($settings['digital_payment'] ?? '', true)['status'] == 0 && $request->payment_method == 'digital_payment' => [
                'code' => 'digital_payment',
                'message' => 'digital_payment_for_the_order_not_available_at_this_time',
                'status' => 403
            ],
            $request->is_guest && ($settings['guest_checkout_status'] ?? 0)  == 0 => [
                'code' => 'is_guest',
                'message' => 'Guest_order_is_not_active',
                'status' => 403
            ],
            ($settings['home_delivery'] ?? 0) == 0 && $request->order_type == 'delivery' => [
                'code' => 'home_delivery',
                'message' => 'Home_delivery_is_disabled',
                'status' => 403
            ],
            ($settings['take_away'] ?? 0) == 0 && $request->order_type == 'take_away' => [
                'code' => 'take_away',
                'message' =>  'Take_away_is_disabled',
                'status' => 403
            ],
            $request->order_type != 'dine_in' && ((($settings['instant_order'] ?? 0) != 1 ||  $restaurant->restaurant_config?->instant_order != 1 ) && !$request->schedule_at && !$request->subscription_order)   => [
                'code' => 'instant_order',
                'message' => 'instant_order_is_not_available_for_now!',
                'status' => 403
            ],
            ($settings['dine_in_order_option'] ?? 0) == 0 && $request->order_type == 'dine_in' => [
                'code' => 'dine_in',
                'message' =>  'Dine_in_is_disabled',
                'status' => 403
            ],
            json_decode($settings['cash_on_delivery'] ?? '', true)['status'] != 1 && $request->payment_method == 'cash_on_delivery' => [
                'code' => 'order_time',
                'message' => 'Cash_on_delivery_is_not_active',
                'status' => 403
            ],

            $request->schedule_at && $schedule_at < now() => [
                'code' => 'order_time',
                'message' =>  'you_can_not_schedule_a_order_in_past',
                'status' => 403
            ],
            $request->schedule_at && $request->order_type == 'dine_in' && Carbon::now()->add($restaurant?->restaurant_config?->schedule_advance_dine_in_booking_duration, $restaurant?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format) > $schedule_at => [
                'code' => 'order_time',
                'message' => 'Dine_date_is_not_available',
                'status' => 403
            ],

            default => null
        };

        if ($response) {
            return ['code' => $response['code'],'message' => translate($response['message']),'status_code'=> $response['status']];
        }

        return $restaurant;
    }



    public static function claculate_original_delivery_fee($request , $restaurant, $delivery_charge,$free_delivery_by){

        if(in_array($request['order_type'],['take_away','dine_in']))
        {
            return ['max_cod_order_amount_value' => 0,'vehicle_id' => null,'original_delivery_charge' => 0 ,'delivery_charge' => $delivery_charge];
        }

        $per_km_shipping_charge = 0;
        $minimum_shipping_charge = 0;
        $maximum_shipping_charge =  0;
        $max_cod_order_amount_value=  0;
        $increased=0;

        $data = Helpers::vehicle_extra_charge(distance_data:$request->distance);
        $extra_charges = (float) (isset($data) ? $data['extra_charge']  : 0);
        $vehicle_id= (isset($data) ? (int) $data['vehicle_id']  : null);

        if($request->latitude && $request->longitude){
            $zone = Zone::where('id', $restaurant->zone_id)->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))->first();
            if(!$zone)
            {
                return ['code' => 'coordinates', 'message' => translate('messages.out_of_coverage') , 'status_code' => 403];
            }
            if( $zone->per_km_shipping_charge && $zone->minimum_shipping_charge ) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
                $maximum_shipping_charge = $zone->maximum_shipping_charge;
                $max_cod_order_amount_value= $zone->max_cod_order_amount;
                if($zone->increased_delivery_fee_status == 1){
                    $increased=$zone->increased_delivery_fee ?? 0;
                }
            }
        }

        if(!in_array($request['order_type'],['take_away','dine_in'])  && !$restaurant->free_delivery &&  !isset($delivery_charge) && ($restaurant->restaurant_model == 'subscription' && isset($restaurant->restaurant_sub) && $restaurant->restaurant_sub->self_delivery == 1  || $restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1 )){
            $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
            $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            $maximum_shipping_charge = $restaurant->maximum_shipping_charge;
            $extra_charges= 0;
            $vehicle_id=null;
            $increased=0;
        }

        if($restaurant->free_delivery || $free_delivery_by == 'vendor' ){
            $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
            $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            $maximum_shipping_charge = $restaurant->maximum_shipping_charge;
            $extra_charges= 0;
            $vehicle_id=null;
            $increased=0;
        }

        $original_delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge + $extra_charges  : $minimum_shipping_charge + $extra_charges;

        if ($maximum_shipping_charge  > $minimum_shipping_charge  && $original_delivery_charge >  $maximum_shipping_charge ){
            $original_delivery_charge = $maximum_shipping_charge;
        }
        else{
            $original_delivery_charge = $original_delivery_charge;
        }

        if(!isset($delivery_charge)){
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
            if ( $maximum_shipping_charge  > $minimum_shipping_charge  && $delivery_charge + $extra_charges >  $maximum_shipping_charge ){
                $delivery_charge =$maximum_shipping_charge;
            }
            else{
                $delivery_charge =$extra_charges + $delivery_charge;
            }
        }
        if($increased > 0 ){
            if($delivery_charge > 0){
                $increased_fee = ($delivery_charge * $increased) / 100;
                $delivery_charge = $delivery_charge + $increased_fee;
            }
            if($original_delivery_charge > 0){
                $increased_fee = ($original_delivery_charge * $increased) / 100;
                $original_delivery_charge = $original_delivery_charge + $increased_fee;
            }
        }
        return ['max_cod_order_amount_value' => $max_cod_order_amount_value,'vehicle_id' => $vehicle_id,'original_delivery_charge' => $original_delivery_charge,'delivery_charge' => $delivery_charge ];
    }

    public function check_restaurant_validation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_type' => 'required|in:take_away,delivery,dine_in',
            'restaurant_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $schedule_at = $request->schedule_at? Carbon::parse($request->schedule_at):now();

        $settings_key = ['instant_order'];

        $settings =  array_column(BusinessSetting::whereIn('key', $settings_key)->get()->toArray(), 'value', 'key');

        $restaurant = Restaurant::with(['discount', 'restaurant_sub','restaurant_config'])->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = '.$schedule_at->format('w').' and `restaurant_schedule`.`opening_time` < "'.$schedule_at->format('H:i:s').'" and `restaurant_schedule`.`closing_time` >"'.$schedule_at->format('H:i:s').'") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        // [哪吒 B方案/组4 预存佣金扣佣] 结算预检同样拦预存佣金不足的餐馆(开关关闭时恒 false)。
        $nezha_deposit_low = self::nezha_deposit_below_threshold($restaurant);
        $nezha_order_suspended = \App\CentralLogics\NezhaRefundOverdue::is_suspended($restaurant); // 哪吒: 退款逾期被运营暂停接单(非资金)

        $response = match (true) {
            !$restaurant => [
                'code' => 'restaurant',
                'message' =>  'restaurant_not_found',
                'status' => 403
            ],
            in_array($restaurant->restaurant_model,['unsubscribed','none']) || ( in_array($restaurant->restaurant_model,['subscription']) && $restaurant?->restaurant_sub == null) || (in_array($restaurant->restaurant_model,['subscription']) && $restaurant?->restaurant_sub?->max_order != "unlimited" && $restaurant?->restaurant_sub?->max_order <= 0 ) => [
                'code' => 'restaurant',
                'message' =>  'Sorry_the_restaurant_is_unable_to_take_any_order',
                'status' => 403
            ],

            $nezha_deposit_low => [
                'code' => 'restaurant',
                'message' => translate('该店休息中，暂时无法下单，请稍后再来'),
                'status' => 403
            ],
            $nezha_order_suspended => [
                'code' => 'restaurant',
                'message' => translate('该商家暂时停止接单，请稍后再试或更换其他商家'),
                'status' => 403
            ],

            $request->schedule_at && $request->order_type != 'dine_in'  && !$restaurant->schedule_order => [
                'code' => 'schedule_at',
                'message' => 'schedule_order_not_available',
                'status' => 403
            ],

            $restaurant->open == false && !$request->subscription_order => [
                'code' => 'schedule_at',
                'message' => 'restaurant_is_closed_at_order_time',
                'status' => 403
            ],

            $request->order_type != 'dine_in' && ((($settings['instant_order'] ?? 0) != 1 ||  $restaurant->restaurant_config?->instant_order != 1 ) && !$request->schedule_at && !$request->subscription_order)   => [
                'code' => 'instant_order',
                'message' => 'instant_order_is_not_available_for_now!',
                'status' => 403
            ],

            $request->schedule_at && $schedule_at < now() => [
                'code' => 'order_time',
                'message' =>  'you_can_not_schedule_a_order_in_past',
                'status' => 403
            ],
            $request->schedule_at && $request->order_type == 'dine_in' && Carbon::now()->add($restaurant?->restaurant_config?->schedule_advance_dine_in_booking_duration, $restaurant?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format) > $schedule_at => [
                'code' => 'order_time',
                'message' => 'Dine_date_is_not_available',
                'status' => 403
            ],

            default => null
        };

        if ($response) {
            return response()->json([
                'errors' => [
                    ['code' => $response['code'], 'message' => translate($response['message'])]
                ]
            ], $response['status']);
        }

        return response()->json([], 200);
    }

    public function getTaxFromCart(Request $request)
    {
        return $this->getCalculatedTax($request);
    }

    private function get_saver_delivery_time($order)
    {
        $saver_delivery_time = null;
        if($order->delivery_type && $order->order_type == 'delivery') {
            $deliveryOption = ZoneDeliveryOption::where('delivery_type', $order->delivery_type)->where('zone_id', $order->zone_id)->first();
            if($deliveryOption) {
                $restaurant_delivery_time = $order->restaurant?->delivery_time;
                if ($restaurant_delivery_time) {
                    $time_array = explode('-', $restaurant_delivery_time);
                    if(count($time_array) == 2) {
                        $min = (int) $time_array[0];
                        $max = (int) $time_array[1];
                        $unit = strpos($time_array[1], 'hour') !== false ? 'hour' : 'min';

                        if($unit == 'min'){
                            if($order->delivery_type == 'express') {
                                $min = max(0, $min - $deliveryOption->reduce_delivery_time);
                                $max = max(0, $max - $deliveryOption->reduce_delivery_time);
                            } elseif ($order->delivery_type == 'slightly_delay') {
                                $min += $deliveryOption->add_delivery_time;
                                $max += $deliveryOption->add_delivery_time;
                            }
                            $saver_delivery_time = $min.'-'.$max.' '.$unit;
                        } else {
                            $saver_delivery_time = $restaurant_delivery_time;
                        }
                    }
                }
            }
        }
        return $saver_delivery_time;
    }
}
