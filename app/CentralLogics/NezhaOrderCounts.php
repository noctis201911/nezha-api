<?php

namespace App\CentralLogics;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\NezhaRefundRecord;
use Illuminate\Support\Facades\Cache;

/**
 * 哪吒 P1b-A: 商家订单计数「单一真相源」。
 *
 * 收口三处历史各自内联的 count(侧栏徽标 _sidebar.blade / 看板待办条 nezha_todo_counts / 订单页组tab list.blade),
 * 杜绝未来再分叉。口径逐字对齐原 list.blade `$nzApplyStatusCount` + `_sidebar` 内联 count(二者本就同口径),
 * 只做一处「有意修正」: 待办条「待确认收款」原误加 `checked=0`(=未读) → 与侧栏/列表(全部待核)不一致,
 * 实证 bug 看板=0 而侧栏/列表=1; 此处统一为「有凭证待核」全量口径(offline_pending, 无 checked 过滤)。
 * checked=0 的「新单提醒/响铃」逻辑仍留在 nezha_todo_counts(target_ids), 与「持续待办徽标」解耦。
 *
 * 纯只读计数 · L3 呈现层 · 不碰 confirm/payment/退款/L1 机制。
 * 缓存: redis 短 TTL(plan §5: 15~30s) + 请求内 memo; 新单/状态变更由 forget() 主动失效。
 */
class NezhaOrderCounts
{
    /** 请求内 memo, 键 = restaurant_id */
    protected static array $memo = [];

    /** 跨请求缓存 TTL 秒 */
    protected const TTL = 20;

    protected static function cacheKey($rid): string
    {
        return 'nezha_vendor_order_group_counts_' . $rid;
    }

    /** 失效缓存(新单命中/订单状态变更时调用)。 */
    public static function forget($rid): void
    {
        $rid = (int) $rid;
        unset(self::$memo[$rid]);
        Cache::forget(self::cacheKey($rid));
    }

    /**
     * 按 order_id 反查所属店并失效其计数缓存。
     * 供 OfflinePayments 写入(顾客传/改凭证)时用——这是「待确认收款」唯一由 offline_payments
     * 驱动、Order 表不写的增量事件, 若只挂 Order observer 会漏(裁决: 消除而非接受 ≤20s 边缘)。
     * guest 单同样有 restaurant_id, 反查安全。
     */
    public static function forgetByOrderId($orderId): void
    {
        $rid = \App\Models\Order::where('id', (int) $orderId)->value('restaurant_id');
        if ($rid) {
            self::forget($rid);
        }
    }

    /** 三处消费方统一入口(memo + redis 短缓存)。 */
    public static function forRestaurant($rid): array
    {
        $rid = (int) $rid;
        if (isset(self::$memo[$rid])) {
            return self::$memo[$rid];
        }
        $counts = Cache::remember(self::cacheKey($rid), self::TTL, function () use ($rid) {
            return self::compute($rid);
        });

        return self::$memo[$rid] = $counts;
    }

    /** 绕过 memo + 缓存直算(供 parity 自测 / 对账用)。 */
    public static function raw($rid): array
    {
        return self::compute((int) $rid);
    }

    /** 复刻 nezha_todo_counts / list 的自配送判定。 */
    protected static function selfDeliveryFlag($rid): int
    {
        $r = Restaurant::with('restaurant_sub')->find($rid);
        if (!$r) {
            return 0;
        }
        if (($r->restaurant_model == 'subscription' && optional($r->restaurant_sub)->self_delivery == 1)
            || ($r->restaurant_model == 'commission' && $r->self_delivery_system == 1)) {
            return 1;
        }

        return 0;
    }

