<?php

namespace App\Http\Controllers\Vendor;

use App\Models\DataSetting;
use App\Models\Order;
use App\Models\DeliveryMan;
use App\Exports\OrderExport;
use App\Models\OrderPayment;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCustomerNudge;
use App\CentralLogics\OrderLogic;
use App\Exports\OrderRefundExport;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Models\Food;
use App\Models\ItemCampaign;
use App\Models\OrderDetail;
use App\Models\Restaurant;
use App\Models\Zone;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use App\Traits\PlaceNewOrder;
use Illuminate\Support\Facades\Session;
use MatanYadaev\EloquentSpatial\Objects\Point;

class OrderController extends Controller
{
    use PlaceNewOrder;
    public function list($status, Request $request)
    {
        $key = explode(' ', $request['search']);

        $data = 0;
        $restaurant = Helpers::get_restaurant_data();
        if (($restaurant->restaurant_model == 'subscription' &&  $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1)) {
            $data = 1;
        }

        Order::where(['checked' => 0])->where('restaurant_id', Helpers::get_restaurant_id())->update(['checked' => 1]);

        $orders = Order::with(['customer', 'offline_payments', 'details'])
            ->when($status == 'searching_for_deliverymen', function ($query) {
                return $query->SearchingForDeliveryman();
            })
            ->when($status == 'confirmed', function ($query) {
                return $query->whereIn('order_status', ['confirmed', 'accepted'])->whereNotNull('confirmed');
            })
            ->when($status == 'pending', function ($query) use ($data) {
                if (config('order_confirmation_model') == 'restaurant' || $data) {
                    return $query->where('order_status', 'pending');
                } else {
                    return $query->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                }
            })
            ->when($status == 'cooking', function ($query) {
                return $query->where('order_status', 'processing');
            })
            ->when($status == 'accepted', function ($query) {
                return $query->where('order_status', 'accepted');
            })
            ->when($status == 'food_on_the_way', function ($query) {
                return $query->where('order_status', 'picked_up');
            })
            ->when($status == 'delivered', function ($query) {
                return $query->Delivered();
            })
            ->when($status == 'ready_for_delivery', function ($query) {
                return $query->where('order_status', 'handover');
            })
            ->when($status == 'refund_requested', function ($query) {
                return $query->Refund_requested();
            })
            ->when($status == 'refunded', function ($query) {
                return $query->Refunded();
            })
            ->when($status == 'payment_failed', function ($query) {
                return $query->where('order_status', 'failed');
            })
            ->when($status == 'canceled', function ($query) {
                return $query->where('order_status', 'canceled');
            })
            ->when($status == 'dine_in', function ($query) {
                return $query->where('order_type', 'dine_in');
            })
            // 哪吒 B方案: 「待确认收款」视图 = 本店 pending 且离线支付待核验的单。
            // 这是补掉单根因 —— 此前 pending+offline 单被 NotDigitalOrder 隐藏, 商家完全看不到。
            ->when($status == 'offline_pending', function ($query) {
                return $query->where('order_status', 'pending')
                    ->where('payment_method', 'offline_payment')
                    ->whereHas('offline_payments', function ($q) {
                        $q->where('status', 'pending');
                    });
            })
            // 哪吒 F-4: 「待退款」视图 = 本店有 pending_merchant_refund 留痕的单(平台已取消/退款, 等商家原路退款)。
            ->when($status == 'refund_pending', function ($query) {
                return $query->whereIn('id', function ($sub) {
                    $sub->select('order_id')->from('nezha_refund_records')->where('status', 'pending_merchant_refund');
                });
            })
            // 哪吒 M-02: 「超时单」虚拟过滤 = 本店当前 warning/error 超时的开放单(offline_pending+confirmed+processing 并集)。
            // 计数口径与 Dashboard 超时卡完全同源(NezhaOrderTimeout::alertOrderIds)。severity 判定纯 SQL 表达不出,
            // 故走 PHP 预算 ID 集合 → whereIn('id',$ids); 空集用 [0] 保证返回空, 不退化成"全部单"。
            ->when($status == 'timeout', function ($query) {
                $ids = \App\CentralLogics\NezhaOrderTimeout::alertOrderIds(
                    \App\CentralLogics\Helpers::get_restaurant_id()
                );
                return $query->whereIn('id', $ids ?: [0]);
            })
            ->when($status == 'customer_nudged', function ($query) {
                $ids = NezhaCustomerNudge::openOrderIds(
                    \App\CentralLogics\Helpers::get_restaurant_id()
                );
                return $query->whereIn('id', $ids ?: [0]);
            })
            // ->when($status == 'assinged', function($query){
            //     return $query->whereNotIn('order_status',['failed','canceled', 'refund_requested', 'refunded','delivered','refund_request_canceled'])->whereNotNull('delivery_man_id');
            // })

            ->when($status == 'scheduled', function ($query) use ($data) {
                return $query->Scheduled()->where(function ($q) use ($data) {
                    if (config('order_confirmation_model') == 'restaurant' || $data) {
                        $q->whereNotIn('order_status', ['failed', 'canceled', 'refund_requested', 'refunded']);
                    } else {
                        $q->whereNotIn('order_status', ['pending', 'failed', 'canceled', 'refund_requested', 'refunded'])->orWhere(function ($query) {
                            $query->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                        });
                    }
                });
            })
            ->when($status == 'all', function ($query) use ($data) {
                return $query->where(function ($q1) use ($data) {
                    $q1->whereNotIn('order_status', (config('order_confirmation_model') == 'restaurant' || $data) ? ['failed', 'canceled', 'refund_requested', 'refunded'] : ['pending', 'failed', 'canceled', 'refund_requested', 'refunded'])
                        ->orWhere(function ($q2) {
                            return $q2->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                        })->orWhere(function ($q3) {
                            return $q3->where('order_status', 'pending')->whereNotNull('subscription_id');
                        });
                });
            })
            ->when(in_array($status, ['pending', 'confirmed']), function ($query) {
                return $query->OrderScheduledIn(30);
            })
            ->when(isset($key), function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('id', 'like', "%{$value}%")
                            ->orWhere('order_status', 'like', "%{$value}%")
                            ->orWhere('transaction_reference', 'like', "%{$value}%");
                    }
                });
            })
            ->Notpos()
            // 哪吒: 「待确认收款」+「待退款」+「超时单」+「客户催促」视图放开 NotDigitalOrder(它会隐藏 pending+offline 单); 其余视图行为不变。
            // 超时集合含 pending+offline_payment 单, 漏放行会被 NotDigitalOrder 静默隐藏 → 列表数<卡数, 破坏数字同源。
            ->when(!in_array($status, ['offline_pending', 'refund_pending', 'timeout', 'customer_nudged'], true), function ($query) {
                return $query->NotDigitalOrder();
            })
            ->hasSubscriptionToday()
            ->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())
            ->orderBy('schedule_at', 'desc')
            ->paginate(config('default_pagination'));

        $st = $status;
        // 哪吒: offline_pending / refund_pending / timeout / customer_nudged 无翻译 key, 直接给中文标题避免显示英文键名。
        $status = $status == 'offline_pending' ? '待确认收款'
            : ($status == 'refund_pending' ? '待退款'
            : ($status == 'timeout' ? '超时单'
            : ($status == 'customer_nudged' ? '客户催促'
            : translate('messages.' . $status))));
        return view('vendor-views.order.list', compact('orders', 'status', 'st', 'restaurant'));
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $orders = Order::where(['restaurant_id' => Helpers::get_restaurant_id()])->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('id', 'like', "%{$value}%")
                    ->orWhere('order_status', 'like', "%{$value}%")
                    ->orWhere('transaction_reference', 'like', "%{$value}%");
            }
        })->Notpos()
            ->NotDigitalOrder()
            ->limit(100)->get();
        return response()->json([
            'view' => view('vendor-views.order.partials._table', compact('orders'))->render()
        ]);
    }

    public function details(Request $request, $id)
    {
        $data = 0;
        $restaurant = Helpers::get_restaurant_data();
        if (($restaurant->restaurant_model == 'subscription' &&  $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1)) {
            $data = 1;
        }
        $status = 'all';

        $order = Order::with(['offline_payments', 'payments', 'subscription', 'subscription.schedule_today', 'details.food', 'customer' => function ($query) {
            return $query->withCount('orders');
        }, 'delivery_man' => function ($query) {
            return $query->withCount('orders');
        }])->where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])

            ->Notpos()
            // 哪吒: 等价于 NotDigitalOrder(排除 pending+digital), 但放开本店 offline_payment 单 ——
            // 商家需在 pending 阶段看到该单并「确认收款」(B方案 §4)。本查询已按 id+restaurant_id 限定为本店单, 不外泄。
            ->where(function ($q) {
                $q->whereNotIn('payment_method', ['digital_payment', 'offline_payment'])
                  ->orWhereNot('order_status', 'pending')
                  ->orWhere('payment_method', 'offline_payment');
            })
            ->when($status == 'all', function ($query) use ($data) {
                return $query->where(function ($q1) use ($data) {
                    $q1->whereNotIn('order_status', (config('order_confirmation_model') == 'restaurant' || $data) ? [''] : ['pending'])
                        ->orWhere(function ($q2) {
                            return $q2->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                        })->orWhere(function ($q3) {
                            return $q3->where('order_status', 'pending')->whereNotNull('subscription_id');
                        });
                });
            })
            // ->hasSubscriptionToday()
            ->first();

        if (isset($order)) {
            $deliveryMen = DeliveryMan::with('last_location')->where('restaurant_id', Helpers::get_restaurant_id())->active()->get();
            $deliveryMen = Helpers::deliverymen_list_formatting(data: $deliveryMen, restaurant_lat: $order?->restaurant?->latitude, restaurant_lng: $order?->restaurant?->longitude);

            $selected_delivery_man = DeliveryMan::with('last_location')->where('id', $order->delivery_man_id)->first() ?? [];
            if ($order->delivery_man) {
                $selected_delivery_man = Helpers::deliverymen_list_formatting(data: $selected_delivery_man, restaurant_lat: $order?->restaurant?->latitude, restaurant_lng: $order?->restaurant?->longitude, single_data: true);
            }
            if (Helpers::employee_module_permission_check('regular_order') && Helpers::employee_module_permission_check('subscription_order')) {
                return view('vendor-views.order.order-view', compact('order', 'selected_delivery_man', 'deliveryMen'));
            }
            if ((Helpers::employee_module_permission_check('regular_order') && !$order->subscription_id)) {
                return view('vendor-views.order.order-view', compact('order', 'selected_delivery_man', 'deliveryMen'));
            }
            if ((Helpers::employee_module_permission_check('subscription_order') && $order->subscription_id)) {
                return view('vendor-views.order.order-view', compact('order', 'selected_delivery_man', 'deliveryMen'));
            }
            Toastr::error('access_denied!');
            return back();
        } else {
            Toastr::info('No more orders!');
            return back();
        }
    }

    /**
     * 哪吒 B方案 — 商家自营「确认收款」。
     * 商家在自己账户收到顾客转账(人民币/USDT)后, 对本店该订单确认收款,
     * 订单 pending -> confirmed, 顾客收到「支付已验证」通知, 商家即可出餐。
     *
     * 合规(INVARIANTS L1-1): 商家确认的是"我已在自己账户收到顾客货款", 钱全程不经平台。
     * 强校验: 只能确认【本店】【离线支付】【offline_payments.status=pending】的单(防越权/防重复确认)。
     */
    public function confirm_offline_payment(Request $request, $id)
    {
        $order = Order::with('offline_payments')
            ->where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])
            ->first();

        if (!$order) {
            Toastr::error(translate('messages.order_not_found'));
            return back();
        }
        // 哪吒 H3(QA 2026-06-18): 已结束的单(顾客取消/拒收/退款)不可被"确认收款"复活。
        if (in_array($order->order_status, ['canceled', 'failed', 'refunded', 'refund_requested', 'refund_request_canceled'], true)) {
            Toastr::warning(translate('messages.this_order_has_ended_cannot_confirm_payment'));
            return back();
        }
        if ($order->payment_method != 'offline_payment' || !$order->offline_payments || $order->offline_payments->status != 'pending') {
            // 已被(商家自己/admin/其他端)处理过, 或本就不是待核验离线单 —— 幂等保护, 不重复执行。
            Toastr::warning(translate('messages.Payment_status_updated'));
            return back();
        }

        try {
            \App\CentralLogics\OrderLogic::confirm_offline_payment($order, 'vendor', auth('vendor')->id() ?? auth('vendor_employee')->id());
        } catch (\App\Exceptions\SanctionScreenException $e) {
            // 哪吒 A1: reject(命中制裁) 与 hold(来源待核验) 都抛此异常, 按类型给固定脱敏文案。
            // 不裸透传 $e->getMessage(): reject 的 message 含 from/tx/sdn_uid 命中内幕, 会泄露给商家。
            if (str_contains($e->getMessage(), '命中制裁名单')) {
                Toastr::error('该单付款来源命中制裁名单，已自动拒收，请勿出餐并联系平台。');
            } else {
                Toastr::error('该单付款来源正在核验中（非拒收），暂不能确认收款，请稍后重试或联系平台。');
            }
            return back();
        }

        Toastr::success(translate('messages.Payment_status_updated'));
        return back();
    }

    /**
     * 哪吒 B方案 — 商家「拒收 / 打回」离线支付。
     * 商家未在账户收到款 / 凭证有问题时, 标记 denied + 备注, 顾客收到通知可重传凭证或取消。
     * 不改订单主状态(仍 pending), 钱不经平台。强校验同上。
     */
    public function deny_offline_payment(Request $request, $id)
    {
        $request->validate([
            'note' => 'required|string|max:255',
        ], [
            'note.required' => translate('messages.Add_Offline_Payment_Rejection_Note'),
        ]);

        $order = Order::with('offline_payments')
            ->where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])
            ->first();

        if (!$order) {
            Toastr::error(translate('messages.order_not_found'));
            return back();
        }
        if ($order->payment_method != 'offline_payment' || !$order->offline_payments || $order->offline_payments->status != 'pending') {
            Toastr::warning(translate('messages.Payment_status_updated'));
            return back();
        }

        \App\CentralLogics\OrderLogic::deny_offline_payment($order, $request->note, 'vendor', auth('vendor')->id() ?? auth('vendor_employee')->id());

        Toastr::success(translate('messages.Payment_status_updated'));
        return back();
    }

    /**
     * 哪吒 F-4 — 商家「标记已退款」直付单。
     * 平台取消/退款直付单后生成 pending_merchant_refund 留痕; 商家在自己账户原路退还原付款人后,
     * 在此标记已退款(可选填 USDT 退款 tx hash / 备注), 记录转 merchant_refunded。
     *
     * 合规(L1-1): 平台全程不碰钱, 本动作仅更新留痕状态, 不触发任何资金记账。
     * 强校验(轴A 对象级鉴权): 只能标记【本店】且【pending_merchant_refund】的记录(防越权/防重复)。
     */
    public function mark_refunded(Request $request, $id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::error(translate('messages.order_not_found'));
            return back();
        }
        $record = \App\Models\NezhaRefundRecord::where('order_id', $order->id)
            ->where('restaurant_id', Helpers::get_restaurant_id())
            ->where('status', 'pending_merchant_refund')
            ->latest('id')
            ->first();
        if (!$record) {
            Toastr::warning(translate('messages.Payment_status_updated'));
            return back();
        }
        $record->status = 'merchant_refunded';
        $record->merchant_refunded_at = now();
        $record->merchant_refund_note = $request->note ? mb_substr($request->note, 0, 255) : null;
        if ($record->payment_channel === 'usdt' && $request->refund_tx_hash) {
            $record->refund_tx_hash = trim($request->refund_tx_hash);
            if (in_array($record->chain_verify_status, ['na', null], true)) {
                $record->chain_verify_status = 'unverified';
            }
        }
        $record->save();

        // 哪吒: 商家标记已退款后, 若该店已无逾期未退款留痕, 自动解除退款逾期接单暂停(非资金)。
        \App\CentralLogics\NezhaRefundOverdue::lift_suspend_if_clear($record->restaurant_id);

        // 哪吒[退款专项2026-06-22 信任闭环]: 商家标记已退款 → 主动给顾客站内信(+FCM若有token),
        // 让顾客知道钱已按原路退回、不必干等; 不再只依赖顾客重开订单页才看到。L1-1: 仅通知不碰钱。
        try {
            if ($order->user_id && !$order->is_guest) {
                $lang = $order->customer?->current_language_key ?: 'zh-CN';
                $isZh = stripos($lang, 'zh') === 0;
                $chanText = $record->payment_channel === 'usdt'
                    ? ($isZh ? 'USDT 原地址' : 'your original USDT address')
                    : ($record->payment_channel === 'rmb'
                        ? ($isZh ? '支付宝原路' : 'Alipay (original method)')
                        : ($isZh ? '原支付方式' : 'your original payment method'));
                $amt = \App\CentralLogics\Helpers::format_currency($record->refund_amount);
                $title = $isZh ? '退款已处理' : 'Refund processed';
                $msg = $isZh
                    ? "商家已为订单 #{$order->id} 按{$chanText}退回 {$amt}。到账时间以您的支付渠道为准；如未收到请联系商家或客服。"
                    : "The restaurant refunded {$amt} for order #{$order->id} via {$chanText}. Arrival time depends on your payment channel; contact the restaurant or support if not received.";
                $data = \App\CentralLogics\Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: 'refunded');
                \App\CentralLogics\Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
                $token = $order->customer?->cm_firebase_token;
                if ($token && \App\CentralLogics\Helpers::customerWantsPush($order->customer, 'refund')) {
                    \App\CentralLogics\Helpers::send_push_notif_to_device($token, $data);
                }
            }
        } catch (\Throwable $e) {
            info('mark_refunded notify customer failed: ' . $e->getMessage());
        }

        // 哪吒[退款完成回平台]: 商家标记已退款后, 主动给平台超管发提醒(Telegram + 邮件), 让平台知道此单已闭环、可抽查。
        // 现状只在「待退款生成」时给 admin 发邮件, 完成侧是静默的; 补这条对称。L1-1: 仅通知不碰钱。
        try {
            $chanText = $record->payment_channel === 'usdt' ? 'USDT 退回原地址' : ($record->payment_channel === 'rmb' ? '支付宝原路退回' : '原支付方式');
            $amt = \App\CentralLogics\Helpers::format_currency($record->refund_amount);
            $restName = $order->restaurant?->name ?? '-';
            try {
                \App\CentralLogics\Helpers::sendTelegramToAdmin("✅ 商家已标记退款\n订单 #" . $order->id . "\n商家：" . $restName . "\n应退：" . $amt . "（" . $chanText . "）\n请抽查商家是否真按原路退还顾客。");
            } catch (\Throwable $e3) {}
            if (config('mail.status')) {
                $admin = \App\Models\Admin::where('role_id', 1)->first();
                $adminEmail = $admin ? $admin->getRawOriginal('email') : null;
                if ($adminEmail) {
                    $body = "商家已标记一笔直付订单为已退款。\n\n订单号: #" . $order->id . "\n商家: " . $restName . "\n应退金额: " . $amt . "\n原路渠道: " . $chanText . "\n商家备注: " . ($record->merchant_refund_note ?: '-') . "\n\n平台不经手此款, 退款由商家按原路退回顾客。建议抽查核对。";
                    \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($adminEmail, $order) {
                        $m->to($adminEmail)->subject('【哪吒退款完成】订单 #' . $order->id . ' 商家已标记退款');
                    });
                }
            }
        } catch (\Throwable $e) {
            info('mark_refunded notify admin failed: ' . $e->getMessage());
        }

        Toastr::success(translate('已标记为已退款，感谢您的原路退还。'));
        return back();
    }

    /**
     * 哪吒效率(2026-06-21): 商家在「备餐中」更新【本单】预计出餐时间(分钟)。
     * 单笔级, 不改店铺默认时长(restaurant.delivery_time); 顾客端 track 实时读 processing_time 反映新 ETA。
     * 对象级鉴权: 仅本店单; 仅 processing 态可改。纯展示数值更新, 不动状态机/资金(L3)。
     */
    public function update_processing_time(Request $request, $id)
    {
        $request->validate([
            'processing_time' => 'required|integer|min:1|max:1440',
        ], [
            'processing_time.required' => '请填写预计出餐时间（分钟）',
            'processing_time.integer'  => '预计出餐时间需为整数分钟',
            'processing_time.min'      => '预计出餐时间至少 1 分钟',
            'processing_time.max'      => '预计出餐时间过大（最多 1440 分钟）',
        ]);
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::warning('订单不存在或无权操作');
            return back();
        }
        if ($order->order_status !== 'processing') {
            Toastr::warning('仅在「备餐中」可更新预计出餐时间');
            return back();
        }
        $order->processing_time = (int) $request->processing_time;
        $order->save();
        Toastr::success('本单预计出餐时间已更新为 ' . $order->processing_time . ' 分钟。');
        return back();
    }

    public function mark_dispatched(Request $request, $id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::warning('订单不存在或无权操作');
            return back();
        }
        if ($order->order_type !== 'delivery') {
            Toastr::warning('仅配送订单需要标记配送中');
            return back();
        }
        if (!in_array($order->order_status, ['processing', 'handover'], true)) {
            Toastr::warning('请在「备餐中 / 已出餐」状态、呼叫 Yandex 后再标记为配送中');
            return back();
        }
        // 哪吒 B方案: 商家叫车后一拍标记「配送中」(picked_up), 无需 Yandex 链接, 顾客立即看到配送中。
        // 配送单不单独显示"已出餐"拍, 但 handover 时间戳是超时+24h自动结算的承重墙, 这里照样补记。
        if (empty($order->handover)) {
            $order->handover = now();
        }
        $order->order_status = 'picked_up';
        $order->picked_up = now();
        $order->save();

        if (!Helpers::send_order_notification($order)) {
            Toastr::warning(translate('messages.push_notification_faild'));
        }

        Toastr::success('已标记为「配送中」，顾客已看到。如需顾客实时追踪，可在下方贴上 Yandex 分享链接。');
        return back();
    }

    public function set_yandex_delivery(Request $request, $id)
    {
        $request->validate([
            'yandex_tracking_url' => 'required|url|max:1024',
        ], [
            'yandex_tracking_url.required' => '请粘贴 Yandex 配送追踪链接',
            'yandex_tracking_url.url' => '链接格式不正确，请粘贴完整的 Yandex 链接',
            'yandex_tracking_url.max' => '链接过长，请检查是否粘贴了正确的内容',
        ]);

        $url = trim($request->yandex_tracking_url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        // 安全: 只放行 https 的 Yandex 官方域, 防商家粘贴野链接/伪协议推给顾客。
        $is_yandex = $scheme === 'https' && ($host === 'yandex.ru' || $host === 'yandex.com'
            || str_ends_with($host, '.yandex.ru') || str_ends_with($host, '.yandex.com'));
        if (!$is_yandex) {
            Toastr::warning('只接受 Yandex 官方链接（yandex.ru / yandex.com），请从 Yandex Go 的“分享”复制完整链接');
            return back();
        }

        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::warning('订单不存在或无权操作');
            return back();
        }
        if ($order->order_type !== 'delivery') {
            Toastr::warning('仅配送订单需要填写 Yandex 配送链接');
            return back();
        }
        if (!in_array($order->order_status, ['processing', 'handover', 'picked_up'], true)) {
            Toastr::warning('请在「备餐中 / 已出餐 / 配送中」状态下填写 Yandex 配送链接');
            return back();
        }

        $order->yandex_tracking_url = $url;
        $order->delivery_link_reminded_at = null;
        // 哪吒 B方案: 商家填入配送链接 = 配送真实出发, 状态推进到「配送中」(picked_up)。
        // processing/handover 都推进到 picked_up(并补记 handover 承重墙时间戳); 已是 picked_up 只更新链接。
        if (in_array($order->order_status, ['processing', 'handover'], true)) {
            if (empty($order->handover)) {
                $order->handover = now();
            }
            $order->order_status = 'picked_up';
            $order->picked_up = now();
        }
        $order->save();

        if (!Helpers::send_order_notification($order)) {
            Toastr::warning(translate('messages.push_notification_faild'));
        }

        Toastr::success('已保存 Yandex 配送链接，订单已更新为“配送中”，顾客现在可实时查看配送进度。');
        return back();
    }

    /**
     * 哪吒 B方案 — 商家「标记已送达」配送单(A: 让最先知道送达的商家也能收尾)。
     * Yandex 外部配送无回调; 商家叫的车、Yandex Go 司机送达会先通知商家。
     * 仅本店、配送单、picked_up/handover 可标; 复用幂等 settle_delivered(恰好结算一次); 无 OTP(配送无面对面核销)。
     */
    public function mark_delivered(Request $request, $id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::warning('订单不存在或无权操作');
            return back();
        }
        if ($order->order_type !== 'delivery') {
            Toastr::warning('仅配送订单用此入口标记已送达');
            return back();
        }
        if ($order->delivered) {
            Toastr::info('本单已是「已送达」');
            return back();
        }
        if (!in_array($order->order_status, ['picked_up', 'handover'], true)) {
            Toastr::warning('请在「配送中 / 已出餐」状态、确认 Yandex 已送达后再标记');
            return back();
        }
        \App\CentralLogics\OrderLogic::settle_delivered($order, 'vendor', auth('vendor')->id() ?? auth('vendor_employee')->id());
        Toastr::success('已标记为「已送达」，本单完成。');
        return back();
    }

    public function status(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'order_status' => 'required|in:confirmed,processing,handover,delivered,canceled',
            'reason' => 'required_if:order_status,canceled',
            'processing_time' => 'required_if:order_status,processing|integer|min:1',
        ], [
            'id.required' => 'Order id is required!',
            'processing_time.required_if' => '请填写预计出餐时间（分钟）后再接单',
            'processing_time.integer' => '预计出餐时间需为整数分钟',
            'processing_time.min' => '预计出餐时间至少 1 分钟',
        ]);

        $order = Order::where(['id' => $request->id, 'restaurant_id' => Helpers::get_restaurant_id()])->with(['subscription_logs', 'details'])->first();

        if ($order->delivered != null) {
            Toastr::warning(translate('messages.cannot_change_status_after_delivered'));
            return back();
        }

        if ($request['order_status'] == 'canceled' && !config('canceled_by_restaurant')) {
            Toastr::warning(translate('messages.you_can_not_cancel_a_order'));
            return back();
        }

        if ($request['order_status'] == 'canceled' && $order->confirmed) {
            Toastr::warning(translate('messages.you_can_not_cancel_after_confirm'));
            return back();
        }

        $data = 0;
        $restaurant = Helpers::get_restaurant_data();
        if (($restaurant->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1)) {
            $data = 1;
        }

        if ($request['order_status'] == 'delivered' && !in_array($order['order_type'], ['dine_in', 'take_away']) && !$data) {
            Toastr::warning(translate('messages.you_can_not_delivered_delivery_order'));
            return back();
        }

        if ($request['order_status'] == "confirmed") {
            if (!$data && config('order_confirmation_model') == 'deliveryman' && !in_array($order['order_type'], ['dine_in', 'take_away']) && $order->subscription_id == null) {
                Toastr::warning(translate('messages.order_confirmation_warning'));
                return back();
            }
        }

        if ($request->order_status == 'delivered') {
            $order_delivery_verification = (bool)\App\Models\BusinessSetting::where(['key' => 'order_delivery_verification'])->first()?->value;
            if ($order_delivery_verification) {
                if ($request->otp) {
                    if ($request->otp != $order->otp) {
                        Toastr::warning(translate('messages.order_varification_code_not_matched'));
                        return back();
                    }
                } else {
                    Toastr::warning(translate('messages.order_varification_code_is_required'));
                    return back();
                }
            }
            if (isset($order->subscription_id) && count($order->subscription_logs) == 0) {
                Toastr::warning(translate('messages.You_Can_Not_Delivered_This_Subscription_order_Before_Schedule'));
                return back();
            }

            if ($order->transaction  == null || isset($order->subscription_id)) {
                $unpaid_payment = OrderPayment::where('payment_status', 'unpaid')->where('order_id', $order->id)->first()?->payment_method;
                $unpaid_pay_method = 'digital_payment';
                if ($unpaid_payment) {
                    $unpaid_pay_method = $unpaid_payment;
                }

                if ($order->payment_method == 'cash_on_delivery' || $unpaid_pay_method == 'cash_on_delivery') {
                    $ol = OrderLogic::create_transaction(order: $order, received_by: 'restaurant', status: null);
                } else {
                    $ol = OrderLogic::create_transaction(order: $order, received_by: 'admin', status: null);
                }


                if (!$ol) {
                    Toastr::warning(translate('messages.faield_to_create_order_transaction'));
                    return back();
                }
            }

            $order->payment_status = 'paid';

            OrderLogic::update_unpaid_order_payment(order_id: $order->id, payment_method: $order->payment_method);

            $order->details->each(function ($item, $key) {
                if ($item->food) {
                    $item->food->increment('order_count');
                }
            });
            $order->customer ?  $order->customer->increment('order_count') : '';
        }
        if ($request->order_status == 'canceled' || $request->order_status == 'delivered') {
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
        }

        if ($request->order_status == 'canceled') {
            Helpers::increment_order_count($order->restaurant);
            $order->cancellation_reason = $request->reason;
            $order->cancellation_note = $request->note;
            $order->canceled_by = 'restaurant';
            if (!isset($order->confirmed) && isset($order->subscription_id)) {
                $order->subscription()->update(['status' => 'canceled']);
                if ($order?->subscription?->log) {
                    $order->subscription->log()->update([
                        'order_status' => $request->status,
                        'canceled' => now(),
                    ]);
                }
            }
            Helpers::decreaseSellCount(order_details: $order->details);
        }
        if ($request->order_status == 'delivered') {
            $order->restaurant->increment('order_count');
            if ($order->delivery_man) {
                $order->delivery_man->increment('order_count');
            }
        }
        $order->order_status = $request->order_status;
        if ($request->order_status == "processing") {
            $order->processing_time = $request->processing_time;
        }
        $order[$request['order_status']] = now();
        $order->save();

        // 哪吒 F-4 补缺(2026-06-21): 商家经 status 通道取消已付直付单时, 补「待退款」留痕。
        // 旧版只写 canceled_by/reason 而漏调 record_direct_pay_refund_pending → 资金留痕缺口。
        // 本分支默认死(canceled_by_restaurant 默认关), 此处为防御性补全(平台不碰钱 L1-1)。
        if ($request->order_status == 'canceled' && $order->payment_method == 'offline_payment') {
            $nz_op = \App\Models\OfflinePayments::where('order_id', $order->id)->whereIn('status', ['pending', 'verified', 'denied'])->first();
            if ($nz_op) {
                \App\Models\OfflinePayments::where('order_id', $order->id)->update(['status' => 'canceled']);
                OrderLogic::record_direct_pay_refund_pending($order, 'restaurant', auth('vendor')->id() ?? auth('vendor_employee')->id(), '商家取消订单，已支付款项需原路退回', true);
                OrderLogic::notify_customer_cancel_refund($order);
            }
        }

        if (!Helpers::send_order_notification($order)) {
            Toastr::warning(translate('messages.push_notification_faild'));
        }
        OrderLogic::update_subscription_log($order);
        Toastr::success(translate('messages.order_status_updated'));
        return back();
    }

    /**
     * 哪吒 B方案 — 商家「无法接单 / 拒单」(商家主动)。
     * 仅【未进入备餐前】(pending / confirmed)可拒; handover / picked_up(已出餐/配送中)绝不可拒。
     * 必填拒单原因。走统一收尾 OrderLogic::finalize_cancellation: canceled_by='restaurant' +
     * 已付直付单生成「待退款」留痕 + 通知顾客原路退(平台不碰钱 L1-1)。
     * 不依赖全局 canceled_by_restaurant 开关(避免开关一开商家可取消任意阶段单的风险)。
     * 对象级鉴权: 只能拒本店单。
     */
    public function reject_order(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => '请填写拒单原因',
        ]);
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->with('details')->first();
        if (!$order) {
            Toastr::error(translate('messages.order_not_found'));
            return back();
        }
        if (!in_array($order->order_status, ['pending', 'confirmed'], true)) {
            Toastr::warning('该订单已进入备餐 / 出餐 / 配送阶段，无法拒单。如确需取消请联系平台客服。');
            return back();
        }
        $refund_pending = false;
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $request, &$refund_pending) {
            $refund_pending = OrderLogic::finalize_cancellation(
                $order,
                'restaurant',
                mb_substr($request->reason, 0, 500),
                $request->note ? mb_substr($request->note, 0, 500) : null,
                auth('vendor')->id() ?? auth('vendor_employee')->id()
            );
        });
        Toastr::success('已拒单并通知顾客。' . ($refund_pending ? '顾客已付款，请到「订单 → 待退款」按原路退还。' : ''));
        return back();
    }

    /**
     * 哪吒 B方案 — 商家处理顾客「取消申请」: action=approve 同意 / reject 拒绝。
     * approve → finalize_cancellation(canceled_by='customer', 申请理由) 收尾 + (已付)待退款留痕。
     * reject  → nezha_cancel_request='rejected' + 存商家理由, 订单回原状态继续履约 + 通知顾客被拒+原因。
     * 仅本店、且当前确有 requested 申请、且仍在 confirmed/processing 阶段可裁决(对象级鉴权)。
     */
    public function cancel_request_decision(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|string|max:500',
        ], [
            'reason.required_if' => '拒绝取消申请时请填写原因',
        ]);
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->with('details')->first();
        if (!$order) {
            Toastr::error(translate('messages.order_not_found'));
            return back();
        }
        if ($order->nezha_cancel_request !== 'requested') {
            Toastr::warning('该订单当前没有待处理的取消申请。');
            return back();
        }
        if (!in_array($order->order_status, ['confirmed', 'processing'], true)) {
            Toastr::warning('订单已进入出餐 / 配送阶段，取消申请已失效。');
            return back();
        }

        if ($request->action === 'approve') {
            $refund_pending = false;
            \Illuminate\Support\Facades\DB::transaction(function () use ($order, &$refund_pending) {
                $refund_pending = OrderLogic::finalize_cancellation(
                    $order,
                    'customer',
                    $order->nezha_cancel_request_reason ?: '顾客申请取消，商家已同意',
                    null,
                    auth('vendor')->id() ?? auth('vendor_employee')->id()
                );
            });
            Toastr::success('已同意取消并通知顾客。' . ($refund_pending ? '顾客已付款，请到「订单 → 待退款」按原路退还。' : ''));
            return back();
        }

        // reject: 订单状态不变, 继续履约
        $order->nezha_cancel_request = 'rejected';
        $order->nezha_cancel_response_note = mb_substr($request->reason, 0, 500);
        $order->nezha_cancel_responded_at = now();
        $order->save();
        try {
            $zh = stripos(($order->customer?->current_language_key ?: 'zh'), 'zh') === 0;
            $title = $zh ? '取消申请未通过' : 'Cancellation declined';
            $msg = $zh
                ? '商家未同意取消订单 #' . $order->id . '，原因：' . mb_substr($request->reason, 0, 150) . '。订单将继续履约，如有疑问请联系商家。'
                : 'The restaurant declined to cancel order #' . $order->id . '. Reason: ' . mb_substr($request->reason, 0, 150) . '. The order will continue.';
            $fcm = $order->is_guest == 0 ? $order?->customer?->cm_firebase_token : null;
            $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
            if ($fcm) { Helpers::send_push_notif_to_device($fcm, $data); }
            if ($order->is_guest == 0) { Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id); }
        } catch (\Throwable $e) { info('cancel_request_decision reject notify failed: ' . $e->getMessage()); }
        Toastr::success('已拒绝取消申请，订单继续履约，已通知顾客。');
        return back();
    }


    public function generate_invoice($id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->with(['payments'])->first();
        $invoiceSettings = DataSetting::invoiceSettings();
        if (Helpers::employee_module_permission_check('regular_order') && Helpers::employee_module_permission_check('subscription_order')) {
            return view('vendor-views.order.invoice', compact('order','invoiceSettings'));
        }
        if ((Helpers::employee_module_permission_check('regular_order') && !$order->subscription_id)) {
            return view('vendor-views.order.invoice', compact('order','invoiceSettings'));
        }
        if ((Helpers::employee_module_permission_check('subscription_order') && $order->subscription_id)) {
            return view('vendor-views.order.invoice', compact('order','invoiceSettings'));
        }
        Toastr::error('Access Denied');
        return back();
    }

    public function add_payment_ref_code(Request $request, $id)
    {
        Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->update([
            'transaction_reference' => $request['transaction_reference']
        ]);

        Toastr::success('Payment reference code is added!');
        return back();
    }


    public function orders_export($status, Request $request)
    {
        try {
            $key = explode(' ', $request['search']);

            $data = 0;
            $restaurant = Helpers::get_restaurant_data();
            if (($restaurant->restaurant_model == 'subscription' &&  $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1)) {
                $data = 1;
            }

            Order::where(['checked' => 0])->where('restaurant_id', Helpers::get_restaurant_id())->update(['checked' => 1]);

            $orders = Order::with(['customer'])
                ->when($status == 'searching_for_deliverymen', function ($query) {
                    return $query->SearchingForDeliveryman();
                })
                ->when($status == 'confirmed', function ($query) {
                    return $query->whereIn('order_status', ['confirmed', 'accepted'])->whereNotNull('confirmed');
                })
                ->when($status == 'pending', function ($query) use ($data) {
                    if (config('order_confirmation_model') == 'restaurant' || $data) {
                        return $query->where('order_status', 'pending');
                    } else {
                        return $query->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                    }
                })
                ->when($status == 'cooking', function ($query) {
                    return $query->where('order_status', 'processing');
                })
                ->when($status == 'food_on_the_way', function ($query) {
                    return $query->where('order_status', 'picked_up');
                })
                ->when($status == 'delivered', function ($query) {
                    return $query->Delivered();
                })
                ->when($status == 'ready_for_delivery', function ($query) {
                    return $query->where('order_status', 'handover');
                })
                ->when($status == 'refund_requested', function ($query) {
                    return $query->Refund_requested();
                })
                ->when($status == 'refunded', function ($query) {
                    return $query->Refunded();
                })
                ->when($status == 'dine_in', function ($query) {
                    return $query->where('order_type', 'dine_in');
                })
                ->when($status == 'customer_nudged', function ($query) {
                    $ids = NezhaCustomerNudge::openOrderIds(
                        \App\CentralLogics\Helpers::get_restaurant_id()
                    );
                    return $query->whereIn('id', $ids ?: [0]);
                })
                ->when($status == 'scheduled', function ($query) use ($data) {
                    return $query->Scheduled()->where(function ($q) use ($data) {
                        if (config('order_confirmation_model') == 'restaurant' || $data) {
                            $q->whereNotIn('order_status', ['failed', 'canceled', 'refund_requested', 'refunded']);
                        } else {
                            $q->whereNotIn('order_status', ['pending', 'failed', 'canceled', 'refund_requested', 'refunded'])->orWhere(function ($query) {
                                $query->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                            });
                        }
                    });
                })
                ->when($status == 'all', function ($query) use ($data) {
                    return $query->where(function ($q1) use ($data) {
                        $q1->whereNotIn('order_status', (config('order_confirmation_model') == 'restaurant' || $data) ? ['failed', 'canceled', 'refund_requested', 'refunded'] : ['pending', 'failed', 'canceled', 'refund_requested', 'refunded'])
                            ->orWhere(function ($q2) {
                                return $q2->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                            })->orWhere(function ($q3) {
                                return $q3->where('order_status', 'pending')->whereNotNull('subscription_id');
                            });
                    });
                })
                ->when(in_array($status, ['pending', 'confirmed']), function ($query) {
                    return $query->OrderScheduledIn(30);
                })
                ->when(isset($key), function ($query) use ($key) {
                    return $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('id', 'like', "%{$value}%")
                                ->orWhere('order_status', 'like', "%{$value}%")
                                ->orWhere('transaction_reference', 'like', "%{$value}%");
                        }
                    });
                })
                ->Notpos()
                ->when($status !== 'customer_nudged', function ($query) {
                    return $query->NotDigitalOrder();
                })
                ->hasSubscriptionToday()
                ->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())
                ->orderBy('schedule_at', 'desc')
                ->get();

            if (in_array($status, ['requested', 'rejected', 'refunded'])) {
                $data = [
                    'orders' => $orders,
                    'type' => $request->order_type ?? translate('messages.all'),
                    'status' => $status,
                    'order_status' => isset($request->orderStatus) ? implode(', ', $request->orderStatus) : null,
                    'search' => $request->search ?? $key[0] ?? null,
                    'from' => $request->from_date ?? null,
                    'to' => $request->to_date ?? null,
                    'zones' => isset($request->zone) ? Helpers::get_zones_name($request->zone) : null,
                    'restaurant' => Helpers::get_restaurant_name(Helpers::get_restaurant_id()),
                ];

                if ($request->type == 'excel') {
                    return Excel::download(new OrderRefundExport($data), 'RefundOrders.xlsx');
                } else if ($request->type == 'csv') {
                    return Excel::download(new OrderRefundExport($data), 'RefundOrders.csv');
                }
            }


            $data = [
                'orders' => $orders,
                'type' => $request->order_type ?? translate('messages.all'),
                'status' => $status,
                'order_status' => isset($request->orderStatus) ? implode(', ', $request->orderStatus) : null,
                'search' => $request->search ?? $key[0] ?? null,
                'from' => $request->from_date ?? null,
                'to' => $request->to_date ?? null,
                'zones' => isset($request->zone) ? Helpers::get_zones_name($request->zone) : null,
                'restaurant' => Helpers::get_restaurant_name(Helpers::get_restaurant_id()),
            ];

            if ($request->type == 'excel') {
                return Excel::download(new OrderExport($data), 'Orders.xlsx');
            } else if ($request->type == 'csv') {
                return Excel::download(new OrderExport($data), 'Orders.csv');
            }
        } catch (\Exception $e) {
            // dd($e);
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);
            return back();
        }
    }

    public function add_order_proof(Request $request, $id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::error(translate('messages.not_found'));
            return back();
        }
        $img_names = $order->order_proof ? json_decode($order->order_proof) : [];
        $images = [];
        $total_file =  (is_array($request->order_proof) ? count($request->order_proof)  : 0) + count($img_names);
        if (!$img_names) {
            $request->validate([
                'order_proof' => 'required|array|max:5',
            ]);
        }

        if ($total_file > 5) {
            Toastr::error(translate('messages.order_proof_must_not_have_more_than_5_item'));
            return back();
        }

        if (!empty($request->file('order_proof'))) {
            foreach ($request->order_proof as $img) {
                $image_name = Helpers::upload('order/', 'png', $img);
                array_push($img_names, ['img' => $image_name, 'storage' => Helpers::getDisk()]);
            }
            $images = $img_names;
        }

        $order->order_proof = json_encode($images);
        $order->save();

        Toastr::success(translate('messages.order_proof_added'));
        return back();
    }


    public function remove_proof_image(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:orders,id',
            'name' => 'required|string',
        ]);

        $order = Order::where(['id' => $request->id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        if (!$order) {
            Toastr::error(translate('messages.not_found'));
            return back();
        }
        $proof = json_decode($order->order_proof, true) ?? [];

        if (count($proof) <= 1) {
            Toastr::warning(translate('You must keep at least one proof image.'));
            return back();
        }

        $target = collect($proof)->firstWhere('img', $request->name);
        if (!$target) {
            Toastr::error(translate('Selected image not found.'));
            return back();
        }

        Helpers::check_and_delete('order/', $target['img']);

        $updatedProof = array_values(array_filter($proof, function ($image) use ($request) {
            return $image['img'] !== $request->name;
        }));

        $order->update([
            'order_proof' => json_encode($updatedProof),
        ]);

        Toastr::success(translate('Order proof image removed successfully.'));
        return back();
    }

    public function download($file_name)
    {
        return Storage::download(base64_decode($file_name));
    }

    public function add_delivery_man($order_id, $delivery_man_id)
    {
        if ($delivery_man_id == 0) {
            return response()->json(['message' => translate('messages.deliveryman_not_found')], 404);
        }
        $order = Order::Notpos()->with(['subscription.schedule_today'])->find($order_id);
        $deliveryman = DeliveryMan::where('id', $delivery_man_id)->available()->active()->first();
        if ($order->delivery_man_id == $delivery_man_id) {
            return response()->json(['message' => translate('messages.order_already_assign_to_this_deliveryman')], 400);
        }
        if ($deliveryman) {
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                // $dm->decrement('assigned_order_count');
                $dm->save();

                $message =Helpers::getPushNotificationMessage(status:'deliveryman_order_unassign',userType: 'deliveryman' , lang:$dm->current_language_key, deliveryManName:$dm->f_name.' '.$dm->l_name);
                if ( $message && isset($dm->fcm_token)) {
                    $data= Helpers::makeDataForPushNotification(title:translate('order_unassigned'), message:$message,orderId: '', type: 'unassign', orderStatus: '');
                    Helpers::send_push_notif_to_device($dm->fcm_token, $data);
                    Helpers::insertDataOnNotificationTable($data , 'delivery_man', $dm->id);
                }
            }
            $order->delivery_man_id = $delivery_man_id;
            $order->order_status = in_array($order->order_status, ['pending', 'confirmed']) ? 'accepted' : $order->order_status;
            $order->accepted = now();
            $order->save();
            OrderLogic::update_subscription_log($order);
            $deliveryman->current_orders = $deliveryman->current_orders + 1;
            $deliveryman->save();
            $deliveryman->increment('assigned_order_count');



            try {

            $fcm_token = ($order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token) ?? null;
            $message = Helpers::getOrderPushNotificationMessage($order, 'accepted', 'user' ,$order->customer ? $order?->customer?->current_language_key : 'en');

            if ($message && isset($fcm_token)) {
                $data= Helpers::makeDataForPushNotification(title:translate('Order_notification'), message:$message,orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
                Helpers::send_push_notif_to_device($fcm_token, $data);
                Helpers::insertDataOnNotificationTable($data , 'user', $order->user_id);
            }

            $message =Helpers::getPushNotificationMessage(status:'deliveryman_order_assign',userType: 'deliveryman' , lang:$deliveryman->current_language_key, deliveryManName:$deliveryman->f_name.' '.$deliveryman->l_name);
            if ( $message && isset($deliveryman->fcm_token)) {
                $data= Helpers::makeDataForPushNotification(title:translate('Order Assigned'), message:$message,orderId: '', type: 'assign', orderStatus: '');
                Helpers::send_push_notif_to_device($deliveryman->fcm_token, $data);
                Helpers::insertDataOnNotificationTable($data , 'delivery_man', $deliveryman->id);
            }

            } catch (\Exception $e) {
                info($e->getMessage());
                Toastr::warning(translate('messages.push_notification_faild'));
            }
            return response()->json([], 200);
        }
        return response()->json(['message' => translate('Deliveryman not available!')], 400);
    }

    public function add_dine_in_table_number(Order $order, Request $request)
    {
        $request->validate([
            'table_number' => 'nullable|max:255|required_without_all:token_number',
            'token_number' => 'nullable|max:255|required_without_all:table_number',
        ], [
            'table_number.required_without_all' => translate('you_must_set_a_table_or_token_number'),
            'token_number.required_without_all' => translate('you_must_set_a_table_or_token_number'),
        ]);

        if ($order?->order_type  == 'dine_in') {
            $order->OrderReference()->update([
                'token_number' => $request->token_number ?? null,
                'table_number' => $request->table_number ?? null
            ]);
        }

        Helpers::dineInOrderTokenUpdatePushNotification($order, $request->table_number, $request->token_number);
        Toastr::success($request->table_number ? translate('table_number_updated_successfully') : translate('token_number_updated_successfully'));
        return back();
    }


    public function updateSchedule(Request $request)
    {

        $order = Order::where('id', $request->order_id)->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())->first();
        if(!$order){ return response()->json(['status'=>'error','message'=>translate('messages.not_found')], 404); }
        $schedule_at = Carbon::parse($request->date);

        $restaurant = Restaurant::selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . (int) $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', Helpers::get_restaurant_id())->first();

        if ($restaurant->open) {
            $order->schedule_at = $schedule_at;
            $order->save();
            $data = ['status' => 'success', 'message' => translate('messages.order_has_been_scheduled')];
        } else {
            $data = ['status' => 'closed',  'message' => translate('restaurant_is_closed_on_this_time')];
        }

        $this->makeEditOrderLogs($order->id, 'edited_schedule_date_&_time', 'vendor');
        return response()->json($data, 200);
    }



    public function remove_from_cart(Request $request)
    {
        $carts = Session::get('order_edit_cart', []);

        foreach ($carts as $key => $cart) {
            if ($cart['cart_id'] == $request->cart_id) {
                if (data_get($cart, 'order_detail_id')) {
                    $edit_logs = Session::get('order_edit_logs', []);
                    $edit_logs[] = 'delete_item';
                    Session::put('order_edit_logs', $edit_logs);
                }
                unset($carts[$key]);
                break;
            }
        }
        $carts = array_values($carts);
        foreach ($carts as $index => &$cart) {
            $cart['cart_id'] = $index + 1;
        }
        Session::put('order_edit_cart', $carts);
        return response()->json([
            'view' => view('vendor-views.order.partials._food_list', compact('carts'))->render(),
        ]);
    }
    public function getSearchedFoods(Request $request)
    {
        $key = explode(' ', $request->search);

        $foods =  Food::where('restaurant_id', Helpers::get_restaurant_id())
            ->when($request->search, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })->active()
            ->orderByRaw("FIELD(name, ?) DESC", [$request->search])
            ->latest()->paginate(10);


        return response()->json([
            'view' => view('vendor-views.order.partials._searched_food_list', compact('foods'))->render(),
        ]);
    }

    public function add_to_cart(Request $request)
    {
        $old_selected_addons = [];
        $old_selected_variations = [];
        $old_selected_without_variation = $request?->old_selected_without_variation ?? 0;

        if ($request?->old_selected_variations) {
            $old_selected_variations = json_decode($request->old_selected_variations, true) ?? [];
        }
        if ($request?->old_selected_addons) {
            $old_selected_addons = json_decode($request->old_selected_addons, true) ?? [];
        }

        if ($request->item_type == 'campaign') {
            $product = ItemCampaign::where('restaurant_id', Helpers::get_restaurant_id())->find($request->id);
        } else {
            $product = Food::where('restaurant_id', Helpers::get_restaurant_id())->with('restaurant')->find($request->id);
        }

        $variations = [];
        $price = 0;
        $addon_price = 0;
        $variation_price = 0;
        $optionIds = [];
        $carts = [];
        $add_ons = [];
        $add_on_qtys = [];
        $add_on_ids = [];

        $product_variations = json_decode($product->variations, true);
        if ($request->variations && count($product_variations)) {
            foreach ($request->variations  as  $value) {

                if ($value['required'] == 'on' &&  isset($value['values']) == false) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select items from') . ' ' . $value['name'],
                    ]);
                }
                if (isset($value['values'])  && $value['min'] != 0 && $value['min'] > count($value['values']['label'])) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select minimum ') . $value['min'] . translate(' For ') . $value['name'] . '.',
                    ]);
                }
                if (isset($value['values']) && $value['max'] != 0 && $value['max'] < count($value['values']['label'])) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select maximum ') . $value['max'] . translate(' For ') . $value['name'] . '.',
                    ]);
                }
            }

            $variation_data = Helpers::get_varient($product_variations, $request->variations);
            $variation_price = $variation_data['price'];
            $variations = $variation_data['variations'];
            $optionIds = $variation_data['optionIds'];
        }

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $add_on_ids[] = $id;
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $add_on_qtys[] = $request['addon-quantity' . $id];
            }
            $add_ons = $request['addon_id'];
        }

        $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product,  quantity: $request->quantity, add_on_qtys: $add_on_qtys, variation_options: $optionIds, add_on_ids: $add_on_ids, old_selected_variations: $old_selected_variations, old_selected_without_variation: $old_selected_without_variation, old_selected_addons: $old_selected_addons);
        if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
            return response()->json([
                'data' => 'stock_out',
                'message' => data_get($addonAndVariationStock, 'out_of_stock'),
                'current_stock' => data_get($addonAndVariationStock, 'current_stock'),
                'id' => data_get($addonAndVariationStock, 'id'),
                'type' => data_get($addonAndVariationStock, 'type'),
            ], 203);
        }

        $addon_data = Helpers::calculate_addon_price(addons: AddOn::where('restaurant_id', Helpers::get_restaurant_id())->whereIn('id', $add_ons)->get(), add_on_qtys: $add_on_qtys, old_selected_addons: $old_selected_addons);

        $addon_price = $addon_data['total_add_on_price'] ?? 0;
        $price = $product->price + $variation_price;
        $price = $price - Helpers::product_discount_calculate(product: $product, price: $price, restaurant: $product->restaurant);
        $price = $price * $request->quantity + $addon_price;

        $carts = Session::get('order_edit_cart', []);

        if (!$request->cart_id) {
            $newVariations = $optionIds;
            sort($newVariations);

            $duplicateExists = false;
            foreach ($carts as $cartItem) {
                if ($cartItem['item_id'] == $product->id) {
                    $existingVariations = $cartItem['variation_options'];
                    sort($existingVariations);

                    if ($existingVariations === $newVariations) {
                        $duplicateExists = true;
                        break;
                    }
                }
            }

            if ($duplicateExists) {
                return response()->json(['data' => 1]);
            }
            $cart['cart_id'] = count($carts) + 1;
            $cart['item_type'] = 'food';
            $cart['name'] = $product->name;
            $cart['image_full_url'] = $product->image_full_url;
            $cart['item_id'] = $product->id;
            $cart['quantity'] = $request->quantity;
            $cart['price'] = $price;
            $cart['maximum_cart_quantity'] =  min($product?->maximum_cart_quantity ?? 9999999999, $product?->stock_type == 'unlimited' ? 999999999 : $product?->item_stock + $old_selected_without_variation);
            $cart['add_on_ids'] = $add_on_ids;
            $cart['add_on_qtys'] = $add_on_qtys;
            $cart['add_ons'] =  json_encode($addon_data['addons']);
            $cart['variation_options'] = $optionIds;
            $cart['addon_price'] = $addon_price;
            $cart['variations'] = $variations;
            $cart['new_item'] = true;
            $carts[] = $cart;

            $edit_logs = Session::get('order_edit_logs', []);
            $edit_logs[] = 'add_new_item';
            Session::put('order_edit_logs', $edit_logs);
            $message = translate('product_has_been_added_in_cart');
        } else {
            $index = array_search($request->cart_id, array_column($carts, 'cart_id'));
            if ($index !== false) {
                if (data_get($carts[$index], 'order_detail_id') && $request->quantity != data_get($carts[$index], 'quantity')) {
                    $edit_logs = Session::get('order_edit_logs', []);
                    $edit_logs[] = 'edited_item_quantity';
                    Session::put('order_edit_logs', $edit_logs);
                }
                $carts[$index]['item_type'] = 'food';
                $carts[$index]['name'] = $product->name;
                $carts[$index]['image_full_url'] = $product->image_full_url;
                $carts[$index]['item_id'] = $product->id;
                $carts[$index]['quantity'] = $request->quantity;
                $carts[$index]['price'] = $price;
                $carts[$index]['maximum_cart_quantity'] =  min($product?->maximum_cart_quantity ?? 9999999999, $product?->stock_type == 'unlimited' ? 999999999 : $product?->item_stock + $old_selected_without_variation);
                $carts[$index]['add_on_ids'] = $add_on_ids;
                $carts[$index]['add_on_qtys'] = $add_on_qtys;
                $carts[$index]['add_ons'] = json_encode($addon_data['addons']);
                $carts[$index]['variation_options'] = $optionIds;
                $carts[$index]['variations'] = $variations;
                $carts[$index]['addon_price'] = $addon_price;
                $message = translate('product_has_been_updated');
            }
        }


        Session::put('order_edit_cart', $carts);
        return response()->json([
            'data' => 0,
            'message' => $message,
            'view' => view('vendor-views.order.partials._food_list', compact('carts'))->render(),
        ]);
    }


    public function edit(Request $request, Order $order)
    {
        Session::forget('order_edit_cart');

        $order = Order::where('restaurant_id', Helpers::get_restaurant_id())
            ->where(['id' => $order->id])->with('details.food', 'restaurant')->first();

        $carts = [];
        foreach ($order->details ?? [] as $key => $detail) {

            if (!$detail->food) {
                return response()->json([
                    'error' => 'food_not_found'
                ]);
            }

            $optionIds = [];
            $add_on_ids = [];
            $add_on_qtys = [];
            $old_selected_variations = [];
            if ($detail->variation != '[]') {
                foreach (json_decode($detail->variation, true) as $value) {
                    foreach (data_get($value, 'values', []) as $item) {
                        if (data_get($item, 'option_id', null) != null) {
                            $optionIds[] = data_get($item, 'option_id', null);
                            $old_selected_variations[data_get($item, 'option_id')] = $detail->quantity;
                        }
                    }
                }
            }

            foreach (json_decode($detail->add_ons, true) as $add_ons) {
                if (data_get($add_ons, 'id', null) != null) {
                    $add_on_ids[] = data_get($add_ons, 'id', null);
                    $add_on_qtys[] = data_get($add_ons, 'quantity', 1);
                }
            }

            $product_variations = json_decode($detail->variation, true);
            $variations = [];
            if (count($product_variations)) {
                $variation_data = Helpers::getVariationPrice($optionIds);
                $variations = $variation_data['variations'];
                if (count($variations) == 0) {
                    $variation_data = Helpers::get_edit_varient($product_variations, json_decode($detail->variation, true));
                    $variations = $variation_data['variations'];
                }
                $price = $detail->food->price + $variation_data['price'];
            } else {
                $price = $detail->food->price;
            }

            $price = $price - Helpers::product_discount_calculate(product: $detail->food, price: $price, restaurant: $order->restaurant);
            $price = $price * $detail->quantity + $detail->total_add_on_price;
            $cart['order_detail_id'] = $detail->id;
            $cart['cart_id'] = $key + 1;
            $cart['item_type'] = 'food';
            $cart['name'] = $detail->food->name;
            $cart['image_full_url'] = $detail->food?->image_full_url;
            $cart['maximum_cart_quantity'] =  min($detail->food?->maximum_cart_quantity ?? 9999999999, $detail->food?->stock_type == 'unlimited' ? 999999999 : $detail->food?->item_stock + $detail->quantity);
            $cart['item_id'] = $detail->food_id;
            $cart['quantity'] = $detail->quantity;
            $cart['price'] = $price;
            $cart['add_on_ids'] = $add_on_ids;
            $cart['add_on_qtys'] = $add_on_qtys;
            $cart['add_ons'] = $detail->add_ons;
            $cart['variation_options'] = $optionIds;
            $cart['variation_options_old'] = $old_selected_variations;
            $cart['addon_price'] = $detail->total_add_on_price;
            $cart['variations'] = json_decode($detail->variation, true);
            $carts[] = $cart;
        }

        Session::put('order_edit_cart', $carts);

        return response()->json([
            'view' => view('vendor-views.order.partials._food_list', compact('carts'))->render(),
        ]);
    }

    public function getSingleFoodPrice(Request $request)
    {

        $variation_price = 0;
        $old_selected_without_variation = 0;
        $old_selected_variations = [];

        if ($request->order_detail_id) {
            $order_detail = OrderDetail::where('id', $request->order_detail_id)->first();

            if ($request?->variation_options_old) {
                $old_selected_variations = $request->variation_options_old ?? [];
            }
            $old_selected_without_variation = $order_detail?->quantity ?? 0;
        }

        $product = Food::where('restaurant_id', Helpers::get_restaurant_id())->with('restaurant')->find($request->food_id);

        if ($product->maximum_cart_quantity && $request->quantity > $product->maximum_cart_quantity) {
            return response()->json([
                'data' => 'maximum_cart_quantity',
                'message' => translate('Maximum cart quantity for this item is ' . $product->maximum_cart_quantity),
            ], 203);
        }
        $addonAndVariationStock = Helpers::addonAndVariationStockCheck(
            product: $product,
            quantity: $request->quantity,
            variation_options: $request?->option_ids,
            old_selected_without_variation: $old_selected_without_variation,
            old_selected_variations: $old_selected_variations
        );
        if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
            return response()->json([
                'data' => 'stock_out',
                'message' => data_get($addonAndVariationStock, 'out_of_stock'),
                'current_stock' => data_get($addonAndVariationStock, 'current_stock'),
                'id' => data_get($addonAndVariationStock, 'id'),
                'type' => data_get($addonAndVariationStock, 'type'),
            ], 203);
        }

        $price = $product->price;
        $carts = Session::get('order_edit_cart', []);
        $index = array_search($request->cart_id, array_column($carts, 'cart_id'));

        if ($index !== false) {
            $product_variations = json_decode($product->variations, true);
            if (count($carts[$index]['variations']) && count($product_variations)) {
                $variation_data = Helpers::get_edit_varient($product_variations, $carts[$index]['variations']);
                $variation_price = $variation_data['price'];
            }
            $price = $price + $variation_price;
            $price = $price - Helpers::product_discount_calculate(product: $product, price: $price, restaurant: $product->restaurant);
            $price =  $price * $request->quantity + $carts[$index]['addon_price'];

            if (data_get($carts[$index], 'order_detail_id') && $request->quantity != data_get($carts[$index], 'quantity')) {
                $edit_logs = Session::get('order_edit_logs', []);
                $edit_logs[] = 'edited_item_quantity';
                Session::put('order_edit_logs', $edit_logs);
            }
            $carts[$index]['quantity'] = $request->quantity;
            $carts[$index]['price'] =  $price;
        }

        Session::put('order_edit_cart', $carts);
        return response()->json([
            'data' => 0,
            'message' => translate('messages.quantity_updated_successfully'),
            'view' => view('vendor-views.order.partials._food_list', compact('carts'))->render(),
        ]);
    }


    public function update(Request $request, Order $order)
    {
        $carts = Session::get('order_edit_cart', []);
        $order_edit_logs = Session::get('order_edit_logs', []);

        $order = $this->makeEditOrderDetails(order: $order, carts: $carts, restaurant: $order->restaurant, editedBy: 'vendor', editLogs: $order_edit_logs);

        if (data_get($order, 'status_code') !== 200) {
            Toastr::error(data_get($order, 'message'));
            return back();
        }

        Toastr::success(translate('messages.order_updated_successfully'));
        return back();
    }

    public function quick_view(Request $request)
    {
        $product = Food::where('restaurant_id', Helpers::get_restaurant_id())->select(['id', 'name', 'image', 'veg', 'stock_type', 'item_stock', 'restaurant_id', 'discount', 'discount_type', 'description', 'maximum_cart_quantity', 'variations', 'add_ons', 'price'])->with('restaurant')->findOrFail($request->product_id);
        $item_type = 'food';
        $order_id = $request->order_id;

        return response()->json([
            'success' => 1,
            'view' => view('vendor-views.order.partials._quick-view', compact('product', 'order_id', 'item_type'))->render(),
        ]);
    }

    public function quick_view_cart_item(Request $request)
    {
        $carts = Session::get('order_edit_cart', []);
        $cart_item =  collect($carts)->firstWhere('cart_id', $request->cart_id);
        $order_id = $request->order_id;
        $item_id = $request->key;
        $product = Food::where('restaurant_id', Helpers::get_restaurant_id())->select(['id', 'name', 'image', 'veg', 'stock_type', 'item_stock', 'restaurant_id', 'discount', 'discount_type', 'description', 'maximum_cart_quantity', 'variations', 'add_ons', 'price'])->with('restaurant')->findOrFail($item_id);
        $item_type = 'food';

        $item_count = count($carts);

        return response()->json([
            'success' => 1,
            'view' => view('vendor-views.order.partials._quick-view-cart-item', compact('order_id', 'product', 'cart_item', 'item_id', 'item_type', 'item_count'))->render(),
        ]);
    }

    public function update_shipping(Request $request, Order $order)
    {

        $request->validate([
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
        ]);
        if ($request->latitude && $request->longitude) {
            $zone = Zone::where('id', $order->restaurant->zone_id)->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))->first();
            if (!$zone) {
                Toastr::error(translate('messages.out_of_coverage'));
                return back();
            }
        }
        // 哪吒[任务3]: 编辑配送地址弹窗无 delivery_note 输入, 重建快照会擦掉地址常备备注 → 先从原快照保留
        $nz_prev_addr = $order->delivery_address ? json_decode($order->delivery_address, true) : [];
        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'floor' => $request->floor,
            'house' => $request->house,
            'road' => $request->road,
            'delivery_note' => is_array($nz_prev_addr) ? ($nz_prev_addr['delivery_note'] ?? null) : null,
        ];

        $order->delivery_address = json_encode($address);
        $order->save();
        $this->makeEditOrderLogs($order->id, 'edited_delivery_info', 'vendor');

        Toastr::success(translate('messages.delivery_address_updated'));
        return back();
    }
}
