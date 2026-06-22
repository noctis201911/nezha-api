<?php

namespace App\CentralLogics;

use App\Models\NezhaRefundRecord;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 B方案 — 商家「逾期未退款」非资金约束的共用工具。
 *
 * 🔴 L1 红线: 本类零资金操作。只读写 restaurants 的「接单挂起标记」(与钱无关)、查询退款留痕。
 *   绝不动保证金、不代退、不向顾客打钱。实际退款永远靠商家自己原路退。
 */
class NezhaRefundOverdue
{
    /** 暂停某商家接单(退款逾期, 非资金)。由运营在后台手动一键执行。 */
    public static function suspend(int $restaurantId, string $reason): bool
    {
        $r = Restaurant::find($restaurantId);
        if (!$r) {
            return false;
        }
        $r->nezha_order_suspended = 1;
        $r->nezha_suspend_reason  = mb_substr($reason, 0, 255);
        $r->nezha_suspended_at    = now();
        $r->save();
        Log::info('NEZHA_REFUND_OVERDUE suspend restaurant#' . $restaurantId . ' :: ' . $reason);
        return true;
    }

    /** 解除某商家「退款逾期」接单挂起(运营手动)。 */
    public static function unsuspend(int $restaurantId): bool
    {
        $r = Restaurant::find($restaurantId);
        if (!$r) {
            return false;
        }
        $r->nezha_order_suspended = 0;
        $r->nezha_suspend_reason  = null;
        $r->nezha_suspended_at    = null;
        $r->save();
        Log::info('NEZHA_REFUND_OVERDUE unsuspend restaurant#' . $restaurantId);
        return true;
    }

    /**
     * 该商家已无逾期未退款留痕时, 自动解除「退款逾期」接单挂起。
     * 安全: nezha_order_suspended 专属退款逾期(不与保证金/打烊等复用), 故解除不误伤别的挂起来源。
     */
    public static function lift_suspend_if_clear(?int $restaurantId): void
    {
        if (!$restaurantId) {
            return;
        }
        try {
            $r = Restaurant::find($restaurantId);
            if (!$r || !(int) $r->nezha_order_suspended) {
                return;
            }
            $stillOverdue = NezhaRefundRecord::where('restaurant_id', $restaurantId)
                ->where('status', 'pending_merchant_refund')
                ->whereNull('merchant_refunded_at')
                ->exists();
            if (!$stillOverdue) {
                $r->nezha_order_suspended = 0;
                $r->nezha_suspend_reason  = null;
                $r->nezha_suspended_at    = null;
                $r->save();
                Log::info('NEZHA_REFUND_OVERDUE auto-lift suspend restaurant#' . $restaurantId);
            }
        } catch (\Throwable $e) {
            Log::warning('NEZHA_REFUND_OVERDUE lift_suspend failed restaurant#' . $restaurantId . ': ' . $e->getMessage());
        }
    }

    /** 接单闸用: 该商家是否因退款逾期被暂停接单(与钱无关)。 */
    public static function is_suspended($restaurant): bool
    {
        return $restaurant && (int) ($restaurant->nezha_order_suspended ?? 0) === 1;
    }

    /**
     * 读「逾期催办」小时阈值: 优先新「小时」键; 缺失回退旧「天」键×24兼容; 再缺用默认。L2 参数。
     * 哪吒[退款专项2026-06-22]: 外卖即时消费, 退款催办由「天」改「小时」粒度。
     */
    public static function thresholdHours(string $hoursKey, string $daysKey, int $defaultHours): int
    {
        $h = \App\Models\BusinessSetting::where('key', $hoursKey)->value('value');
        if ($h !== null && $h !== '') {
            return (int) $h;
        }
        $d = \App\Models\BusinessSetting::where('key', $daysKey)->value('value');
        if ($d !== null && $d !== '') {
            return ((int) $d) * 24;
        }
        return $defaultHours;
    }

    /** 把逾期小时数 humanize 成中文标签: <24h→「X小时」; ≥24h→「Y天」或「Y天Z小时」。 */
    public static function humanizeHours(int $hours): string
    {
        if ($hours < 24) {
            return max(0, $hours) . '小时';
        }
        $d = intdiv($hours, 24);
        $h = $hours % 24;
        return $h > 0 ? ($d . '天' . $h . '小时') : ($d . '天');
    }
}
