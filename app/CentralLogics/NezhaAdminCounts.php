<?php

namespace App\CentralLogics;

use App\Models\Order;
use App\Scopes\ZoneScope;
use Illuminate\Support\Facades\Cache;

/**
 * 哪吒超管M2-D1: 超管后台「订单计数单一真相源」(平台全量)。
 *
 * 收口历史三处各自内联的订单 count(侧栏徽标 _sidebar.blade / 顶栏角标 _header.blade / 订单页组tab),
 * 杜绝分叉。此前侧栏走两个 Cache::rememberForever(order_stats_summary / order_scheduled_stats) 快照——
 * 其口径本与订单列表页(all)逐字相同(Notpos + HasSubscriptionToday), 但 rememberForever + HasSubscriptionToday
 * 是时间依赖谓词 + 失效钩子只挂 Order created/updated(漏时间流逝/批量裸写/删除/订阅表变更) → 快照永久漂移
 * = 现网侧栏「全部28」vs 列表「31」的真根因(非口径差异, 非脏数据)。本类改用 Cache::remember(60s) 短缓存
 * + 请求内 memo, 陈旧封顶 60s, 三处消费方读同一值 = 按构造一致。
 *
 * 全平台数(单操作员平台): 显式 withoutGlobalScope(ZoneScope) 保证不因谁先触发缓存而被 zone 污染;
 * 员工角色可见性照旧由渲染层 gate 裁剪, 本类不做权限分支(见 HANDOFF_admin_M2 §A.5)。
 * 纯只读计数 · L3 呈现层 · 不碰订单/退款/L1 机制。
 */
class NezhaAdminCounts
{
    /** 请求内 memo */
    protected static ?array $memo = null;

    /** 跨请求缓存 TTL 秒 */
    protected const TTL = 60;

    protected const CACHE_KEY = 'nezha_admin_order_counts';

    /** 失效缓存(Order created/updated 时调用; 60s TTL 为兜底)。 */
    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** 统一入口(memo + 60s 缓存)。 */
    public static function all(): array
    {
        if (is_array(self::$memo)) {
            return self::$memo;
        }

        return self::$memo = Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return self::compute();
        });
    }

    /** 取单键(缺省 0)。 */
    public static function get(string $key, int $default = 0): int
    {
        $all = self::all();

        return isset($all[$key]) ? (int) $all[$key] : $default;
    }

    /** 绕过 memo + 缓存直算(供对账自测用)。 */
    public static function raw(): array
    {
        return self::compute();
    }

    /**
     * 全部队列计数一次算清。口径逐字对齐原 _sidebar.blade 两个 selectRaw
     * (order_stats_summary / order_scheduled_stats) + offline 内联 count, 与订单列表页(all)同口径,
     * 仅去掉 rememberForever 的永久漂移。
     */
    protected static function compute(): array
    {
        // 基座A: 平台全量 —— 非POS + 今日订阅口径(与列表 all / 原侧栏 $order 完全一致)
        $a = Order::withoutGlobalScope(ZoneScope::class)->Notpos()->HasSubscriptionToday()->selectRaw('
            COUNT(*) as total,
            COUNT(CASE WHEN order_type = "dine_in" THEN 1 END) as dine_in,
            COUNT(CASE WHEN order_status = "delivered" THEN 1 END) as delivered,
            COUNT(CASE WHEN order_status = "canceled" THEN 1 END) as canceled,
            COUNT(CASE WHEN order_status = "failed" THEN 1 END) as failed,
            COUNT(CASE WHEN order_status = "refunded" THEN 1 END) as refunded,
            COUNT(CASE WHEN order_status = "refund_requested" THEN 1 END) as refund_requested,
            COUNT(CASE WHEN order_status IN ("confirmed", "processing","handover") THEN 1 END) as processing,
            COUNT(CASE WHEN created_at <> schedule_at AND scheduled = 1 THEN 1 END) as scheduled
        ')->first();

        // 基座B: 基座A + OrderScheduledIn(30)(到点/已过点排程口径, 与原 $order_sch 一致)
        $b = Order::withoutGlobalScope(ZoneScope::class)->Notpos()->HasSubscriptionToday()->OrderScheduledIn(30)->selectRaw('
            COUNT(CASE WHEN order_status = "pending" THEN 1 END) as pending,
            COUNT(CASE WHEN order_status = "picked_up" THEN 1 END) as picked_up,
            COUNT(CASE WHEN order_status IN ("accepted", "confirmed","processing","handover","picked_up") THEN 1 END) as ongoing,
            COUNT(CASE WHEN delivery_man_id IS NULL  AND order_type = "delivery" AND order_status NOT IN ("delivered", "failed","canceled","refund_requested","refund_request_canceled","refunded") THEN 1 END) as searching_dm,
            COUNT(CASE WHEN order_status = "accepted" THEN 1 END) as accepted
        ')->first();

        // offline 待核验(口径同原侧栏第97行内联: has offline_payments + Notpos, 无今日订阅过滤)
        $offline = Order::withoutGlobalScope(ZoneScope::class)->has('offline_payments')->Notpos()->count();

        return [
            'total'            => (int) ($a->total ?? 0),
            'dine_in'          => (int) ($a->dine_in ?? 0),
            'delivered'        => (int) ($a->delivered ?? 0),
            'canceled'         => (int) ($a->canceled ?? 0),
            'failed'           => (int) ($a->failed ?? 0),
            'refunded'         => (int) ($a->refunded ?? 0),
            'refund_requested' => (int) ($a->refund_requested ?? 0),
            'processing'       => (int) ($a->processing ?? 0),
            'scheduled'        => (int) ($a->scheduled ?? 0),
            'pending'          => (int) ($b->pending ?? 0),
            'picked_up'        => (int) ($b->picked_up ?? 0),
            'ongoing'          => (int) ($b->ongoing ?? 0),
            'searching_dm'     => (int) ($b->searching_dm ?? 0),
            'accepted'         => (int) ($b->accepted ?? 0),
            'offline_payments' => (int) $offline,
        ];
    }
}