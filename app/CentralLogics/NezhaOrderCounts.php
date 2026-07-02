<?php

namespace App\CentralLogics;

use App\Models\Order;
use App\Models\Restaurant;
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

        // 待确认收款(有凭证待核) —— 不加 NotDigitalOrder; **无 checked 过滤**(修 0-vs-1)
        $out['offline_pending'] = $base()
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->count();

        // 待退款 —— 不加 NotDigitalOrder
        $out['refund_pending'] = $base()
            ->whereIn('id', function ($sub) {
                $sub->select('order_id')->from('nezha_refund_records')->where('status', 'pending_merchant_refund');
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

        return $out;
    }
}
