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
    public static function create_transaction($order, $received_by=false, $status = null)
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
                $amount_admin = $comission?($order->restaurant_discount_amount/ 100) * $comission:0;
                $restaurant_d_amount=  $order->restaurant_discount_amount- $amount_admin;
                Helpers::expenseCreate( amount:$restaurant_d_amount,type:'discount_on_product',datetime:now(),order_id:  $order->id,created_by:  'vendor',restaurant_id:$order?->restaurant?->id);
                Helpers::expenseCreate( amount:$amount_admin,type:'discount_on_product',datetime:now(),order_id:  $order->id,created_by:  'admin');
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


        $order_amount = $order->order_amount - $order->additional_charge - $order->extra_packaging_amount - $order->delivery_charge - $order->total_tax_amount - $order->dm_tips - $order->delivery_type_charge + $order->coupon_discount_amount + $restaurant_discount_amount + $ref_bonus_amount;

        if ($order->delivery_type === 'express') {
            $order_amount -= $order->delivery_type_charge;
        } elseif ($order->delivery_type === 'slightly_delay') {
            $order_amount += $order->delivery_type_charge;
        }

        if($restaurant->restaurant_model == 'subscription' && isset($rest_sub)){
            $comission_amount =0;
            $subscription_mode= 1;
            $commission_percentage= 0;
        }
        else{
            $comission_amount = $comission?($order_amount/ 100) * $comission:0;
            $subscription_mode= 0;
            $commission_percentage= $comission;
        }

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
                    $nezha_deposit_mode = BusinessSetting::where('key','nezha_deposit_mode_status')->first()?->value;
                    if($nezha_deposit_mode == 1 && $comission_amount > 0){
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
    public static function confirm_offline_payment($order, $confirmer_type = 'admin', $confirmer_id = null, $allow_inconclusive = false)
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

        $order->payment_status = 'paid';
        $order->confirmed = now();
        $order->order_status = 'confirmed';
        $order->save();
        $order->offline_payments()->update([
            'status' => 'verified',
        ]);

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
        if ($status == 'approved') {
            Helpers::send_order_notification($order);

            $notification_text  = 'offline_verified';
            $notification_title = translate('messages.Your_Offline_payment_was_approved');
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
            $notification_title = translate('messages.Your_Offline_payment_was_rejected');
            $mail_sattus_type   = 'denied';
            $mail_sattus        = Helpers::get_mail_status('offline_payment_deny_mail_status_user');
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_offline_payment_deny');
        }

        try {
            $fcm_token = ($order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token) ?? null;
            $message = Helpers::getOrderPushNotificationMessage($order, $notification_text, 'user', $order->customer ? $order?->customer?->current_language_key : 'en');
            if ($message && isset($fcm_token)) {
                $data = Helpers::makeDataForPushNotification(title: $notification_title, message: $message, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
                Helpers::send_push_notif_to_device($fcm_token, $data);
                Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
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
    public static function record_direct_pay_refund_pending($order, $confirmer_type = 'admin', $confirmer_id = null, $reasonNote = null)
    {
        try {
            if ($order->payment_method != 'offline_payment') {
                return; // 仅直付单走本闭环
            }
            $op = \App\Models\OfflinePayments::where('order_id', $order->id)->first();
            $paidish = ($order->payment_status == 'paid') || ($op && $op->status == 'verified');
            if (!$paidish) {
                return; // 从未真正付款/确认收款的单无款可退, 不建记录
            }
            $exists = \App\Models\NezhaRefundRecord::where('order_id', $order->id)
                ->whereIn('status', ['pending_merchant_refund', 'merchant_refunded'])
                ->exists();
            if ($exists) {
                return; // 幂等
            }

            $refundAmount = round(
                $order->order_amount - $order->delivery_charge - $order->dm_tips - $order->additional_charge - $order->extra_packaging_amount,
                config('round_up_to_digit')
            );
            if ($refundAmount < 0) { $refundAmount = 0; }
            if ($refundAmount > $order->order_amount) { $refundAmount = $order->order_amount; }

            $route = \App\CentralLogics\NezhaRefundControl::lock_route($order); // 纯通道/原地址检测, 不依赖退款护栏开关

            \App\Models\NezhaRefundRecord::create([
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

            // 推送商家: 去自己账户按原路退还原付款人。失败不阻断。
            try {
                $vendorToken = $order->restaurant?->vendor?->firebase_token;
                if ($vendorToken) {
                    $channelText = (($route['channel'] ?? '') === 'usdt') ? 'USDT 退回原地址' : '微信/支付宝原路退回';
                    $title = '有一笔直付单需要您退款';
                    $msg   = '订单 #' . $order->id . ' 已被平台取消/退款，请按原路退还顾客付款（' . $channelText . '），退款后在「订单→待退款」标记已退款。';
                    $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: 'refunded');
                    Helpers::send_push_notif_to_device($vendorToken, $data);
                }
            } catch (\Throwable $e) {
                info('record_direct_pay_refund_pending push failed: ' . $e->getMessage());
            }

            self::log_offline_payment_action($order, 'direct_pay_refund_pending', $order->order_status, $order->order_status, $confirmer_type, $confirmer_id);
        } catch (\Throwable $e) {
            info('record_direct_pay_refund_pending failed: order=' . ($order->id ?? '?') . ' ' . $e->getMessage());
        }
    }
}
