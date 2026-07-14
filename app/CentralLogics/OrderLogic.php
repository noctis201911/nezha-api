<?php

namespace App\CentralLogics;

use Exception;
use App\Models\User;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\Incentive;
use App\Models\Restaurant;
use App\Models\AdminWallet;
use App\Models\DeliveryMan;
use App\Models\IncentiveLog;
use App\Models\OrderPayment;
use App\Models\Subscription;
use App\Models\BusinessSetting;
use App\Models\SubscriptionLog;
use App\Models\OrderTransaction;
use App\Models\RestaurantWallet;
use App\Models\DeliveryManWallet;
use App\Models\AccountTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderLogic
{
    /**
     * [哪吒 B方案/组4 佣金展示] 计算某订单「平台应收佣金」。
     *
     * ⚠️ 本方法是 create_transaction() 内 $comission_amount 计算的【只读镜像】, 公式必须保持一致:
     *   - 费率   : $comission = 餐馆 comission ?? 全局 admin_commission
     *   - 计佣基数: $order_amount(净商品额: 订单总额扣各项费/税/小费, 加回券与 admin 出资折扣/首单返)
     *   - 佣金额 : ($order_amount / 100) * 费率; 订阅制餐馆免佣 = 0
     * 仅供 admin / 商家 订单详情页【只读展示】, 本身不扣款、无副作用。
     * 改 create_transaction() 佣金公式时务必同步本方法 + tests/Feature/NezhaCommissionTest.php(测试锁公式)。
     *
     * @return array{rate:float, base:float, amount:float, subscription:bool}
     */
    public static function nezha_commissionable_amount($order): array
    {
        $restaurant = $order->restaurant ?? null;
        $rest_sub   = $restaurant?->restaurant_sub;

        // 费率: 餐馆自定 comission 优先(含 0%), 未设则用全局 admin_commission。镜像 create_transaction 费率行。
        $rate = !isset($restaurant?->comission)
            ? (float) (\App\Models\BusinessSetting::where('key', 'admin_commission')->first()?->value ?? 0)
            : (float) $restaurant->comission;

        // 订阅制(月费)餐馆免佣。镜像 create_transaction 订阅分支。
        if ($restaurant && $restaurant->restaurant_model == 'subscription' && isset($rest_sub)) {
            return ['rate' => 0.0, 'base' => 0.0, 'amount' => 0.0, 'subscription' => true];
        }

        // 计佣基数 = 净商品额。镜像 create_transaction $order_amount 行:
        // 仅当折扣由 admin 出资(discount_on_product_by=='admin')时, restaurant_discount_amount 计回基数。
        $restaurant_discount_addback = (($order->discount_on_product_by ?? null) == 'admin')
            ? (float) ($order->restaurant_discount_amount ?? 0) : 0.0;

        $base = (float) $order->order_amount
            - (float) ($order->additional_charge ?? 0)
            - (float) ($order->extra_packaging_amount ?? 0)
            - (float) ($order->delivery_charge ?? 0)
            - (float) ($order->total_tax_amount ?? 0)
            - (float) ($order->dm_tips ?? 0)
            - (float) ($order->delivery_type_charge ?? 0)
            + (float) ($order->coupon_discount_amount ?? 0)
            + $restaurant_discount_addback
            + (float) ($order->ref_bonus_amount ?? 0);

        // 加急/稍后送二次调整。镜像 create_transaction express/slightly_delay 分支。
        if (($order->delivery_type ?? null) === 'express') {
            $base -= (float) ($order->delivery_type_charge ?? 0);
        } elseif (($order->delivery_type ?? null) === 'slightly_delay') {
            $base += (float) ($order->delivery_type_charge ?? 0);
        }

        $amount = $rate ? ($base / 100) * $rate : 0.0;

        return ['rate' => $rate, 'base' => $base, 'amount' => $amount, 'subscription' => false];
    }

    // [哪吒 退出结算 §C5/DESIGN A6] $offboard_settle=true 表示本次结算由「商家退出结算」控制流(NezhaOffboard)驱动:
    //   此时即使店处于 settling 冻结态, 也要把在途单的佣金落到 commission_deduction(受控收净), 故绕过 C2 冻结闸。
    //   活线路径(顾客确认/超时兜底/商家标记, 默认 false)一律维持 C2 冻结行为不变。
    public static function create_transaction($order, $received_by=false, $status = null, $offboard_settle = false)
    {
        $comission = !isset($order?->restaurant?->comission)?\App\Models\BusinessSetting::where('key','admin_commission')->first()?->value:$order?->restaurant?->comission;

        $admin_subsidy = 0;
        $amount_admin = 0;
        $restaurant_d_amount = 0;
        $admin_coupon_discount_subsidy =0;
        $restaurant_subsidy =0;
        $restaurant_coupon_discount_subsidy =0;
        $restaurant_discount_amount=0;
        $restaurant= $order->restaurant;
        $rest_sub = $restaurant?->restaurant_sub;
        $ref_bonus_amount=0;
        $restaurant_amount=0;
        // [哪吒 B方案/组3 拔二清腿] 直付订单 = 顾客直接付款给商家本人(支付宝/USDT), 平台全程不碰钱。
        // 按 COD 思路记账: 不累加 total_earning(平台不欠商家/永不打款)、不记平台收款(digital_received),
        // 仅保留应收佣金(order_transaction.admin_commission + adminWallet->total_commission_earning), 留给组4预存佣金扣佣去收。
        $is_direct_pay = ($order->payment_method == 'offline_payment');

        // free delivery by admin
        if($order->free_delivery_by == 'admin')
        {
            $admin_subsidy = $order->original_delivery_charge;
            Helpers::expenseCreate( amount:$order->original_delivery_charge, type:'free_delivery',datetime:now(),order_id:  $order->id,created_by:  $order->free_delivery_by);
        }
        // free delivery by restaurant
        if($order->free_delivery_by == 'vendor')
        {
            $restaurant_subsidy = $order->original_delivery_charge;
            Helpers::expenseCreate( amount:$order->original_delivery_charge,type:'free_delivery',datetime:now(),order_id:  $order->id,created_by:  $order->free_delivery_by,restaurant_id:$order?->restaurant?->id);
        }
        // coupon discount by Admin
        if($order->coupon_created_by == 'admin')
        {
            $admin_coupon_discount_subsidy = $order->coupon_discount_amount;
            Helpers::expenseCreate( amount:$admin_coupon_discount_subsidy,type:'coupon_discount',datetime:now(),order_id:  $order->id,created_by:  $order->coupon_created_by);
        }
        // 1st order discount by Admin
        if($order->ref_bonus_amount > 0)
        {
            $ref_bonus_amount = $order->ref_bonus_amount;
            Helpers::expenseCreate(amount:$ref_bonus_amount,type:'referral_discount',datetime:now(),created_by:'admin',order_id:$order->id);
        }

        if($order->delivery_type_charge > 0 && $order->delivery_type == 'slightly_delay')
        {
            $delivery_type_charge = $order->delivery_type_charge;
            Helpers::expenseCreate(amount:$delivery_type_charge,type:'slightly_delay_delivery_charge',datetime:now(),created_by:'admin',order_id:$order->id);
        }

        // coupon discount by restaurant
        if($order->coupon_created_by == 'vendor')
        {
            $restaurant_coupon_discount_subsidy = $order->coupon_discount_amount;
            Helpers::expenseCreate( amount:$restaurant_coupon_discount_subsidy,type:'coupon_discount',datetime:now(),order_id:  $order->id,created_by:  $order->coupon_created_by, restaurant_id:$order?->restaurant?->id);
        }

        if($order->restaurant_discount_amount > 0  && $order->discount_on_product_by == 'vendor')
        {
            if($restaurant->restaurant_model == 'subscription' && isset($rest_sub)){
                $restaurant_d_amount=  $order->restaurant_discount_amount;
                Helpers::expenseCreate( amount:$restaurant_d_amount,type:'discount_on_product',datetime:now(),order_id:  $order->id,created_by:  'vendor',restaurant_id:$order?->restaurant?->id);
            } else{
                $amount_admin = 0; // 哪吒[折扣账务定性·L1 2026-07-02] 商家自掏折扣(满减/POS)100%记vendor·平台0出资; 佣金已按减后额计(平台少收D×率=让利分摊·属"少收"非"支出"),不得再记admin_expense否则报表重复扣净利+虚显平台补贴,冲突L1"平台不出资"。见INVARIANTS+CHANGELOG 2026-07-02。
                $restaurant_d_amount=  $order->restaurant_discount_amount- $amount_admin;
                Helpers::expenseCreate( amount:$restaurant_d_amount,type:'discount_on_product',datetime:now(),order_id:  $order->id,created_by:  'vendor',restaurant_id:$order?->restaurant?->id);
                // 哪吒[L1 2026-07-02] admin 出资拆分已取消($amount_admin=0), 不再写 admin discount_on_product 行。
            }
        }

        if($order->restaurant_discount_amount > 0  && $order->discount_on_product_by == 'admin')
        {
            $restaurant_discount_amount=$order->restaurant_discount_amount;
            Helpers::expenseCreate( amount:$restaurant_discount_amount,type:'discount_on_product',datetime:now(),order_id:  $order->id,created_by:  'admin');
        }


        if($order?->cashback_history){
            self::cashbackToWallet($order);
        }


        // [哪吒 B方案/组4] 计佣基数(净商品额)+ 佣金额 统一走唯一纯函数 nezha_commissionable_amount(),
        // 与 admin/商家订单详情展示行同源, 杜绝"两份公式各自漂移"。$order_amount 即计佣基数,
        // 含 express/slightly_delay 二次调整与 admin 出资折扣 addback, 与原内联公式逐分支等值。
        $nz_comm = self::nezha_commissionable_amount($order);
        $order_amount = $nz_comm['base'];
        $comission_amount = $nz_comm['amount'];
        $subscription_mode = $nz_comm['subscription'] ? 1 : 0;
        $commission_percentage = $nz_comm['subscription'] ? 0 : $comission;

        if(($restaurant->restaurant_model == 'subscription' &&  $rest_sub?->self_delivery == 1) || ($restaurant->restaurant_model != 'subscription' && $restaurant->self_delivery_system)){
            $comission_on_delivery =0;
            $comission_on_actual_delivery_fee =0;

            if($order->free_delivery_by == 'admin'){
                $restaurant_amount = $order->original_delivery_charge ?? 0;
            }
        }
        else{
            $delivery_charge_comission_percentage = BusinessSetting::where('key', 'delivery_charge_comission')->first()?->value ?? 0;

            $comission_on_delivery = $delivery_charge_comission_percentage * ( $order->original_delivery_charge / 100 );
            $comission_on_actual_delivery_fee = ($order->delivery_charge > 0) ? $comission_on_delivery : 0;
            $delivery_fee_comission = $comission_on_delivery;
        }
        $restaurant_amount =$restaurant_amount+ $order_amount + $order->total_tax_amount + $order->extra_packaging_amount - $comission_amount - $restaurant_coupon_discount_subsidy ;
        try{
            // [哪吒 双扣佣底座 · task_fb41eea8 / DESIGN_merchant_offboard A6·C5] order_transactions.order_id 有 UNIQUE 约束。
            // 并发结算(顾客确认 settle_delivered / 超时兜底 cron / 商家 status())可能同时越过上游 exists() 幂等闸后进到这里,
            // 只有先到者能 insert 成功, 后到者撞唯一键抛 1062 → 下面 catch 当幂等跳过并 return false(不进扣佣/改钱包/推状态),
            // 保证 commission_deduction 对每单恰好扣一次。⚠️ 勿删 order_transactions.order_id 的 UNIQUE 约束: C5 并发结算安全依赖它。
            try {
            OrderTransaction::insert([
                'vendor_id' =>$order->restaurant->vendor->id,
                'delivery_man_id'=>$order->delivery_man_id,
                'order_id' =>$order->id,
                'order_amount'=>$order->order_amount,
                'restaurant_amount'=>$restaurant_amount,
                'admin_commission'=>$comission_amount + $order->additional_charge -  $admin_subsidy - $admin_coupon_discount_subsidy - $ref_bonus_amount - $restaurant_discount_amount,
                //add a new column. add the comission here
                'delivery_charge'=>$order->delivery_charge - $comission_on_actual_delivery_fee,//minus here
                'original_delivery_charge'=>$order->original_delivery_charge - $comission_on_delivery,//calculate the comission with this. minus here
                'tax'=>$order->total_tax_amount,
                'received_by'=> $received_by?$received_by:'admin',
                'zone_id'=>$order->zone_id,
                'status'=> $status,
                'dm_tips'=> $order->dm_tips,
                'created_at' => now(),
                'updated_at' => now(),
                'delivery_fee_comission'=> $delivery_fee_comission ?? 0,
                'admin_expense'=>$admin_subsidy + $admin_coupon_discount_subsidy + $restaurant_discount_amount + $amount_admin + $ref_bonus_amount + ($order->delivery_type === 'slightly_delay' ? $order->delivery_type_charge : 0),
                'restaurant_expense'=>$restaurant_subsidy + $restaurant_coupon_discount_subsidy ,
                // for restaurant business model
                'is_subscribed'=> $subscription_mode,
                'commission_percentage'=> $commission_percentage,
                'discount_amount_by_restaurant' => $restaurant_coupon_discount_subsidy + $restaurant_d_amount + $restaurant_subsidy,
                // for subscription order
                'is_subscription' => isset($order->subscription_id) ?  1 : 0 ,
                'additional_charge' => $order->additional_charge,
                'extra_packaging_amount' => $order->extra_packaging_amount,
                'ref_bonus_amount' => $order->ref_bonus_amount,
            ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // 1062 = MySQL 唯一键冲突: 本单已被并发的另一路结算记过流水, 当幂等跳过(不重复扣佣)。
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    info('nezha 双扣佣防护: order_transaction 已存在(并发结算), 幂等跳过 order#' . $order->id);
                    return false;
                }
                throw $e; // 其它 DB 错误交由外层 catch 记录并 return false, 行为不变
            }
            $adminWallet = AdminWallet::firstOrNew(
                ['admin_id' => Admin::where('role_id', 1)->first()->id]
            );
            $vendorWallet = RestaurantWallet::firstOrNew(
                ['vendor_id' => $order->restaurant->vendor->id]
            );
            if($order->delivery_man &&
           (($restaurant->restaurant_model == 'subscription' &&  $rest_sub?->self_delivery == 0) || ($restaurant->restaurant_model != 'subscription' && $restaurant->self_delivery_system == 0))
            ){
                $dmWallet = DeliveryManWallet::firstOrNew(
                    ['delivery_man_id' => $order->delivery_man_id]
                );
                if (isset($order->delivery_man->earning) && $order->delivery_man->earning == 1) {
                    self::check_incentive($order->zone_id, $order->delivery_man_id, $order->delivery_man->todays_earning()->sum('original_delivery_charge'), $order->delivery_man->incentive);

                    $dmWallet->total_earning = $dmWallet->total_earning + $order->dm_tips + $order->original_delivery_charge - $comission_on_delivery;
                } else {
                    $adminWallet->total_commission_earning = $adminWallet->total_commission_earning + $order->dm_tips + $order->original_delivery_charge - $comission_on_delivery;
                }
            }

            $adminWallet->total_commission_earning = $adminWallet->total_commission_earning + $comission_amount + $comission_on_actual_delivery_fee - $admin_subsidy - $admin_coupon_discount_subsidy -$restaurant_discount_amount + $order->additional_charge  - $ref_bonus_amount;

            if($order->delivery_type_charge > 0 && $order->delivery_type == 'express'){
                $adminWallet->total_commission_earning = $adminWallet->total_commission_earning + $order->delivery_type_charge;
            }

            if(($restaurant->restaurant_model == 'subscription' &&  $rest_sub?->self_delivery == 1) || ($restaurant->restaurant_model != 'subscription' && $restaurant->self_delivery_system == 1))
            {
                $vendorWallet->total_earning = $vendorWallet->total_earning + $order->delivery_charge + $order->dm_tips;
            }
            else{
                $adminWallet->delivery_charge = $adminWallet->delivery_charge + $order->delivery_charge - $comission_on_actual_delivery_fee;
            }
            // [哪吒 B方案/组3] 直付订单不累加 total_earning(平台不欠商家、永不打款给商家); 其余订单维持原账务。
            if(!$is_direct_pay){
                $vendorWallet->total_earning = $vendorWallet->total_earning + $restaurant_amount;
            }
            try
            {
                DB::beginTransaction();
                $unpaid_payment = OrderPayment::where('payment_status','unpaid')->where('order_id',$order->id)->first()?->payment_method;
                $unpaid_pay_method = 'digital_payment';
                if($unpaid_payment){
                    $unpaid_pay_method = $unpaid_payment;
                }

                if($is_direct_pay)
                {
                    // [哪吒 B方案/组3] 顾客直付商家本人, 平台不收款: 不记 digital_received, 也不记 collected_cash。
                    // 平台应收佣金已在上方 adminWallet->total_commission_earning + order_transaction.admin_commission 记下。
                    // [组4 预存佣金扣佣] 开关(nezha_deposit_mode_status)开启时, 从商家预存佣金扣除本单"向商家收的佣金"。
                    // 注: 服务费(向客户收)不在此扣; 佣金率沿用现成 admin_commission/餐馆 comission(后台可调)。
                    // [哪吒 单店抽佣] 仅当该店启用抽佣(总开关+单店开关皆开)时扣佣; 单一真相源 OrderController::nezha_commission_active。
                    // [哪吒 退出结算 §C2/§C5] 冻结态(settling)默认停扣佣(is_frozen_id)保结算窗口 deposit 稳定;
                    //   仅当 $offboard_settle=true(结算控制流受控收在途佣金, §C5「归零在途佣金」)时绕过冻结闸。
                    if(\App\Http\Controllers\Api\V1\OrderController::nezha_commission_active($order->restaurant) && ($offboard_settle || !\App\CentralLogics\NezhaOffboard::is_frozen_id($order->restaurant_id)) && $comission_amount > 0){
                        // F-3 防并发 lost-update: 事务内 lockForUpdate 读最新余额, 串行化同商家并发扣减; 由函数末尾 $vendorWallet->save() 落库。
                        $freshBalance = (float) (RestaurantWallet::where('vendor_id', $order->restaurant->vendor->id)->lockForUpdate()->value('deposit_balance') ?? 0);
                        $vendorWallet->deposit_balance = $freshBalance - $comission_amount;
                        \App\Models\RestaurantDepositTransaction::insert([
                            'vendor_id'     => $order->restaurant->vendor->id,
                            'restaurant_id' => $order->restaurant->id,
                            'order_id'      => $order->id,
                            'type'          => 'commission_deduction',
                            'amount'        => -1 * $comission_amount,
                            'commission'    => $comission_amount,
                            'balance_after' => $vendorWallet->deposit_balance,
                            'note'          => '订单#'.$order->id.' 佣金扣除',
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]);
                    }
                }
                else if($received_by=='admin')
                {
                    $adminWallet->digital_received = $adminWallet->digital_received + ($order->order_amount - $order->partially_paid_amount);
                }
                else if($received_by=='restaurant' && ($order->payment_method == "cash_on_delivery" || $unpaid_pay_method == 'cash_on_delivery'))
                {

                    $restaurant_over_flow =  true ;
                    $vendorWallet->collected_cash = $vendorWallet->collected_cash + ($order->order_amount - $order->partially_paid_amount);
                }
                else if($received_by==false)
                {
                    $adminWallet->manual_received = $adminWallet->manual_received + ($order->order_amount - $order->partially_paid_amount);
                }
                else if($received_by=='deliveryman' && $order->delivery_man->type == 'zone_wise' && ($order->payment_method == "cash_on_delivery" || $unpaid_pay_method == 'cash_on_delivery'))
                {
                    if(!isset($dmWallet)) {
                        $dmWallet = DeliveryManWallet::firstOrNew(
                            ['delivery_man_id' => $order->delivery_man_id]
                        );
                    }

                $dmWallet->collected_cash=$dmWallet->collected_cash + ($order->order_amount-$order->partially_paid_amount);
                $dm_over_flow =  true ;

            }
                if(isset($dmWallet)) {
                    $dmWallet->save();
                }
                $vendorWallet->save();
                $adminWallet->save();




                if(isset($restaurant_over_flow) ){
                    self::create_account_transaction_for_collect_cash(old_collected_cash:$vendorWallet->collected_cash , from_type:'restaurant' , from_id: $order->restaurant->vendor->id , amount: $order->order_amount - $order->partially_paid_amount ,order_id: $order->id);
                }
                if(isset($dm_over_flow)){
                    self::create_account_transaction_for_collect_cash(old_collected_cash:$dmWallet->collected_cash , from_type:'deliveryman' , from_id: $order->delivery_man_id , amount: $order->order_amount - $order->partially_paid_amount ,order_id: $order->id);
                }



                self::update_unpaid_order_payment(order_id:$order->id, payment_method:$order->payment_method);

                DB::commit();
                if($order->is_guest  == 0){
                    $ref_status = BusinessSetting::where('key','ref_earning_status')->first()?->value;
                    if(isset($order?->customer?->ref_by) && $order?->customer?->order_count == 0  && $ref_status == 1){
                        $ref_code_exchange_amt = BusinessSetting::where('key','ref_earning_exchange_rate')->first()?->value;
                        $referar_user=User::where('id',$order?->customer?->ref_by)->first();
                        $refer_wallet_transaction = CustomerLogic::create_wallet_transaction(user_id:$referar_user?->id, amount:$ref_code_exchange_amt, transaction_type:'referrer',referance:$order?->customer?->phone);

                        //     'description' => translate('You_have_received').' '.Helpers::format_currency($ref_code_exchange_amt).' '.translate('in_your_wallet_as').' '.$order?->customer?->f_name.' '.$order?->customer?->l_name.' '.translate('you_referred_completed_thier_first_order') ,



                    $message =Helpers::getPushNotificationMessage(status:'customer_referral_bonus_earning',userType: 'user' , lang:$referar_user?->cm_firebase_token, userName: $referar_user?->f_name.' '.$referar_user?->l_name);
                    if ($message && isset($referar_user?->cm_firebase_token)) {
                        $data= Helpers::makeDataForPushNotification(title:translate('messages.Congratulation'), message:$message,orderId: '', type: 'referral_earn', orderStatus: '',amount:$ref_code_exchange_amt);
                        Helpers::send_push_notif_to_device($referar_user?->cm_firebase_token, $data);
                        Helpers::insertDataOnNotificationTable($data , 'user', $referar_user?->id);
                    }

                    try{
                        $notification_status= Helpers::getNotificationStatusData('customer','customer_add_fund_to_wallet');
                        Helpers::add_fund_push_notification($referar_user->id,$ref_code_exchange_amt);
                        if($notification_status?->mail_status == 'active' && config('mail.status') && $referar_user?->email && Helpers::get_mail_status('add_fund_mail_status_user') == '1') {
                            Mail::to($referar_user?->getRawOriginal('email'))->send(new \App\Mail\AddFundToWallet($refer_wallet_transaction));
                            }
                        } catch(\Exception $exception){
                            info([$exception->getFile(),$exception->getLine(),$exception->getMessage()]);
                        }
                    }

                    if($order->user_id) CustomerLogic::create_loyalty_point_transaction(user_id:$order->user_id,referance: $order->id, amount:$order->order_amount,transaction_type: 'order_place');
                }
            }
            catch(\Exception $exception)
            {
                DB::rollBack();
                info([$exception->getFile(),$exception->getLine(),$exception->getMessage()]);
                return false;
            }
        }
        catch(\Exception $exception){
            info([$exception->getFile(),$exception->getLine(),$exception->getMessage()]);
            return false;
        }
        return true;
    }
    /**
     * 哪吒 B方案 — 配送/自取单「收尾结算」统一入口（顾客「确认收货」A + handover 超时自动兜底 C 共用）。
     *
     * 背景: B方案平台不配送(顾客自叫 Yandex/自取), 既无骑手点「已送达」, 商家也不知 Yandex 何时送达,
     * 故收尾触发方只能是「顾客确认」或「超时兜底」。本方法把订单推到 delivered 并触发**恰好一次**佣金结算。
     *
     * 幂等闸(防顾客/商家/兜底任一路径重复或并发多记佣金): 直接查 order_transaction 是否已存在,
     * 仅当不存在时调 create_transaction —— 先到先记、余者跳过(与商家端 status() 的 `$order->transaction==null` 同闸)。
     * L1-1: 直付单 create_transaction 内 is_direct_pay 分支不碰 total_earning/digital_received(平台不碰钱), 仅记应收佣金。
     *
     * @param  \App\Models\Order  $order
     * @param  string             $finalized_by  'customer'(顾客确认) | 'auto'(超时兜底) —— 仅留痕用
     * @param  int|null           $by_id         顾客 user_id（游客/自动为 null）
     * @return bool  true=本次完成收尾; false=不允许收尾(非 handover/picked_up / 已 delivered / 订阅单 / 建流水失败)
     */
    public static function settle_delivered($order, $finalized_by = 'customer', $by_id = null, $offboard_settle = false)
    {
        // 订阅单有独立交付生命周期, 不走顾客确认/超时兜底。
        if ($order->subscription_id != null) {
            return false;
        }
        // 仅「商家已出餐交付(handover/picked_up)且尚未送达」的单可收尾。
        if ($order->delivered != null || !in_array($order->order_status, ['handover', 'picked_up'], true)) {
            return false;
        }

        $order->loadMissing(['details', 'restaurant', 'restaurant.vendor', 'customer']);
        $before_status = $order->order_status;

        // 幂等闸: 查 DB(非缓存关系)看本单是否已记过流水, 防重复/并发多扣一次佣金。
        $already_settled = OrderTransaction::where('order_id', $order->id)->exists();
        if (!$already_settled) {
            $unpaid_payment = OrderPayment::where('payment_status', 'unpaid')->where('order_id', $order->id)->first()?->payment_method;
            $unpaid_pay_method = $unpaid_payment ?: 'digital_payment';

            if ($order->payment_method == 'cash_on_delivery' || $unpaid_pay_method == 'cash_on_delivery') {
                $ol = self::create_transaction(order: $order, received_by: 'restaurant', status: null, offboard_settle: $offboard_settle);
            } else {
                $ol = self::create_transaction(order: $order, received_by: 'admin', status: null, offboard_settle: $offboard_settle);
            }
            if (!$ol) {
                return false; // 建流水失败则不推进状态(与商家端一致, 不留半收尾状态)
            }
        }

        $order->payment_status = 'paid';
        self::update_unpaid_order_payment(order_id: $order->id, payment_method: $order->payment_method);

        $order->order_status = 'delivered';
        $order->delivered = now();
        $order->save();

        // 销量/计数(与商家端 delivered 分支一致)
        $order->details->each(function ($item) {
            if ($item->food) {
                $item->food->increment('order_count');
            }
        });
        $order->customer ? $order->customer->increment('order_count') : '';
        $order->restaurant ? $order->restaurant->increment('order_count') : '';

        try {
            Helpers::send_order_notification($order);
        } catch (\Throwable $e) {
            info('settle_delivered notify failed: ' . $e->getMessage());
        }

        // 留痕(审计: 谁/何时把单收尾为已送达)
        try {
            \App\Models\Log::create([
                'logable_id'     => $order->id,
                'logable_type'   => \App\Models\Order::class,
                'action_type'    => 'delivery_settled',
                'model'          => 'Order',
                'model_id'       => $order->id,
                'action_details' => json_encode([
                    'finalized_by' => $finalized_by,
                    'by_id'        => $by_id,
                    'at'           => now()->toDateTimeString(),
                ]),
                'before_state'   => $before_status,
                'after_state'    => 'delivered',
                'restaurant_id'  => $order->restaurant_id,
            ]);
        } catch (\Throwable $e) {
            info('settle_delivered log failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * 哪吒B方案: 订单「完成/送达」展示元数据（顾客端）。
     * 平台无自营骑手、未接入第三方(Yandex)配送轨迹 → 不能独立核实“送达事件”，
     * 故必须如实标注「状态来源」(谁把单收尾)；配送订单缺事件时不伪造节点(req 1/2/8)。
     */
    public static function completion_meta($order)
    {
        $is_delivery = $order->order_type === 'delivery';
        $is_done = $order->order_status === 'delivered';

        // 是否存在真实配送事件: 有取餐(picked_up)时间 或 指派过配送员
        $has_delivery_event = !empty($order->picked_up) || !empty($order->delivery_man_id);

        // 状态来源: 读 delivery_settled 留痕(谁把单收尾为已送达)
        $source = null;
        try {
            $log = \App\Models\Log::where('logable_id', $order->id)
                ->where('logable_type', \App\Models\Order::class)
                ->where('action_type', 'delivery_settled')
                ->orderByDesc('id')->first();
            if ($log) {
                $d = json_decode($log->action_details, true) ?: [];
                $source = $d['finalized_by'] ?? null;       // customer / auto / vendor
            }
        } catch (\Throwable $e) {
            // 留痕不可用不影响主流程
        }
        if (!$source && $is_done) {
            // 无 settle 留痕(商家端直接标记送达 / 历史单)
            $source = $has_delivery_event ? 'deliveryman' : 'merchant_marked';
        }

        $source_labels = [
            'customer'        => '你已确认收货',
            'auto'            => '超时未确认，系统自动完成',
            'vendor'          => '商家确认送达',
            'deliveryman'     => '配送员标记送达',
            'merchant_marked' => '商家标记完成',
        ];

        // 历史订单无配送轨迹: 配送单、已完成，但既无配送事件，又无任何主动收尾来源(req 8)
        $legacy_no_track = $is_delivery && $is_done && !$has_delivery_event
            && !in_array($source, ['customer', 'auto', 'vendor'], true);

        // 配送责任方: B 方案由商家自行安排第三方(如 Yandex)，平台不是配送方
        $responsible_party = $is_delivery ? '商家自行安排第三方配送（如 Yandex）' : '商家自营';

        // 申诉窗口/处理时间(L2 可后台调)
        $window_hours = (int) (DB::table('business_settings')->where('key', 'nezha_appeal_window_hours')->value('value') ?? 48);
        $processing_text = DB::table('business_settings')->where('key', 'nezha_appeal_processing_text')->value('value')
            ?: '商家通常 24 小时内回应；如需平台介入，预计 1–3 个工作日。';

        $delivered_at = $order->delivered ? (string) $order->delivered : null;
        $appeal_deadline = null;
        $appeal_open = false;
        if ($delivered_at) {
            try {
                $deadline = \Carbon\Carbon::parse($order->delivered)->addHours($window_hours);
                $appeal_deadline = $deadline->toDateTimeString();
                $appeal_open = $deadline->isFuture();
            } catch (\Throwable $e) {
            }
        }

        // 现有申诉(留痕)状态
        $appeal = null;
        try {
            $row = \App\Models\NezhaDeliveryAppeal::where('order_id', $order->id)->orderByDesc('id')->first();
            if ($row) {
                $appeal = [
                    'id'         => $row->id,
                    'status'     => $row->status,
                    'created_at' => (string) $row->created_at,
                    'sla_due_at' => $row->sla_due_at ? (string) $row->sla_due_at : null,
                ];
            }
        } catch (\Throwable $e) {
        }

        return [
            'is_delivered'        => $is_done,
            'order_type'          => $order->order_type,
            'delivered_at'        => $delivered_at,
            'picked_up_at'        => $order->picked_up ? (string) $order->picked_up : null,
            'has_delivery_event'  => $has_delivery_event,
            'source'              => $source,
            'source_label'        => $source ? ($source_labels[$source] ?? $source) : null,
            'responsible_party'   => $responsible_party,
            'delivery_trackable'  => false, // 平台未接入第三方配送轨迹
            'legacy_no_track'     => $legacy_no_track,
            // 可获得的配送凭证(如实): 平台无骑手轨迹/签收照片
            'available_proofs'    => self::available_delivery_proofs($order),
            // 申诉(req 4/5)
            'appeal_window_hours' => $window_hours,
            'appeal_deadline'     => $appeal_deadline,
            'appeal_open'         => $appeal_open,
            'appeal_processing'   => $processing_text,
            'appeal'              => $appeal,
        ];
    }

    private static function available_delivery_proofs($order)
    {
        $proofs = [];
        if ($order->delivered) {
            $proofs[] = ['type' => 'completion_record', 'label' => '完成记录（送达时间 + 状态来源）', 'available' => true];
        }
        $proofs[] = ['type' => 'chat', 'label' => '与商家的聊天记录', 'available' => (bool) ($order->restaurant && $order->restaurant->vendor_id)];
        // 平台未接入第三方骑手 → 签收照片/骑手轨迹不可得，如实标记，不伪造
        $proofs[] = ['type' => 'rider_track', 'label' => '骑手轨迹 / 签收照片', 'available' => false, 'note' => '平台未接入第三方配送轨迹'];
        return $proofs;
    }

    /**
     * 哪吒B方案: 支付凭证/收据（顾客端查看），隐藏敏感信息(req 7)。
     * 平台不碰钱；收据仅展示订单层面金额/方式/收款方，敏感账号串掩码。
     */
    public static function receipt_meta($order)
    {
        $symbol = BusinessSetting::where('key', 'currency_symbol')->first()?->value ?? '֏';
        $method = $order->payment_method;
        $method_name = trim(str_replace('_', ' ', (string) $method));
        // 哪吒: method_name 缺失时不露英文(如 offline payment), 给中文兜底；真实 method_name 仍优先(下方 offline 块覆盖)
        $method_fallback_zh = [
            'offline_payment'  => '线下转账收款',
            'cash_on_delivery' => '货到付款',
            'digital_payment'  => '在线支付',
        ];
        if (isset($method_fallback_zh[$method])) {
            $method_name = $method_fallback_zh[$method];
        }

        $masked_fields = [];
        if ($method === 'offline_payment' && isset($order->offline_payments) && $order->offline_payments) {
            $info = json_decode($order->offline_payments->payment_info, true) ?: [];
            $method_name = $info['method_name'] ?? $method_name;
            foreach ($info as $k => $v) {
                if (in_array($k, ['method_name', 'method_id'], true)) {
                    continue;
                }
                if (!is_scalar($v)) {
                    continue;
                }
                // 截图/文件类字段不进收据摘要(凭证图另有入口, 见 req 7)
                if (Helpers::offline_payment_proof_url($v) !== null
                    || preg_match('#\.(png|jpe?g|gif|webp|pdf)$#i', (string) $v)
                    || str_contains((string) $v, '/')) {
                    continue;
                }
                $masked_fields[] = ['label' => $k, 'value' => self::mask_sensitive((string) $v)];
            }
        }

        return [
            'order_id'        => $order->id,
            'created_at'      => $order->created_at ? (string) $order->created_at : null,
            'paid_at'         => $order->payment_status === 'paid'
                ? ($order->confirmed ? (string) $order->confirmed : (string) $order->updated_at) : null,
            'payment_method'  => $method,
            'method_name'     => $method_name,
            'payment_status'  => $order->payment_status,
            'order_amount'    => (float) $order->order_amount,
            'currency_symbol' => $symbol,
            'payee'           => $order->restaurant?->name,   // 收款方=商家(B方案直付商家)
            'platform_note'   => '本收据仅供核对；款项直接支付给商家本人账户。',
            'masked_fields'   => $masked_fields,
        ];
    }

    /** 账号/卡号/手机号等长数字串掩码: 保留前2后2，中间用 * 替换(req 7 隐藏敏感信息)。 */
    private static function mask_sensitive($value)
    {
        return preg_replace_callback('/\d{6,}/', function ($m) {
            $s = $m[0];
            return mb_substr($s, 0, 2) . str_repeat('*', max(2, strlen($s) - 4)) . mb_substr($s, -2);
        }, $value);
    }

    public static function refund_before_delivered($order){
        // 哪吒 B方案 L1-1 (F-4a): 直付单(offline_payment)平台从未收款, 取消退款由商家直接退原付款人,
        // 平台不动 digital_received、不走平台钱包(与 refund_order 的 !$isDirectPay 护栏对齐, 防平台垫钱碰钱)。
        if ($order->payment_method == 'offline_payment') {
            return true;
        }
        $adminWallet = AdminWallet::firstOrNew(
            ['admin_id' => Admin::where('role_id', 1)->first()->id]
        );
        if ($order->payment_method == 'cash_on_delivery') {
            return false;
        }
            if(($order->payment_status == "paid")){
                $adminWallet->digital_received = $adminWallet->digital_received - $order->order_amount;
                $adminWallet->save();
                if ($order->payment_status == "paid" && BusinessSetting::where('key', 'wallet_add_refund')->first()?->value == 1 && $order->is_guest  == 0) {
                    CustomerLogic::create_wallet_transaction(user_id:$order->user_id, amount:$order->order_amount, transaction_type:'order_refund', referance:$order->id);
                }
            }elseif(($order->payment_status == "partially_paid")){
                $adminWallet->digital_received = $adminWallet->digital_received - $order->partially_paid_amount;
                $adminWallet->save();
                if (BusinessSetting::where('key', 'wallet_add_refund')->first()?->value == 1 && $order->is_guest  == 0) {
                    CustomerLogic::create_wallet_transaction($order->user_id, $order->partially_paid_amount, 'order_refund', $order->id);
                }
            }
        return true;
    }


    public static function refund_order($order)
    {
        $order_transaction = $order->transaction;
        if($order_transaction == null || $order->restaurant == null)
        {
            return false;
        }
        $received_by = $order_transaction->received_by;
        // [哪吒 B方案/组3] 直付订单平台从未收款, 退款由商家直接退顾客; 平台侧只冲销应收佣金, 不动 total_earning/现金桶(与 create_transaction 对称)。
        $is_direct_pay = ($order->payment_method == 'offline_payment');

        $adminWallet = AdminWallet::firstOrNew(
            ['admin_id' => Admin::where('role_id', 1)->first()->id]
        );

        $vendorWallet = RestaurantWallet::firstOrNew(
            ['vendor_id' => $order->restaurant->vendor->id]
        );

        $adminWallet->total_commission_earning = $adminWallet->total_commission_earning - $order_transaction->admin_commission;

        if(!$is_direct_pay){
            $vendorWallet->total_earning = $vendorWallet->total_earning - $order_transaction->restaurant_amount;
        }

        $refund_amount = $order->order_amount - $order->additional_charge - $order->extra_packaging_amount;

        $status = 'refunded_with_delivery_charge';
        if($order->order_status == 'delivered' || $order->order_status == 'refund_requested'|| $order->order_status == 'refund_request_canceled')
        {
            $refund_amount = $order->order_amount - $order->delivery_charge - $order->dm_tips - $order->additional_charge - $order->extra_packaging_amount;
            $status = 'refunded_without_delivery_charge';
        }
        else
        {
            $adminWallet->delivery_charge = $adminWallet->delivery_charge - $order_transaction->delivery_charge;
        }
        try
        {
            DB::beginTransaction();
            $partially_paid = OrderPayment::where('payment_method','cash_on_delivery')->where('order_id',$order->id)->exists() ?? false;

            if($partially_paid){
                $refund_amount = $refund_amount - $order->partially_paid_amount;
            }


            if($is_direct_pay)
            {
                // [哪吒 B方案/组3] 直付订单平台无现金桶可冲销(退款走商家直退顾客)。
                // [组4 预存佣金扣佣] 若本单此前从预存佣金扣过佣金, 退款时对称返还(基于已记流水, 不受开关后续变动影响)。
                $deducted = \App\Models\RestaurantDepositTransaction::where('order_id',$order->id)
                    ->where('type','commission_deduction')->sum('commission');
                if($deducted > 0){
                    // [哪吒 退出结算 §C3] 退出中/已退出的店(offboard_status != active): 不自动回充 deposit ——
                    //   结算窗口 deposit 只经三腿受控变动以保 approved 快照稳定; 已 paid/offboarded 的店回充会把钱打进死账户造成漏损。
                    //   改为在结算工单记独立字段 frozen_reversal_owed(平台欠商家, 与 shortfall_amount 分开)+ 审计留痕, 不动 deposit(§C3「非回充」待人工核算)。
                    if(\App\CentralLogics\NezhaOffboard::is_deposit_credit_frozen($order->restaurant_id)){
                        \App\CentralLogics\NezhaOffboard::recordFrozenReversalOwed($order, (float) $deducted);
                    } else {
                        // F-3 防并发 lost-update: 同扣减, lockForUpdate 读最新余额后返还; 由函数末尾 save() 落库。
                        $freshBalance = (float) (RestaurantWallet::where('vendor_id', $order->restaurant->vendor->id)->lockForUpdate()->value('deposit_balance') ?? 0);
                        $vendorWallet->deposit_balance = $freshBalance + $deducted;
                        \App\Models\RestaurantDepositTransaction::insert([
                            'vendor_id'     => $order->restaurant->vendor->id,
                            'restaurant_id' => $order->restaurant->id,
                            'order_id'      => $order->id,
                            'type'          => 'refund_reversal',
                            'amount'        => $deducted,
                            'commission'    => $deducted,
                            'balance_after' => $vendorWallet->deposit_balance,
                            'note'          => '订单#'.$order->id.' 退款返还佣金',
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]);
                    }
                }
            }
            else if($received_by=='admin')
            {
                if($order->delivery_man_id && $order->payment_method != "cash_on_delivery")
                {
                    $adminWallet->digital_received = $adminWallet->digital_received - $refund_amount;
                }
                else
                {
                    $adminWallet->manual_received = $adminWallet->manual_received - $refund_amount;
                }

            }
            else if($received_by=='restaurant')
            {
                $vendorWallet->collected_cash = $vendorWallet->collected_cash - $refund_amount;
            }

            else if($received_by=='deliveryman')
            {
                $dmWallet = DeliveryManWallet::firstOrNew(
                    ['delivery_man_id' => $order->delivery_man_id]
                );
                $dmWallet->collected_cash=$dmWallet->collected_cash - $refund_amount;
                $dmWallet->save();
            }
            $order_transaction->status = $status;
            $order_transaction->save();

            $adminWallet->save();
            $vendorWallet->save();
            DB::commit();
        }
        catch(\Exception $e)
        {
            DB::rollBack();
            info(["line___{$e->getLine()}",$e->getMessage()]);
            return false;
        }
        return true;

    }


    public static function check_incentive($zone_id, $delivery_man_id, $delivery_man_earning, $dm_incentive)
    {   

        $incentive = Incentive::where('zone_id', $zone_id)->where('earning', '<=', $delivery_man_earning)->orderBy('earning', 'desc')->first();
        if(!$incentive) {
            return false;
        }
        if ($dm_incentive) {
            if ($incentive && $dm_incentive->earning != $incentive->earning){
                $dm_incentive->earning = $incentive ? $incentive->earning : $dm_incentive->earning;
                $dm_incentive->incentive = $incentive ? $incentive->incentive : $dm_incentive->incentive;
            }
        } else {
            $dm_incentive = new IncentiveLog();
            $dm_incentive->earning = $incentive ? $incentive->earning : 0;
            $dm_incentive->incentive = $incentive ? $incentive->incentive : 0;
            $dm_incentive->delivery_man_id = $delivery_man_id;
            $dm_incentive->zone_id = $zone_id;
            $dm_incentive->date = now();
            $dm_incentive->status = 'pending';
        }
        $dm_incentive->today_earning = $delivery_man_earning;
        $dm_incentive->save();
        return true;
    }

    public static function create_subscription_log($id=null)
    {
        $order = Order::find($id);
        if(!isset($order)  || !isset($order?->subscription?->schedule) || !isset($order?->subscription?->schedule_today) || isset($order?->subscription_log ) || $order?->restaurant?->restaurant_model == 'unsubscribed'){
            return true;
        }

        $day = $order->subscription->schedule_today->day ??  $order->subscription->schedule->day ?? 0;
        $today = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$day] ??'Sun';
        $nextdate = date('Y-m-d', strtotime('next ' . $today));

        $time= $order->subscription->schedule_today->time ?? $order->subscription->schedule->time;
        $schedule_at = $day != 0 ? $nextdate : now()->format('Y-m-d');
        $subscription_log = new SubscriptionLog();
        $subscription_log->subscription_id = $order->subscription_id;
        $subscription_log->order_id = $order->id;
        $subscription_log->order_status = 'pending';
        $subscription_log->schedule_at = $schedule_at.' '.$time;
        $subscription_log->updated_at = now();
        $subscription_log->created_at = now();
        $order->subscription_log()->save($subscription_log);
        $order->order_status = 'pending';
        $order->payment_status='unpaid';
        $order->schedule_at = $schedule_at.' '.$time ;
        $order->save();

        Helpers::send_order_notification($order);

        return true;
    }

    public static function update_subscription_log(Order $order):void
    {
        if(!isset($order?->subscription_log) || !isset($order->subscription_id)){
            return ;
        }
        $schedule_today = $order->subscription_log;
        $schedule_today->order_status = $order->order_status;
        $schedule_today->delivery_man_id = $order->delivery_man_id;
        if($order->order_status != 'pending')$schedule_today->{$order->order_status} = now();
        $schedule_today->save();

        if($order->order_status == 'delivered'){
            $subscription = $order->subscription;
            $subscription->billing_amount += $order->order_amount;
            $subscription->paid_amount += $order->order_amount;
            $subscription->save();

            $order->delivery_man_id = null;
            $order->save();
        }

        return ;
    }

    public static function check_subscription(User $user):void
    {
        $subscriptions = Subscription::where('user_id', $user->id)->expired()->get();
        try{
            DB::beginTransaction();
            foreach($subscriptions as $subscription){
                if($subscription->paid_amount > $subscription->billing_amount){
                    $extra = $subscription->paid_amount - $subscription->billing_amount;
                    CustomerLogic::create_wallet_transaction(user_id:$user->id,amount: $extra,transaction_type: 'add_fund',referance:"Subscription, Id:{$subscription->id}");
                }
                $subscription->status = 'expired';
                $subscription->save();
            }
            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
            info(["line___{$e->getLine()}",$e->getMessage()]);
        }
    }

    public static function create_order_payment($order_id, $amount, $payment_status, $payment_method)
    {
        $payment = new OrderPayment();
        $payment->order_id = $order_id;
        $payment->amount = $amount;
        $payment->payment_status = $payment_status;
        $payment->payment_method = $payment_method;
        if($payment->save()){
            return true;
        }

        return false;

    }

    public static function update_unpaid_order_payment($order_id,$payment_method)
    {
        $payment = OrderPayment::where('payment_status','unpaid')->where('order_id',$order_id)->first();
        if($payment){
            $payment->payment_status = 'paid';
            if($payment_method != 'partial_payment'){
                $payment->payment_method = $payment_method;
            }
            if($payment->save()){
                return true;
            }

            return false;
        }
        return true;

    }

    public static function create_account_transaction_for_collect_cash($old_collected_cash, $from_type ,$from_id ,$amount, $order_id){
        $account_transaction = new AccountTransaction();
            $account_transaction->from_type =$from_type;
            $account_transaction->from_id = $from_id;
            $account_transaction->created_by = $from_type;
            $account_transaction->method = 'cash_collection';
            $account_transaction->ref = $order_id;
            $account_transaction->amount = $amount ?? 0;
            $account_transaction->current_balance = $old_collected_cash ?? 0;
            $account_transaction->type = 'cash_in';
            $account_transaction->save();


            if($from_type  ==  'restaurant'){
                $vendor= Vendor::find($from_id);

                $Payable_Balance = $vendor?->wallet?->collected_cash   > 0 ? 1: 0;
                $cash_in_hand_overflow= BusinessSetting::where('key' ,'cash_in_hand_overflow_restaurant')->first()?->value;
                $cash_in_hand_overflow_restaurant_amount = BusinessSetting::where('key' ,'cash_in_hand_overflow_restaurant_amount')->first()?->value;

                if ($Payable_Balance == 1 &&  $cash_in_hand_overflow && $vendor?->wallet?->balance < 0 &&  $cash_in_hand_overflow_restaurant_amount <= abs($vendor?->wallet?->collected_cash)){
                    $rest= Restaurant::where('vendor_id', $vendor->id)->first();
                    $rest->status = 0 ;
                    $rest->save();
                }

            } elseif($from_type  ==  'deliveryman' ){
                $cash_in_hand_overflow= BusinessSetting::where('key' ,'cash_in_hand_overflow_delivery_man')->first()?->value;
                $cash_in_hand_overflow_delivery_man = BusinessSetting::where('key' ,'dm_max_cash_in_hand')->first()?->value;

            $dm= DeliveryMan::find($from_id);

            $wallet_balance = round( $dm?->wallet?->total_earning - ($dm?->wallet?->total_withdrawn +$dm?->wallet?->pending_withdraw + $dm?->wallet?->collected_cash),8);
            $over_flow_balance = $dm?->wallet?->collected_cash;
            $Payable_Balance =  $over_flow_balance  > 0 ? 1: 0;
                if ($Payable_Balance == 1 &&  $cash_in_hand_overflow  && $wallet_balance<0 &&  $cash_in_hand_overflow_delivery_man < abs($over_flow_balance)){
                    $dm->status = 0 ;
                    $dm->save();
                }
            }

            return true;
    }


    public static function cashbackToWallet($order){

        $refer_wallet_transaction = CustomerLogic::create_wallet_transaction($order?->cashback_history?->user_id, $order?->cashback_history?->calculated_amount, 'CashBack',$order->id);
        if($refer_wallet_transaction != false){
            Helpers::expenseCreate(amount:$order?->cashback_history?->calculated_amount,type:'CashBack',datetime:now(),created_by:'admin', order_id:$order->id);
            $order?->cashback_history?->cashBack?->increment('total_used');

            $user = $order->customer;
            $message =Helpers::getPushNotificationMessage(status:'customer_cashback',userType: 'user' , lang:$user?->cm_firebase_token, userName: $user?->f_name.' '.$user?->l_name);
            if ($message && isset($user?->cm_firebase_token)) {
                $data= Helpers::makeDataForPushNotification(title:translate('You’ve_Earned_Cahback!'), message:$message,orderId: $order->id, type: 'CashBack', orderStatus: '');
                Helpers::send_push_notif_to_device($user?->cm_firebase_token, $data);
                Helpers::insertDataOnNotificationTable($data , 'user', $user->id);
            }

        }

        return true;
    }

    /**
     * 哪吒 B方案 — 离线支付「确认收款」统一动作。
     * admin 后台核验(后备) 与 商家自营确认(主路径) 共用此方法, 避免两端逻辑漂移。
     *
     * 状态机: pending + offline_payment  ->  payment_status=paid / order_status=confirmed / offline_payments=verified。
     *
     * 合规(INVARIANTS L1-1 平台全程不碰资金): 本动作只改"谁来核验 + 订单可见性/状态",
     * 顾客货款全程直付商家本人账户, 平台不归集、不代收代付, 这里不触碰任何资金流转。
     * 收款人 = 商家本人(收款码后台代录, 商家无自助改码入口, business-flow §8)。
     *
     * @param  Order        $order
     * @param  string       $confirmer_type  'admin' | 'vendor'  (留痕用)
     * @param  int|null     $confirmer_id    操作人 id (留痕用)
     * @return Order
     */
    public static function confirm_offline_payment($order, $confirmer_type = 'admin', $confirmer_id = null, $allow_inconclusive = false, $processing_time = null)
    {
        // 🔴 L1-6 制裁名单筛查(机制②): 确认收款 = 放行出餐的闸口。USDT 付款先反查链上来源地址,
        //    命中 OFAC SDN/黑名单 → 拒收 + 留痕 + 不放行(平台不与受制裁主体交易)。
        //    放在任何状态变更之前: 命中时订单保持未确认, 仅标记 denied, 失败也在安全侧。
        //    非 USDT / 反查不出地址(无 tx 或 API 不可达)不硬拦 —— 后者写 review 记录待人工复核。
        //    $allow_inconclusive=true: 仅用于 admin「人工核实来源后放行」—— 越过"反查不出→暂挂",
        //    但【真命中 reject 仍照拦】(下面 reject 分支不受影响), 即人工只能放行"查不出", 不能放行"已命中"。
        if (\App\CentralLogics\NezhaSanctionScreen::enabled()) {
            $screen = \App\CentralLogics\NezhaSanctionScreen::screen_order($order);
            if (($screen['action'] ?? 'pass') === 'reject') {
                \App\CentralLogics\NezhaSanctionScreen::record_reject($order, $screen);
                self::deny_offline_payment(
                    $order,
                    '付款来源地址经制裁名单筛查命中，无法确认收款。如有疑问请联系客服。',
                    $confirmer_type,
                    $confirmer_id
                );
                info(['nezha_sanction reject', 'order' => $order->id, 'detail' => $screen['detail'] ?? '']);
                throw new \App\Exceptions\SanctionScreenException($screen['detail'] ?? '付款来源地址命中制裁名单，已拒收。');
            }
            if (($screen['action'] ?? 'pass') === 'inconclusive') {
                // 反查不出来源地址(无 tx / 链上 API 不可达): 先留一条待人工复核记录(去重)。
                \App\CentralLogics\NezhaSanctionScreen::record_inconclusive($order, $screen);
                if (!$allow_inconclusive && \App\CentralLogics\NezhaSanctionScreen::inconclusive_action() === 'hold') {
                    // fail-closed(默认): 不放行出餐, 中止本次确认。订单保持 pending、offline_payments 仍 pending,
                    //   来源核实 / API 恢复后可重新「确认收款」再次筛查; 不标 denied(非拒收, 是暂挂待复核)。
                    info(['nezha_sanction hold(inconclusive)', 'order' => $order->id, 'detail' => $screen['detail'] ?? '']);
                    throw new \App\Exceptions\SanctionScreenException('付款来源地址暂无法完成制裁筛查（来源待人工核对），暂不能确认收款。请稍后重试或联系平台。');
                }
                // fail-open: 已留痕, 放行继续。
            }
        }

        $before_status = $order->order_status;

        // 哪吒 M3(2026-07-12): 行锁 + 锁内终态复核, 防"顾客已并发取消并生成待退款留痕"的死单被本"确认收款=接单"
        //   主路径裸 save 复活成送货态(并把 offline 翻回 verified)。终态则中止本次确认(不改状态/不翻 offline), 返回 false。
        $nz_confirm_ok = true;
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $processing_time, &$nz_confirm_ok) {
            $nz_fresh = \App\Models\Order::where('id', $order->id)->lockForUpdate()->first();
            if (!$nz_fresh || $nz_fresh->isFinalized()) { $nz_confirm_ok = false; return; }
            $order->payment_status = 'paid';
            $order->confirmed = now();
            $order->order_status = 'confirmed';
            // 哪吒 B方案: 确认收款 = 接单; 即时单自动进入「备餐中」。预约单(未来时段)不自动进备餐。
            $nz_is_scheduled = $order->scheduled == 1 && $order->schedule_at && \Carbon\Carbon::parse($order->schedule_at)->gt(now());
            if (!$nz_is_scheduled) {
                $order->processing = now();
                $order->order_status = 'processing';
                // 哪吒 P4: 可传本单预计出餐时间(1-1440); 未传退回平台默认(仅当当前为空)。
                $nz_pt = (int) $processing_time;
                if ($nz_pt >= 1) {
                    $order->processing_time = min($nz_pt, 1440);
                } elseif (empty($order->processing_time)) {
                    $order->processing_time = (int) (\App\CentralLogics\Helpers::get_business_settings('nezha_default_prep_min') ?: 30);
                }
            }
            $order->save();
            $order->offline_payments()->update(['status' => 'verified']);
        });
        if (!$nz_confirm_ok) {
            return false;
        }

        $payment_method_name = data_get(json_decode($order->offline_payments?->payment_info ?? '', true), 'method_name', $order->payment_method); // F-1 防无凭证行时 null->payment_info fatal
        if ($order->payment_method == 'partial_payment') {
            $order->payments()->where('payment_status', 'unpaid')->update([
                'payment_method' => $payment_method_name,
                'payment_status' => 'paid',
            ]);
        }

        self::notify_offline_payment_result($order, 'approved');
        self::log_offline_payment_action($order, 'offline_confirmed', $before_status, $order->order_status, $confirmer_type, $confirmer_id);

        return $order;
    }

    /**
     * 哪吒 B方案 — 离线支付「拒收 / 打回」统一动作 (admin 与 商家共用)。
     * 仅把 offline_payments 标记为 denied + 备注, 不改订单主状态(顾客可据备注重传凭证或取消)。
     * @param  Order        $order
     * @param  string|null  $note            拒收原因(展示给顾客)
     * @param  string       $confirmer_type  'admin' | 'vendor'
     * @param  int|null     $confirmer_id
     * @return Order
     */
    public static function deny_offline_payment($order, $note = null, $confirmer_type = 'admin', $confirmer_id = null)
    {
        $order->offline_payments()->update([
            'status' => 'denied',
            'note'   => $note,
        ]);

        self::notify_offline_payment_result($order, 'denied');
        self::log_offline_payment_action($order, 'offline_denied', $order->order_status, $order->order_status, $confirmer_type, $confirmer_id);

        return $order;
    }

    /**
     * 给顾客发离线支付核验结果通知(推送 + 邮件)。
     * 由 Admin\OrderController::sent_notification_on_offline_payment 迁移而来, 作为 admin/vendor 单一来源。
     */
    private static function notify_offline_payment_result($order, $status)
    {
        $order = Order::findOrFail($order->id);
        // 哪吒(#5): 通知标题按顾客语言本地化(与 sentUserNotification 一致); 否则 translate() 用 app locale(确认时多为 en)把英文标题烘焙进推送/站内信.
        $cust_lang = $order->customer ? ($order?->customer?->current_language_key ?: 'en') : 'en';
        $isZh = $cust_lang && stripos($cust_lang, 'zh') === 0;
        if ($status == 'approved') {
            // 顾客只收下面的“收款已确认”一条；通用整套通知还会再发一条
            // “订单已确认”，造成同一动作双推送。商家/配送侧交接仍照常通知。
            try {
                Helpers::sentDeliveryManNotification($order);
                Helpers::sentRestaurantNotification($order);
            } catch (\Throwable $e) {
                info('notify offline payment handoff failed: '.$e->getMessage());
            }

            $notification_text  = 'offline_verified';
            $notification_title = $isZh ? '收款已确认' : translate('messages.Your_Offline_payment_was_approved');
            $mail_sattus        = Helpers::get_mail_status('offline_payment_approve_mail_status_user');
            $mail_sattus_type   = 'approved';
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_offline_payment_approve');

            if ($order->restaurant->restaurant_model == 'subscription' && isset($order->restaurant->restaurant_sub)) {
                if ($order->restaurant->restaurant_sub->max_order != "unlimited" && $order->restaurant->restaurant_sub->max_order > 0) {
                    $order->restaurant->restaurant_sub()->decrement('max_order', 1);
                }
            }
        } else {
            $notification_text  = 'offline_denied';
            $notification_title = $isZh ? '收款未通过' : translate('messages.Your_Offline_payment_was_rejected');
            $mail_sattus_type   = 'denied';
            $mail_sattus        = Helpers::get_mail_status('offline_payment_deny_mail_status_user');
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_offline_payment_deny');
        }

        try {
            $fcm_token = ($order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token) ?? null;
            $message = Helpers::getOrderPushNotificationMessage($order, $notification_text, 'user', $order->customer ? $order?->customer?->current_language_key : 'en');
            if ($message) {
                $data = Helpers::makeDataForPushNotification(title: $notification_title, message: $message, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
                // 哪吒: 顾客「订单进度」推送偏好闸(离线支付审核结果)
                if ($fcm_token && Helpers::customerWantsPush($order->customer, 'order_progress')) {
                    Helpers::send_push_notif_to_device($fcm_token, $data);
                }
                // 站内信是所有登录顾客的兜底，不应依赖设备是否已授权 FCM。
                if (!$order->is_guest && $order->user_id) {
                    Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
                }
            }
            if ($order?->customer?->email && config('mail.status') && $mail_sattus == '1' && $notification_status?->mail_status == 'active') {
                Mail::to($order?->customer?->getRawOriginal('email'))->send(new \App\Mail\UserOfflinePaymentMail($order?->customer?->f_name . ' ' . $order?->customer?->l_name, $mail_sattus_type));
            }
        } catch (\Exception $e) {
            info('notify_offline_payment_result failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * 留痕: 谁在何时确认/拒收了离线支付 (写入 logs 表, 供审计/异常确认排查)。
     * 永不因留痕失败影响主流程(try/catch 吞掉)。
     */
    private static function log_offline_payment_action($order, $action_type, $before, $after, $confirmer_type, $confirmer_id)
    {
        try {
            \App\Models\Log::create([
                'logable_id'     => $order->id,
                'logable_type'   => \App\Models\Order::class,
                'action_type'    => $action_type,
                'model'          => 'Order',
                'model_id'       => $order->id,
                'action_details' => json_encode([
                    'offline_payment' => $action_type,
                    'by_type'         => $confirmer_type,
                    'by_id'           => $confirmer_id,
                    'at'              => now()->toDateTimeString(),
                ]),
                'before_state'   => $before,
                'after_state'    => $after,
                'restaurant_id'  => $order->restaurant_id,
            ]);
        } catch (\Throwable $e) {
            info('log_offline_payment_action failed: ' . $e->getMessage());
        }
    }


    /**
     * 哪吒 F-4 — 直付单(offline_payment)退款/取消时, 平台「通知商家退款 + 留痕」。
     *
     * 背景(B方案 L1-1): 直付单顾客的钱直付商家本人账户, 平台不碰钱。退款=商家原路退回原付款人,
     * 全程在平台外。本方法只做【通知/留痕/状态流转】, 绝不引入平台代退/平台钱包退款(item 4)。
     *
     * 行为(无视 nezha_refund_control_status 开关, 直付单必建记录):
     *   1) 仅对【已付款/已确认收款】的直付单建记录(未付款无款可退)。
     *   2) 幂等: 同单已有 pending_merchant_refund/merchant_refunded 记录则跳过(防 admin 重复点)。
     *   3) 算应退额(≤原单), 用 NezhaRefundControl::lock_route 取原路通道/USDT原地址(纯检测, 不依赖开关)。
     *   4) 建 NezhaRefundRecord(status=pending_merchant_refund) 留痕(L1-2/L1-3/L1-4)。
     *   5) 推送商家(vendor firebase_token) 提醒去原路退款 + 写 log。
     * 全程 try/catch, 留痕/通知失败绝不阻断 admin 的退款/取消主流程。
     */
    public static function record_direct_pay_refund_pending($order, $confirmer_type = 'admin', $confirmer_id = null, $reasonNote = null, $treatProofAsPaid = false)
    {
        try {
            if ($order->payment_method != 'offline_payment') {
                return null; // 仅直付单走本闭环
            }
            $op = \App\Models\OfflinePayments::where('order_id', $order->id)->first();
            $paidish = ($order->payment_status == 'paid') || ($op && $op->status == 'verified') || ($treatProofAsPaid && $op);  // 哪吒 B方案: 已提交凭证(钱已直付商家)即视为已付款
            if (!$paidish) {
                return null; // 从未真正付款/确认收款的单无款可退, 不建记录
            }
            $existing = \App\Models\NezhaRefundRecord::where('order_id', $order->id)
                ->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_MERCHANT_LIFECYCLE)
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing; // 幂等；调用方仍可读取当前阶段
            }

            $refundAmount = round(
                $order->order_amount - $order->delivery_charge - $order->dm_tips - $order->additional_charge - $order->extra_packaging_amount,
                config('round_up_to_digit')
            );
            if ($refundAmount < 0) { $refundAmount = 0; }
            if ($refundAmount > $order->order_amount) { $refundAmount = $order->order_amount; }

            $route = \App\CentralLogics\NezhaRefundControl::lock_route($order); // 纯通道/原地址检测, 不依赖退款护栏开关

            $record = \App\Models\NezhaRefundRecord::create([
                'order_id'            => $order->id,
                'refund_id'           => optional(\App\Models\Refund::where('order_id', $order->id)->first())->id,
                'restaurant_id'       => $order->restaurant_id,
                'user_id'             => $order->user_id,
                'guest_id'            => $order->is_guest ? (string) $order->user_id : null,
                'payment_channel'     => $route['channel'] ?? 'other',
                'order_amount'        => $order->order_amount,
                'refund_amount'       => $refundAmount,
                'reason_note'         => $reasonNote,
                'route_locked_note'   => $route['note'] ?? null,
                'chain'               => $route['chain'] ?? null,
                'original_tx_hash'    => $route['original_tx_hash'] ?? null,
                'locked_to_address'   => $route['locked_to_address'] ?? null,
                'chain_verify_status' => ($route['channel'] ?? '') === 'usdt' ? 'unverified' : 'na',
                'risk_action'         => 'pass',
                'status'              => 'pending_merchant_refund',
                'operator_id'         => $confirmer_id,
            ]);

            // 哪吒[退款提醒补渠道]: 通知商家去原路退款。站内信(消息中心)+Telegram(商家主渠道)无条件投递,
            // FCM 仅在有 token 时(网页端商家通常无 token)。三者各自 try/catch, 失败不阻断主流程。L1-1: 仅通知不碰钱。
            try {
                $channelText = (($route['channel'] ?? '') === 'usdt') ? 'USDT 退回原地址' : '支付宝原路退回';
                $title = '有一笔直付单需要您退款';
                $msg   = '订单 #' . $order->id . ' 已被平台取消/退款，请您按原路退还顾客付款（' . $channelText . '），退款后在「订单→待退款」标记已退款。';
                $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: 'refunded');
                $vendorId = $order->restaurant?->vendor_id;
                if ($vendorId) {
                    Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId);
                }
                try {
                    Helpers::sendTelegramToRestaurant($order->restaurant, "🔔 有一笔待退款\n订单 #" . $order->id . "\n该单已被平台取消/退款，请您按原路退还顾客付款（" . $channelText . "），退款后在商家后台「订单 → 待退款」点「标记已退款」。");
                } catch (\Throwable $e2) {}
                $vendorToken = $order->restaurant?->vendor?->firebase_token;
                if ($vendorToken) {
                    Helpers::send_push_notif_to_device($vendorToken, $data);
                }
            } catch (\Throwable $e) {
                info('record_direct_pay_refund_pending vendor notify failed: ' . $e->getMessage());
            }

            // 哪吒[退款专项2026-06-22 信任闭环]: 顾客/商家/系统侧触发的退款 → 邮件提醒超管(平台方)来看,
            // 让「顾客发起退款」不再只有商家知道。admin 自己操作的(confirmer_type='admin')不重复提醒。L1-1: 仅通知不碰钱。
            try {
                if ($confirmer_type !== 'admin' && config('mail.status')) {
                    $admin = \App\Models\Admin::where('role_id', 1)->first();
                    $adminEmail = $admin ? $admin->getRawOriginal('email') : null;
                    if ($adminEmail) {
                        $whoMap = ['customer' => '顾客', 'restaurant' => '商家', 'system' => '系统(超时自动)'];
                        $who = $whoMap[$confirmer_type] ?? $confirmer_type;
                        $channelText = (($route['channel'] ?? '') === 'usdt')
                            ? 'USDT 退回原地址'
                            : ((($route['channel'] ?? '') === 'rmb') ? '支付宝原路退回' : '见付款凭证');
                        $body = "有一笔直付订单进入待退款。\n\n"
                            . "订单号: #{$order->id}\n"
                            . "发起方: {$who}\n"
                            . "应退金额: " . \App\CentralLogics\Helpers::format_currency($refundAmount) . "\n"
                            . "原路渠道: {$channelText}\n"
                            . "商家: " . ($order->restaurant?->name ?? '-') . "\n"
                            . "原因: " . ($reasonNote ?: '-') . "\n\n"
                            . "平台不经手此款，退款由商家按原路退回顾客。请在后台「风控中心→逾期未退款」跟进商家是否按时退款。";
                        \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($adminEmail, $order) {
                            $m->to($adminEmail)->subject('【哪吒退款提醒】订单 #' . $order->id . ' 待商家退款');
                        });
                    }
                }
            } catch (\Throwable $e) {
                info('record_direct_pay_refund_pending admin mail failed: ' . $e->getMessage());
            }

            self::log_offline_payment_action($order, 'direct_pay_refund_pending', $order->order_status, $order->order_status, $confirmer_type, $confirmer_id);
            return $record;
        } catch (\Throwable $e) {
            info('record_direct_pay_refund_pending failed: order=' . ($order->id ?? '?') . ' ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 直付退款阶段通知：管理员批准只表示「待商家退款」，不表示钱已退回。
     */
    public static function notify_customer_direct_pay_refund_pending($order, $record): void
    {
        self::notify_customer_direct_pay_refund_stage($order, $record, 'pending', 'admin');
    }

    /**
     * 直付退款完成阶段通知：仅在 merchant_refunded 状态转换成功后发送。
     */
    public static function notify_customer_direct_pay_refund_completed($order, $record, $source = 'merchant'): void
    {
        self::notify_customer_direct_pay_refund_stage($order, $record, 'completed', $source);
    }

    private static function notify_customer_direct_pay_refund_stage($order, $record, string $stage, string $source): void
    {
        try {
            if (!$record) {
                return;
            }

            $lang = $order->customer?->current_language_key ?: 'zh-CN';
            $isZh = stripos($lang, 'zh') === 0;
            $channel = self::direct_pay_refund_channel_text($record->payment_channel, $isZh);
            $amount = Helpers::format_currency($record->refund_amount);

            if ($stage === 'pending') {
                $title = $isZh ? '待商家退款' : 'Waiting for restaurant refund';
                $message = $isZh
                    ? "平台已批准订单 #{$order->id} 的退款申请，商家尚未标记退款。该笔 {$amount} 将由商家按{$channel}退回；请留意支付渠道到账情况。"
                    : "The refund for order #{$order->id} was approved, but the restaurant has not marked it refunded. The restaurant must return {$amount} via {$channel}.";
                $refundStatus = 'pending_merchant_refund';
            } else {
                $verifiedByAdmin = $source === 'admin';
                $title = $isZh
                    ? ($verifiedByAdmin ? '平台已核实退款' : '商家已标记退款')
                    : ($verifiedByAdmin ? 'Refund verified by support' : 'Restaurant marked refund');
                $message = $isZh
                    ? ($verifiedByAdmin
                        ? "平台已核实订单 #{$order->id} 的 {$amount} 退款已由商家按{$channel}处理。到账时间以支付渠道为准；如未收到请联系客服。"
                        : "商家已将订单 #{$order->id} 的 {$amount} 标记为按{$channel}退款。到账时间以支付渠道为准；如未收到请联系商家或客服核实。")
                    : ($verifiedByAdmin
                        ? "Support verified that the restaurant handled the {$amount} refund for order #{$order->id} via {$channel}. Contact support if it does not arrive."
                        : "The restaurant marked the {$amount} refund for order #{$order->id} via {$channel}. Contact the restaurant or support if it does not arrive.");
                $refundStatus = 'merchant_refunded';
            }

            $data = Helpers::makeDataForPushNotification(
                title: $title,
                message: $message,
                orderId: $order->id,
                type: 'order_status',
                orderStatus: 'refunded'
            );
            $data['nezha_refund_status'] = $refundStatus;

            if (!$order->is_guest && $order->user_id) {
                Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
            }

            $token = $order->is_guest
                ? $order->guest?->fcm_token
                : $order->customer?->cm_firebase_token;
            if ($token && Helpers::customerWantsPush($order->customer, 'refund')) {
                Helpers::send_push_notif_to_device($token, $data);
            }
        } catch (\Throwable $e) {
            info('notify_customer_direct_pay_refund_stage failed: order=' . ($order->id ?? '?') . ' ' . $e->getMessage());
        }
    }

    private static function direct_pay_refund_channel_text($channel, bool $isZh): string
    {
        if ($channel === 'usdt') {
            return $isZh ? 'USDT 原地址' : 'the original USDT address';
        }
        if ($channel === 'rmb') {
            return $isZh ? '支付宝原路' : 'the original Alipay method';
        }
        return $isZh ? '原支付方式' : 'the original payment method';
    }

    /**
     * 哪吒 — 统一「取消订单收尾」(B方案 L1-1 平台不碰钱)。
     * 三条路共用: ①顾客接单后申请取消→商家同意 ②商家主动拒单 ③(防御)后台/超时取消。
     * 行为: 置 canceled + 来源/理由/备注 → 回退销量 → 增订单计数 →
     *   已付直付单则 mark offline_payments=canceled + 生成 pending_merchant_refund 留痕 +
     *   通知顾客「联系商家原路退回」(平台绝不自动退款) → send_order_notification。
     * 返回 bool: 是否生成了待退款留痕(已付款单)。
     * 注: 调用方需已 ->with('details') 加载明细(decreaseSellCount 用)。
     */
    public static function finalize_cancellation($order, $canceled_by = 'customer', $reason = null, $note = null, $actor_id = null)
    {
        $order->order_status = 'canceled';
        $order->canceled = now();
        $order->cancellation_reason = $reason;
        $order->cancellation_note = $note;
        $order->canceled_by = $canceled_by;
        if ($order->nezha_cancel_request === 'requested') {
            $order->nezha_cancel_request = 'approved';
            $order->nezha_cancel_responded_at = now();
        }
        $order->save();

        Helpers::decreaseSellCount(order_details: $order->details);
        Helpers::increment_order_count($order->restaurant);

        $refund_pending = false;
        $nezha_offline_proof = $order->payment_method == 'offline_payment'
            ? \App\Models\OfflinePayments::where('order_id', $order->id)->whereIn('status', ['pending', 'verified', 'denied'])->first()
            : null;
        if ($nezha_offline_proof) {
            \App\Models\OfflinePayments::where('order_id', $order->id)->update(['status' => 'canceled']);
            $reasonNote = ($note ?: $reason) ? ('订单已取消：' . ($note ?: $reason)) : '订单已取消，已支付款项需商家原路退回';
            self::record_direct_pay_refund_pending($order, $canceled_by, $actor_id, $reasonNote, true);
            $refund_pending = true;
            self::notify_customer_cancel_refund($order);
        }

        Helpers::send_order_notification($order);
        return $refund_pending;
    }

    /**
     * 哪吒 — 给顾客发「订单已取消，请联系商家原路退款」站内信 + 推送(已付直付单专用)。
     * 平台不经手此款(L1-1),文案永远指向商家原路退,绝不出现"平台已退款"。失败不阻断主流程。
     */
    public static function notify_customer_cancel_refund($order)
    {
        try {
            $nezha_zh = stripos(($order->customer?->current_language_key ?: 'zh'), 'zh') === 0;
            $title = $nezha_zh ? '订单已取消' : 'Order canceled';
            $msg = $nezha_zh
                ? '你的订单 #' . $order->id . ' 已取消。此前直接付给商家的款项，请在订单页点『联系商家』按原路退回。'
                : 'Your order #' . $order->id . ' is canceled. For the amount paid directly to the restaurant, please contact the restaurant for an original-route refund.';
            $fcm = $order->is_guest == 0 ? $order?->customer?->cm_firebase_token : null;
            $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: 'canceled');
            // 哪吒: 顾客「订单进度」推送偏好闸(B方案取消退款提醒)
            if ($fcm && Helpers::customerWantsPush($order->customer, 'refund')) { Helpers::send_push_notif_to_device($fcm, $data); }
            if ($order->is_guest == 0) { Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id); Helpers::markCancelNotified($order->id); }
        } catch (\Throwable $e) {
            info('notify_customer_cancel_refund failed: ' . $e->getMessage());
        }
    }
}