    protected static function compute($rid): array
    {
        $selfDelivery = self::selfDeliveryFlag($rid);
        $restaurantConfirm = (config('order_confirmation_model') == 'restaurant' || $selfDelivery);

        // 公共基座: 本店 + 非POS + 今日订阅口径(与 list `$nzCountBase` / sidebar 完全一致)
        $base = function () use ($rid) {
            return Order::query()->where('restaurant_id', $rid)->Notpos()->HasSubscriptionToday();
        };

        $out = [];

        // 全部 —— 不加 NotDigitalOrder(与 list 'all' / sidebar 全部 同口径)
        $out['all'] = $base()->count();

        // 客户催促 —— 逐字用 list 口径(base + openOrderIds), 不加 NotDigitalOrder
        $out['customer_nudged'] = $base()->whereIn('id', NezhaCustomerNudge::openOrderIds((int) $rid) ?: [0])->count();

        // 超时 —— 与 list 'timeout' / 需动作徽标同源(NezhaOrderTimeout::alertOrderIds), 不加 NotDigitalOrder
        // (P1b-C: 需动作组的「超时」chip 读此 key; 缺它会显 0 而并集里有超时单, 破坏 chip 数字诚实)
        $out['timeout'] = $base()->whereIn('id', NezhaOrderTimeout::alertOrderIds((int) $rid) ?: [0])->count();

        // 待确认收款(有凭证待核) —— 不加 NotDigitalOrder; **无 checked 过滤**(修 0-vs-1)
        $out['offline_pending'] = $base()
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->count();

        // 待退款 —— 不加 NotDigitalOrder
        $out['refund_pending'] = $base()
            ->whereIn('id', function ($sub) {
                $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_NEEDS_ACTION);
            })->count();

        // —— 以下均加 NotDigitalOrder(与 list 后置过滤一致)——
        // 待处理(P1a 已从 tab 移除, 计数保留供直链/兜底)
        $pending = $base()->where('order_status', 'pending');
        if (!$restaurantConfirm) {
            $pending->whereIn('order_type', ['take_away', 'dine_in']);
        }
        $out['pending'] = $pending->OrderScheduledIn(30)->NotDigitalOrder()->count();

        $out['confirmed'] = $base()->whereIn('order_status', ['confirmed', 'accepted'])
            ->whereNotNull('confirmed')->OrderScheduledIn(30)->NotDigitalOrder()->count();

        $out['cooking'] = $base()->where('order_status', 'processing')->NotDigitalOrder()->count();
        $out['ready_for_delivery'] = $base()->where('order_status', 'handover')->NotDigitalOrder()->count();
        $out['food_on_the_way'] = $base()->where('order_status', 'picked_up')->NotDigitalOrder()->count();
        $out['delivered'] = $base()->Delivered()->NotDigitalOrder()->count();
        $out['refunded'] = $base()->Refunded()->NotDigitalOrder()->count();
        $out['refund_requested'] = $base()->Refund_requested()->NotDigitalOrder()->count();
        $out['payment_failed'] = $base()->where('order_status', 'failed')->NotDigitalOrder()->count();
        $out['canceled'] = $base()->where('order_status', 'canceled')->NotDigitalOrder()->count();

