<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 B方案 — 商家「长期不确认订单 → 自动暂停接单(auto-offline)」的共用工具。
 *
 * 目的: 保护顾客不被继续喂给「失联/不响应」的商家。当某商家在滚动窗口内多次因超时
 *   被系统自动取消订单(商家责任)、且窗口内一单都没成功处理(行为判定=不在场)时,
 *   自动置「接单挂起」标记停止接收新单; 商家回来一键自助恢复 / 运营后台恢复。
 *
 * 🔴 L1 红线: 本类零资金操作。只读写 restaurants 的「与钱无关接单挂起标记」+ 查询订单/超时账本。
 *   绝不取消存量单(单笔超时取消由 OrderTimeoutSweep 独立负责)、不碰保证金、不代退、不打钱。
 *
 * 定级 L2(真实影响开关·会暂停商家经营)。总闸 nezha_autooffline_status(默认 0 关) dormant, 关时全程 no-op。
 * 与退款逾期挂起(nezha_order_suspended)【互相独立】: 各用各的列, 接单闸把两个信号 OR 起来;
 *   恢复各管各的(本类只清 nezha_auto_offline, 不误伤退款逾期挂起, 反之亦然)。
 */
class NezhaAutoOffline
{
    /** 总闸: 功能是否启用(默认关)。关时 sweep 全程 no-op。 */
    public static function enabled(): bool
    {
        return (int) (BusinessSetting::where('key', 'nezha_autooffline_status')->value('value') ?? 0) === 1;
    }

    /** 触发阈值 N: 滚动窗口内商家责任超时取消达此单数即触发(默认 3, 后台可调)。L2 参数。 */
    public static function strikeCount(): int
    {
        $n = (int) (BusinessSetting::where('key', 'nezha_autooffline_strike_count')->value('value') ?? 0);
        return $n > 0 ? $n : 3;
    }

    /** 滚动窗口 H(小时, 默认 2, 后台可调)。L2 参数。 */
    public static function windowHours(): int
    {
        $h = (int) (BusinessSetting::where('key', 'nezha_autooffline_window_hours')->value('value') ?? 0);
        return $h > 0 ? $h : 2;
    }

    /** 接单闸用: 该商家是否因「长期不确认」被自动下线(与钱无关)。 */
    public static function is_offline($restaurant): bool
    {
        if (!$restaurant) {
            return false;
        }
        $v = is_array($restaurant) ? ($restaurant['nezha_auto_offline'] ?? 0) : ($restaurant->nezha_auto_offline ?? 0);
        return (int) $v === 1;
    }

    /** 自动下线某商家(非资金)。仅置接单挂起标记。save 失败抛出 -> 由调用方处理。 */
    public static function offline(int $restaurantId, string $reason): bool
    {
        $r = Restaurant::find($restaurantId);
        if (!$r) {
            return false;
        }
        $r->nezha_auto_offline        = 1;
        $r->nezha_auto_offline_reason = mb_substr($reason, 0, 255);
        $r->nezha_auto_offline_at     = now();
        $r->save();
        Log::warning('NEZHA_AUTOOFFLINE offline restaurant#' . $restaurantId . ' :: ' . $reason);
        return true;
    }

    /**
     * 恢复某商家接单。$by ∈ {self(商家自助一键), ops(运营后台)}。
     * 🔴 无冷却自动恢复(业主 2026-07-11 拍板): 只能商家自助/运营显式恢复, sweep 绝不自动解挂
     * (自动重开会把还没回来的店又推给顾客, 违背保护顾客初衷)。
     * 只清本功能自己的列, 不动退款逾期挂起(nezha_order_suspended), 故不误伤别的挂起来源。
     */
    public static function recover(int $restaurantId, string $by = 'ops'): bool
    {
        $r = Restaurant::find($restaurantId);
        if (!$r) {
            return false;
        }
        if (!(int) ($r->nezha_auto_offline ?? 0)) {
            return false; // 本就没被自动下线
        }
        $r->nezha_auto_offline        = 0;
        $r->nezha_auto_offline_reason = null;
        $r->nezha_auto_offline_at     = null;
        $r->save();
        try {
            DB::table('nezha_auto_offline_events')->insert([
                'restaurant_id' => $restaurantId,
                'action'        => $by === 'self' ? 'self_recover' : 'ops_recover',
                'detail'        => $by === 'self' ? '商家作业台一键恢复接单' : '运营后台恢复接单',
                'fired_at'      => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE recover event log failed restaurant#' . $restaurantId . ': ' . $e->getMessage());
        }
        Log::info('NEZHA_AUTOOFFLINE recover restaurant#' . $restaurantId . ' by=' . $by);
        return true;
    }

    /**
     * 滚动窗口内「商家责任超时取消(cancel_paid_refund)」达 N 单的候选店。
     * 账本 nezha_order_timeout_events 无 restaurant_id → JOIN orders 取店; 排除预约单(scheduled=1)。
     * 只数 cancel_paid_refund(排除 cancel_unpaid=顾客没付, 非商家责任)。返回 [{restaurant_id, strikes}] 集合。
     */
    public static function strikingRestaurants(string $cutoff, int $n)
    {
        return DB::table('nezha_order_timeout_events as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->where('e.action', 'cancel_paid_refund')
            ->where('e.fired_at', '>=', $cutoff)
            ->where('o.scheduled', 0)
            ->whereNotNull('o.restaurant_id')
            ->groupBy('o.restaurant_id')
            ->havingRaw('COUNT(DISTINCT o.id) >= ?', [$n])
            ->select('o.restaurant_id', DB::raw('COUNT(DISTINCT o.id) as strikes'))
            ->get();
    }

    /**
     * 行为在场判定: 窗口内该店有没有成功推进过任何一单(accepted/processing/handover/picked_up/delivered 任一时间戳落在窗口内)。
     * 超时被取消的单只会盖 canceled, 不会盖这些前进态, 故不会被误算成「已处理」。true=在场(不下线)。
     */
    public static function handledInWindow(int $restaurantId, string $cutoff): bool
    {
        return DB::table('orders')
            ->where('restaurant_id', $restaurantId)
            ->where(function ($q) use ($cutoff) {
                $q->where('accepted', '>=', $cutoff)
                  ->orWhere('processing', '>=', $cutoff)
                  ->orWhere('handover', '>=', $cutoff)
                  ->orWhere('picked_up', '>=', $cutoff)
                  ->orWhere('delivered', '>=', $cutoff);
            })
            ->exists();
    }
}
