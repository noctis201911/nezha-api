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

    /** 送达时间点步长分钟(L2·后台可调·默认 20·业主 2026-07-11 定)。窗口内按本值铺离散送达点(像美团 10:00/10:20/10:40)。 */
    public static function pointStepMin(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_point_step_min')->first()->value ?? 20);
        return $v > 0 ? $v : 20;
    }

    /**
     * 校验一张预约单的 schedule_at 是否与所选窗口自洽 + 落在可约区间(纯函数·可单测)。返回中文错误串或 null(通过)。
     * 🔴 单点模型(业主 2026-07-11 定·推翻窗口起始唯一): 顾客在窗口 [start,end] 内选一个 step(默认20min)对齐的**精确送达点**,
     *    不再只能选窗口起始。故时刻校验从「==windowStart」改为「∈[windowStart,windowEnd] 且按 step 对齐」。
     * 校验:不约过去 → 星期匹配窗口 day → 时刻在窗口内且 step 对齐 → ≥ now+minLead → ≤ now+maxDays。
     */
    public static function validateWindowTiming(Carbon $scheduleAt, int $windowDay, string $windowStart, string $windowEnd, Carbon $now, int $minLeadHours, int $maxDays, int $stepMin): ?string
    {
        if ($scheduleAt->lt($now)) {
            return '不能预约过去的时段';
        }
        if ((int) $scheduleAt->dayOfWeek !== $windowDay) {
            return '所选日期与配送时段不匹配';
        }
        $ws  = self::hmToMinutes($windowStart);
        $we  = self::hmToMinutes($windowEnd);
        $sat = (int) $scheduleAt->format('H') * 60 + (int) $scheduleAt->format('i');
        if ($ws === null || $we === null || $sat < $ws || $sat > $we) {
            return '所选时间不在配送时段内';
        }
        if ($stepMin > 0 && ($sat - $ws) % $stepMin !== 0) {
            return '所选送达时间无效';
        }
        if ($scheduleAt->lt($now->copy()->addHours($minLeadHours))) {
            return '需至少提前 ' . $minLeadHours . ' 小时预约';
        }
        if ($scheduleAt->gt($now->copy()->addDays($maxDays))) {
            return '最多提前 ' . $maxDays . ' 天预约';
        }
        return null;
    }

    /** 预约单窗口前"免费自助取消"的提前量小时数(L2·后台可调·默认 2·业主 2026-07-11 定)。 */
    public static function freeCancelLeadHours(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_free_cancel_lead_hours')->first()->value ?? 2);
        return $v > 0 ? $v : 2;
    }

    /**
     * 11-6:已确认(confirmed)预约单是否允许顾客自助免费取消(纯谓词·可单测)。业主 2026-07-11 批。
     * 条件:scheduled=1 且 order_status==confirmed(=已接单但未备货, processing 起就不算) 且 窗口起始 ≥ now + freeCancelLead 小时。
     * 🔴 调用方须另外用 NezhaPreorder::enabled() 门控(总闸关时整条 11-6 不生效)。$scheduleAt 接受 Carbon 或字符串或空。
     */
    public static function confirmedSelfCancelAllowed(int $scheduled, string $orderStatus, $scheduleAt, Carbon $now, int $freeCancelLeadHours): bool
    {
        if ($scheduled !== 1 || $orderStatus !== 'confirmed' || empty($scheduleAt)) {
            return false;
        }
        $sat = $scheduleAt instanceof Carbon ? $scheduleAt : Carbon::parse($scheduleAt);
        return $now->copy()->addHours($freeCancelLeadHours)->lte($sat);
    }

    /**
     * M7:预约单是否可被商家「批量标出餐」(纯谓词·可单测)。业主 2026-07-11 定映射:待出餐=confirmed → 标出餐 → handover(出餐待叫车)。
     * 条件:scheduled=1 且 order_status==confirmed(跳过 processing 备餐中·集中备货一次性出餐)。
     * 🔴 调用方须另用 enabled() 门控 + IDOR(restaurant_id) + 锁内以 fresh 状态复核。「转入配送」不批量翻 picked_up(不告诉顾客批量配送), 走逐单 Yandex。
     */
    public static function canBatchReady(int $scheduled, string $orderStatus): bool
    {
        return $scheduled === 1 && $orderStatus === 'confirmed';
    }

    /** screen05/11-7:建议提前叫车分钟数(L2·后台可调·默认 30·业主 2026-07-11 定「固定提前量」·非实时 ETA)。 */
    public static function dispatchLeadMin(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_dispatch_lead_min')->first()->value ?? 30);
        return $v > 0 ? $v : 30;
    }

    /** screen05:到窗口提醒阈值分钟数(L2·后台可调·默认 45·下一个窗口起始 ≤ 本值分钟内即在作业台顶部提醒)。 */
    public static function windowRemindMin(): int
    {
        $v = (int) (BusinessSetting::where('key', 'nezha_preorder_window_remind_min')->first()->value ?? 45);
        return $v > 0 ? $v : 45;
    }

    /**
     * screen05 单点版 · 作业台每单卡态(纯函数·可单测·替代窗口版 workbenchGroupState·业主 2026-07-11 单点模型)。
     *   delivered = 已送达(order_status=delivered)→ 族②绿·沉底。
     *   called    = 已叫车(order_status=picked_up·商家已叫 Yandex)→ 族②绿·沉底。
     *   due       = 该叫车(未派出 且 now ≥ 建议叫车时间=送达点 − dispatch_lead)→ 族①琥珀·置顶带[叫车]。
     *   upcoming  = 未到时间(未派出 且 建议叫车时间未到)→ 族③紫·白卡无按钮。
     * $callTimeReached = now 是否已达该单建议叫车时间(调用方按 schedule_at − dispatch_lead 算)。
     */
    public static function pointCardState(string $orderStatus, bool $callTimeReached): string
    {
        if ($orderStatus === 'delivered') {
            return 'delivered';
        }
        if ($orderStatus === 'picked_up') {
            return 'called';
        }
        return $callTimeReached ? 'due' : 'upcoming';
    }

    /* ───────────────────────── M6 顾客选配送时段(READ·screen03/04) ───────────────────────── */

    /** 时段起始时刻 → 上午/下午/晚上('HH:MM' 或 'HH:MM:SS')。纯函数。 */
    public static function periodLabel(string $startHm): string
    {
        $h = (int) substr($startHm, 0, 2);
        if ($h < 12) { return '上午'; }
        if ($h < 18) { return '下午'; }
        return '晚上';
    }

    /** 星期几(0=周日..6=周六·Carbon dayOfWeek 口径)→ 中文。纯函数。 */
    public static function weekdayLabel(int $wd): string
    {
        return ['周日', '周一', '周二', '周三', '周四', '周五', '周六'][(($wd % 7) + 7) % 7] ?? '';
    }

    /** 日期标签(今天/明天/后天/n月j日)。screen05 作业台 + 顾客选窗口单一真相源。纯函数。 */
    public static function dayLabel(Carbon $d, Carbon $now): string
    {
        $days = (int) $now->copy()->startOfDay()->diffInDays($d->copy()->startOfDay(), false);
        if ($days <= 0) { return '今天'; }
        if ($days === 1) { return '明天'; }
        if ($days === 2) { return '后天'; }
        return $d->format('n月j日');
    }

    /**
     * 顾客选配送时段:把 active 窗口按「今日起 max_days 天」铺成离散**送达时间点** {days, earliest}(纯函数·可单测·$now 注入)。
     * 🔴 单点模型(业主 2026-07-11·推翻整窗口一 slot): 每个窗口 [start,end] 按 step(默认20min)铺离散点(像美团 10:00/10:20/10:40),
     *    顾客选一个精确送达点。每 point 带 schedule_at('Y-m-d H:i:s'=该日期+点时刻, 与 place_order/validateWindowTiming 自洽)
     *    + selectable(受 min_lead/max_days/不约过去过滤)。相邻窗口共享边界点按时刻去重。有点的 weekday 才出该天(空天不出 tab)。
     * $windows: 可迭代, 每项数组可 [] 取 id/day/start_time/end_time(day=0-6 周日..周六)。
     */
    public static function buildSlotDays(iterable $windows, Carbon $now, int $minLeadHours, int $maxDays, int $stepMin = 20): array
    {
        $stepMin     = $stepMin > 0 ? $stepMin : 20;
        $earliestSel = $now->copy()->addHours($minLeadHours);
        $latestSel   = $now->copy()->addDays($maxDays);

        $byDay = [];
        foreach ($windows as $w) {
            $byDay[(int) ($w['day'] ?? -1)][] = $w;
        }

        $days = [];
        $earliest = null;
        for ($d = 0; $d <= $maxDays; $d++) {
            $date = $now->copy()->addDays($d)->startOfDay();
            $wd = (int) $date->dayOfWeek;
            if (empty($byDay[$wd])) {
                continue;
            }
            $dayWins = $byDay[$wd];
            usort($dayWins, fn($a, $b) => strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? '')));

            // 当天所有窗口按 step 铺离散送达点, 以分钟为键去重(相邻窗口共享边界点只留一个·先到先得)。
            $pointsByMin = [];
            foreach ($dayWins as $w) {
                $sMin = self::hmToMinutes(substr((string) ($w['start_time'] ?? ''), 0, 5));
                $eMin = self::hmToMinutes(substr((string) ($w['end_time'] ?? ''), 0, 5));
                if ($sMin === null || $eMin === null || $sMin >= $eMin) {
                    continue;
                }
                for ($m = $sMin; $m <= $eMin; $m += $stepMin) {
                    if (isset($pointsByMin[$m])) { continue; }
                    $hm  = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
                    $sat = Carbon::parse($date->format('Y-m-d') . ' ' . $hm . ':00');
                    $selectable = $sat->gte($earliestSel) && $sat->lte($latestSel) && $sat->gt($now);
                    $pointsByMin[$m] = [
                        'window_id'   => (int) ($w['id'] ?? 0),
                        'time'        => $hm,
                        'schedule_at' => $sat->format('Y-m-d H:i:s'),
                        'selectable'  => $selectable,
                    ];
                }
            }
            if (!$pointsByMin) {
                continue;
            }
            ksort($pointsByMin);   // 按时刻(分钟)升序
            $points = array_values($pointsByMin);

            $hasSel = false;
            foreach ($points as $p) {
                if (!$p['selectable']) { continue; }
                $hasSel = true;
                if ($earliest === null || Carbon::parse($p['schedule_at'])->lt(Carbon::parse($earliest['schedule_at']))) {
                    $earliest = [
                        'window_id'   => $p['window_id'],
                        'schedule_at' => $p['schedule_at'],
                        'date'        => $date->format('Y-m-d'),
                        'day_label'   => self::dayLabel($date, $now),
                        'time'        => $p['time'],
                    ];
                }
            }
            $days[] = [
                'date'           => $date->format('Y-m-d'),
                'day_label'      => self::dayLabel($date, $now),
                'weekday'        => $wd,
                'weekday_label'  => self::weekdayLabel($wd),
                'has_selectable' => $hasSel,
                'points'         => $points,
            ];
        }

        return ['days' => $days, 'earliest' => $earliest];
    }

    /**
     * 顾客选配送时段完整数据(查本店 active 窗口 → buildSlotDays)。$now 缺省 now()。
     * 🔴 调用方(端点)须先 enabled() 门控 + 店存在/active 校验。返回 {days, earliest}。
     */
    public static function computeSelectableSlots(int $restaurantId, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $windows = NezhaDeliveryWindow::where('restaurant_id', $restaurantId)
            ->where('active', 1)
            ->get(['id', 'day', 'start_time', 'end_time'])
            ->map(fn($w) => ['id' => (int) $w->id, 'day' => (int) $w->day, 'start_time' => (string) $w->start_time, 'end_time' => (string) $w->end_time])
            ->all();

        return self::buildSlotDays($windows, $now, self::minLeadHours(), self::maxDaysAhead(), self::pointStepMin());
    }
}