        // 已完结组的「已取消」chip = 已取消且无未结退款(带未结退款的取消单归售后·待退款, 不重复计已完结, 与 grp_done 同口径)
        $out['done_canceled'] = $base()->where('order_status', 'canceled')
            ->whereNotIn('id', function ($sub) {
                $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED);
            })->NotDigitalOrder()->count();

        $out['scheduled'] = $base()->Scheduled()->where(function ($q) use ($restaurantConfirm) {
            if ($restaurantConfirm) {
                $q->whereNotIn('order_status', ['failed', 'canceled', 'refund_requested', 'refunded']);
            } else {
                $q->whereNotIn('order_status', ['pending', 'failed', 'canceled', 'refund_requested', 'refunded'])
                    ->orWhere(function ($query) {
                        $query->where('order_status', 'pending')->whereIn('order_type', ['take_away', 'dine_in']);
                    });
            }
        })->NotDigitalOrder()->count();

        // —— P1b-C: 4 组 rollup 计数(订单页 4+1 组 tab)。组过滤口径 = applyGroupFilter 单一真相源,
        //    与控制器 list($status) 的 grp_* 分支同源, 保证「组 tab 计数 = 列表 total」数字同源。
        //    需动作(action)为横切并集按单去重; ongoing/aftersale/done 为分区(一单只住一组)。
        foreach (['action', 'ongoing', 'aftersale', 'done'] as $g) {
            $q = $base();
            self::applyGroupFilter($q, $g, $rid);
            $out['grp_' . $g] = $q->count();
        }

        return $out;
    }

    /**
     * P1b-C: 「需动作」组成员 order-id 集合(按单去重的并集)。
     * = 待确认收款(有凭证) ∪ 待退款(未结记录) ∪ 客户催促 ∪ 超时。
     * 供「需动作」组 tab 计数 + 控制器 grp_action 列表共用同一 id 集 → 数字同源。
     * 前两者用 base(本店+非POS+今日订阅)取 id; 催促/超时走各自 helper(本店参数);
     * 并集交给消费方的 base 链再过滤一次(消除非今日订阅噪音), 计数与列表两侧一致。
     */
    public static function actionOrderIds($rid): array
    {
        $rid = (int) $rid;
        $base = function () use ($rid) {
            return Order::query()->where('restaurant_id', $rid)->Notpos()->HasSubscriptionToday();
        };
        $offline = $base()
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->pluck('id')->all();
        $refund = $base()
            ->whereIn('id', function ($sub) {
                $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_NEEDS_ACTION);
            })->pluck('id')->all();
        // 退款申请中(顾客已申请退款/取消, 等商家响应) —— Fable 钉板: 典型需动作, 并入告警面
        $refundRequested = $base()->where('order_status', 'refund_requested')->pluck('id')->all();
        $nudge = NezhaCustomerNudge::openOrderIds($rid) ?: [];
        $timeout = NezhaOrderTimeout::alertOrderIds($rid) ?: [];

        return array_values(array_unique(array_map('intval', array_merge($offline, $refund, $refundRequested, $nudge, $timeout))));
    }

    /**
     * P1b-C: 4 组过滤器单一真相源(compute 组计数 + 控制器 list($status) grp_* 分支共用同一 WHERE)。
     * 只加 WHERE, 不加 NotDigitalOrder(4 组均在控制器 NotDigitalOrder 豁免名单内; 待确认收款=pending+offline
     * 会被 NotDigitalOrder 隐藏, 故这里精确指定分支而不依赖该 scope):
     *  - action:    需动作 = actionOrderIds 并集(横切告警视图, 可与进行中重叠, 徽标唯一来源)。
     *  - ongoing:   进行中 = 待确认收款 ∪ 已接单(confirmed+accepted) ∪ 备餐 ∪ 待配送 ∪ 配送中 ∪ 已预订(未关闭)。
     *  - aftersale: 售后   = 退款申请中 ∪ 已退款 ∪ 待退款(未结记录)。
     *  - done:      已完结 = 已送达/支付失败/已取消, 且无未结退款记录(带未结退款者只住售后)。
     * 分区三组(ongoing/aftersale/done)一单只住一组; action 为横切叠加。纯 L3 呈现, 不碰记录语义/L1。
     */
    public static function applyGroupFilter($query, string $group, $rid): void
    {
        switch ($group) {
            case 'action':
                $query->whereIn('id', self::actionOrderIds($rid) ?: [0]);
                break;

            case 'ongoing':
                $query->where(function ($w) {
                    $w->where(function ($o) { // 待确认收款(pending+offline+有凭证待核)
                        $o->where('order_status', 'pending')->where('payment_method', 'offline_payment')
                          ->whereHas('offline_payments', function ($p) { $p->where('status', 'pending'); });
                    })
                    ->orWhere(function ($o) { // 已接单(confirmed+accepted)
                        $o->whereIn('order_status', ['confirmed', 'accepted'])->whereNotNull('confirmed');
                    })
                    ->orWhereIn('order_status', ['processing', 'handover', 'picked_up']) // 备餐/待配送/配送中
                    ->orWhere(function ($o) { // 已预订(未关闭, 含待确认的预约)
                        $o->Scheduled()->whereNotIn('order_status', ['failed', 'canceled', 'refund_requested', 'refunded', 'refund_request_canceled', 'delivered']);
                    });
                })
                // 挂着未结退款的在途单归「售后」不重复进「进行中」(结构性保证分区两两不相交)
                ->whereNotIn('id', function ($sub) {
                    $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED);
                });
                break;

            case 'aftersale':
                $query->where(function ($w) {
                    $w->whereIn('order_status', ['refund_requested', 'refunded'])
                      ->orWhereIn('id', function ($sub) {
                          $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED);
                      });
                });
                break;

            case 'done':
                $query->whereIn('order_status', ['delivered', 'failed', 'canceled'])
                      ->whereNotIn('id', function ($sub) {
                          $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED);
                      });
                break;
        }
    }
}
