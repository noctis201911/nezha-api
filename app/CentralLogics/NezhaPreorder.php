<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\NezhaDeliveryWindow;

/**
 * 哪吒 预约下单 / 集中配送 — 接单模式逻辑中枢(M4)。
 *
 * 「接单模式」三态是**呈现层的收敛**,权威落到底座两个现成 flag:
 *   restaurants.schedule_order + restaurant_config.instant_order(不发明新字段,见 PLAN §3)。
 *
 * | 三态             | instant_order | schedule_order | 顾客看到          |
 * |------------------|:-------------:|:--------------:|-------------------|
 * | instant          |       1       |       0        | 只能「现在送」    |
 * | instant_preorder |       1       |       1        | 可选现在送/预约   |
 * | preorder_only    |       0       |       1        | 只能预约时段      |
 *
 * 第四种 0/0(既不即时也不预约=停业)由三态构造**天然不可能**——每态至少一 flag=1。
 *
 * 纯映射方法(modeToFlags / flagsToMode / modeEnablesPreorder)不碰 DB、可离线单测;
 * enabled()/hasActiveWindow() 触 DB(总闸 + 时段守卫),由 staging/真机验。
 * 合规:L2/L3——仅切「怎么接单」,不碰钱/退款/L1(见 PLAN §9)。全功能收在总闸 nezha_preorder_status(默认关)下。
 */
class NezhaPreorder
{
    const MODE_INSTANT          = 'instant';
    const MODE_INSTANT_PREORDER = 'instant_preorder';
    const MODE_PREORDER_ONLY    = 'preorder_only';

    /**
     * 三态 → 两 flag 的权威映射(纯函数·无 DB·可单测)。非法 mode 返回 null(端点据此拒绝,不误写)。
     */
    public static function modeToFlags(string $mode): ?array
    {
        return match ($mode) {
            self::MODE_INSTANT          => ['instant_order' => 1, 'schedule_order' => 0],
            self::MODE_INSTANT_PREORDER => ['instant_order' => 1, 'schedule_order' => 1],
            self::MODE_PREORDER_ONLY    => ['instant_order' => 0, 'schedule_order' => 1],
            default                     => null,
        };
    }

    /**
     * 两 flag → 三态(纯函数·反向·渲染抽屉选中态用)。
     * business_settings/列值常为字符串,故先 (int) 归一;异常 0/0 脏值安全回落「即时」。
     */
    public static function flagsToMode($instantOrder, $scheduleOrder): string
    {
        $i = (int) $instantOrder;
        $s = (int) $scheduleOrder;
        if ($i === 1 && $s === 1) return self::MODE_INSTANT_PREORDER;
        if ($i === 0 && $s === 1) return self::MODE_PREORDER_ONLY;
        return self::MODE_INSTANT; // 1/0 及异常 0/0 都归即时(安全默认,永不落到停业态)
    }

    /**
     * 该 mode 是否开启预约(schedule_order=1)——决定是否要跑「≥1 配送时段」守卫。纯函数。非法 mode → false。
     */
    public static function modeEnablesPreorder(string $mode): bool
    {
        $flags = self::modeToFlags($mode);
        return $flags !== null && $flags['schedule_order'] === 1;
    }

    /**
     * 预约下单总闸(默认关)。翻开须 cache:clear + php-fpm restart(进程内 static·见 PLAN §16)。
     */
    public static function enabled(): bool
    {
        return (bool) (BusinessSetting::where('key', 'nezha_preorder_status')->first()?->value ?? 0);
    }

    /**
     * 该商家有无 ≥1 个启用中的配送时段(预约模式的净新增守卫,mockup 01 状态B)。
     */
    public static function hasActiveWindow($restaurantId): bool
    {
        return NezhaDeliveryWindow::where('restaurant_id', $restaurantId)
            ->where('active', 1)
            ->exists();
    }
}
