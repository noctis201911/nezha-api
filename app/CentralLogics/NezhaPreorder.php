<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\NezhaDeliveryWindow;
use Carbon\Carbon;

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

    /**
     * 'H:i' 或 'H:i:s' → 当天分钟数(0-1439);格式非法返回 null。纯函数。
     * 营业时段列存 'H:i:s'(如 22:00:00),窗口输入是 'H:i'——统一归一为分钟再比,免字符串比较踩坑。秒粒度忽略(时段到分钟)。
     */
    public static function hmToMinutes(?string $t): ?int
    {
        if (!is_string($t) || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }

    /**
     * 配送窗口 [start,end] 是否**整段**落在某一个营业块 [opening,closing] 内(且 start<end)。纯函数·可单测。
     * 债辩 §4.2:配窗口时即校验窗口 ⊆ 营业时段(否则下单校验 order_validation_check 会直接拒单)。
     * $blocks:可迭代,每项数组或模型可 [] 取 opening_time/closing_time(一天可有多营业块,窗口不得跨闭店缝)。
     */
    public static function rangeWithinAnyBlock(string $start, string $end, iterable $blocks): bool
    {
        $ws = self::hmToMinutes($start);
        $we = self::hmToMinutes($end);
        if ($ws === null || $we === null || $ws >= $we) {
            return false;
        }
        foreach ($blocks as $block) {
            $os = self::hmToMinutes($block['opening_time'] ?? null);
            $ce = self::hmToMinutes($block['closing_time'] ?? null);
            if ($os === null || $ce === null) {
                continue;
            }
            if ($os <= $ws && $ce >= $we) {
                return true;
            }
        }
        return false;
    }

    /** 最少提前下单小时数(L2·后台可调·默认 2)。窗口起始 ≥ now + 本值 才可约。 */
    public static function minLeadHours(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_min_lead_hours')->first()->value ?? 2);
        return $v > 0 ? $v : 2;
    }

    /** 最远可约天数(L2·后台可调·默认 3)。窗口起始 ≤ now + 本值天 才可约。 */
    public static function maxDaysAhead(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_max_days_ahead')->first()->value ?? 3);
        return $v > 0 ? $v : 3;
    }

    /**
     * 校验一张预约单的 schedule_at 是否与所选窗口自洽 + 落在可约区间(纯函数·可单测)。返回中文错误串或 null(通过)。
     * 债辩纠正①:delivery 预约现只拦「不能约过去」, min_lead/max_days 是**净新增服务端硬校验**, 别当已复用。
     * 校验:不约过去 → 星期匹配窗口 day → 时刻等于窗口起始 → ≥ now+minLead → ≤ now+maxDays。
     */
    public static function validateWindowTiming(Carbon $scheduleAt, int $windowDay, string $windowStart, Carbon $now, int $minLeadHours, int $maxDays): ?string
    {
        if ($scheduleAt->lt($now)) {
            return '不能预约过去的时段';
        }
        if ((int) $scheduleAt->dayOfWeek !== $windowDay) {
            return '所选日期与配送时段不匹配';
        }
        $ws = self::hmToMinutes($windowStart);
        $sat = (int) $scheduleAt->format('H') * 60 + (int) $scheduleAt->format('i');
        if ($ws === null || $sat !== $ws) {
            return '所选时间与配送时段不匹配';
        }
        if ($scheduleAt->lt($now->copy()->addHours($minLeadHours))) {
            return '需至少提前 ' . $minLeadHours . ' 小时预约';
        }
        if ($scheduleAt->gt($now->copy()->addDays($maxDays))) {
            return '最多提前 ' . $maxDays . ' 天预约';
        }
        return null;
    }
}
