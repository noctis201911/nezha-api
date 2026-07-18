<?php

namespace App\CentralLogics;

use App\Models\Order;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 哪吒 · 新单「反复提醒商家接单」的待催订单集合(单一口径)。
 *
 * 口径**逐字复刻** vendor 看板 toast/响铃 的三桶(DashboardController::nezha_todo_counts 的
 * offline_ids / pending_ids / confirmed_ids, 均带 checked=0), 而非 NezhaOrderCounts::compute 的
 * 「持续待办徽标」(无 checked 过滤) —— 二者被 NezhaOrderCounts 头注**有意解耦**:
 * checked=0 属「响铃 / 新单提醒」域, 无 checked 属「持续待办徽标」域。
 * 反复催单(A 网页响铃 / B 手机 TG)同属「响铃」域, 故复用带 checked=0 的口径。
 *
 * 有意与 DashboardController 内联桶「重复」而非抽取共享: 冻结面降险, 不动 live toast 热路径;
 * 由 NezhaNewOrderNagSweepTest 的 parity 断言守住「本类桶计数 == toast 桶计数」防漂移
 * (codebase 既有 NezhaOrderCounts::raw() parity 惯例)。纯只读聚合 · L3 · 不碰状态/退款/L1。
 *
 * 类别 → 桶:
 *   accept(待接单)  = pending_ids ∪ confirmed_ids
 *   payment(待收款) = offline_ids
 */
class NezhaNewOrderNag
{
    /** 复刻 nezha_todo_counts / NezhaOrderCounts 的自配送判定。 */
    protected static function selfDeliveryFlag($rid): int
    {
        $r = Restaurant::with('restaurant_sub')->find($rid);
        if (! $r) {
            return 0;
        }
        if (($r->restaurant_model == 'subscription' && optional($r->restaurant_sub)->self_delivery == 1)
            || ($r->restaurant_model == 'commission' && $r->self_delivery_system == 1)) {
            return 1;
        }

        return 0;
    }

    /** 待收款(离线已传凭证待核) —— 逐字复刻 nezha_todo_counts $offline_ids。 */
    protected static function paymentQuery($rid)
    {
        return Order::where(['order_status' => 'pending', 'payment_method' => 'offline_payment', 'restaurant_id' => $rid])
            ->where('checked', 0)
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->Notpos()->HasSubscriptionToday();
    }

    /** 待处理(pending) —— 逐字复刻 nezha_todo_counts $pending_ids。 */
    protected static function pendingQuery($rid)
    {
        $q = Order::where(['order_status' => 'pending', 'restaurant_id' => $rid])
            ->where('checked', 0)
            ->Notpos()->NotDigitalOrder()->HasSubscriptionToday()->OrderScheduledIn(30);
        if (! (config('order_confirmation_model') == 'restaurant' || self::selfDeliveryFlag($rid))) {
            $q->whereIn('order_type', ['take_away', 'dine_in']);
        }

        return $q;
    }

    /** 已确认待接(confirmed) —— 逐字复刻 nezha_todo_counts $confirmed_ids。 */
    protected static function confirmedQuery($rid)
    {
        return Order::whereIn('order_status', ['confirmed'])
            ->where(['restaurant_id' => $rid])->where('checked', 0)->whereNotNull('confirmed')
            ->NotDigitalOrder()->Notpos()->HasSubscriptionToday()->OrderScheduledIn(30);
    }

    /**
     * 按店 + 已勾选类别, 返回待催订单(按类别分组, 便于命令用对应 phase 时钟算年龄)。
     *
     * @return array{accept: Collection, payment: Collection}
     */
    public static function bucketsForRestaurant($rid, bool $scopeAccept, bool $scopePayment): array
    {
        $rid     = (int) $rid;
        $accept  = collect();
        $payment = collect();

        if ($scopeAccept) {
            $accept = self::pendingQuery($rid)->get()
                ->concat(self::confirmedQuery($rid)->get())
                ->unique('id')->values();
        }
        if ($scopePayment) {
            $payment = self::paymentQuery($rid)->get()->values();
        }

        return ['accept' => $accept, 'payment' => $payment];
    }

    /**
     * 纯判定(命令与单测单一真相源): 给定阶段时钟起点 / 上次响铃时间戳 / 间隔 / 上限 / 当前时刻,
     * 这一单此刻该不该再催一次。$startClock=null(预约窗口未临近/无可靠时钟)→ 不催。
     */
    public static function shouldNagNow(?Carbon $startClock, ?int $lastRingTs, int $intervalSec, int $maxSec, Carbon $now): bool
    {
        if (! $startClock) {
            return false; // 窗口未临近 / 无时钟 → 不催
        }
        if ($startClock->diffInSeconds($now) >= $maxSec) {
            return false; // 超最长反复时长 → 停
        }
        if ($lastRingTs && ($now->timestamp - $lastRingTs) < $intervalSec) {
            return false; // 未到间隔
        }

        return true;
    }

    /** parity 自测用: 各桶计数(不取模型)。 */
    public static function counts($rid): array
    {
        $rid = (int) $rid;

        return [
            'payment'   => self::paymentQuery($rid)->count(),
            'pending'   => self::pendingQuery($rid)->count(),
            'confirmed' => self::confirmedQuery($rid)->count(),
        ];
    }
}
