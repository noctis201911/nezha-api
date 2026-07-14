<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 顾客选配送时段 READ 逻辑的纯单测(无 DB；入口仍受隔离库安全墙保护)。
 * 覆盖 NezhaPreorder::buildSlotDays / periodLabel / weekdayLabel / dayLabel —— 注入固定 $now 保证确定性。
 */
class NezhaPreorderSlotsTest extends TestCase
{
    /** 时段起始 → 上午/下午/晚上。 */
    public function test_period_label(): void
    {
        $this->assertSame('上午', NezhaPreorder::periodLabel('10:00'));
        $this->assertSame('上午', NezhaPreorder::periodLabel('09:30:00'));
        $this->assertSame('下午', NezhaPreorder::periodLabel('14:00'));
        $this->assertSame('下午', NezhaPreorder::periodLabel('12:00'));
        $this->assertSame('晚上', NezhaPreorder::periodLabel('18:00'));
        $this->assertSame('晚上', NezhaPreorder::periodLabel('21:30'));
    }

    /** 星期几中文(0=周日..6=周六)。 */
    public function test_weekday_label(): void
    {
        $this->assertSame('周日', NezhaPreorder::weekdayLabel(0));
        $this->assertSame('周五', NezhaPreorder::weekdayLabel(5));
        $this->assertSame('周六', NezhaPreorder::weekdayLabel(6));
    }

    /** 今天/明天/后天/n月j日。 */
    public function test_day_label(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');
        $this->assertSame('今天', NezhaPreorder::dayLabel($now->copy(), $now));
        $this->assertSame('明天', NezhaPreorder::dayLabel($now->copy()->addDay(), $now));
        $this->assertSame('后天', NezhaPreorder::dayLabel($now->copy()->addDays(2), $now));
        $this->assertSame($now->copy()->addDays(3)->format('n月j日'), NezhaPreorder::dayLabel($now->copy()->addDays(3), $now));
    }

    /** buildSlotDays 单点模型: 窗口按 20min 铺离散送达点; 今日早于提前量的点不可选、14 点后可选; earliest=最早可选点。 */
    public function test_build_slot_days_points_selectable_and_earliest(): void
    {
        $now   = Carbon::parse('2026-07-11 08:00:00');   // min_lead=2h → 最早可约 = 10:00
        $todayWd    = (int) $now->dayOfWeek;
        $tomorrowWd = (int) $now->copy()->addDay()->dayOfWeek;

        $windows = [
            ['id' => 1, 'day' => $todayWd,    'start_time' => '14:00:00', 'end_time' => '14:40:00'],   // 今天 14:00/14:20/14:40 → 全可选
            ['id' => 2, 'day' => $todayWd,    'start_time' => '08:00:00', 'end_time' => '08:40:00'],   // 今天 08:00/08:20/08:40 → 全早于 now+2h → 不可选
            ['id' => 3, 'day' => $tomorrowWd, 'start_time' => '10:00:00', 'end_time' => '10:20:00'],   // 明天 10:00/10:20 → 可选
        ];
        $r = NezhaPreorder::buildSlotDays($windows, $now, 2, 3, 20);

        $this->assertCount(2, $r['days']);                                // 今天 + 明天

        $today = $r['days'][0];
        $this->assertSame('今天', $today['day_label']);
        $this->assertSame($todayWd, $today['weekday']);
        $this->assertCount(6, $today['points']);                          // 08:00/08:20/08:40 + 14:00/14:20/14:40, 按时刻升序
        $this->assertSame('08:00', $today['points'][0]['time']);
        $this->assertFalse($today['points'][0]['selectable']);           // 早于提前量
        $this->assertSame('14:00', $today['points'][3]['time']);
        $this->assertTrue($today['points'][3]['selectable']);
        $this->assertSame('2026-07-11 14:00:00', $today['points'][3]['schedule_at']);   // 与 place_order 自洽
        $this->assertSame(1, $today['points'][3]['window_id']);
        $this->assertTrue($today['has_selectable']);

        $this->assertSame('明天', $r['days'][1]['day_label']);
        $this->assertCount(2, $r['days'][1]['points']);                   // 明天 10:00/10:20

        // earliest = 最早可选点(今天 14:00 早于明天 10:00)
        $this->assertNotNull($r['earliest']);
        $this->assertSame(1, $r['earliest']['window_id']);
        $this->assertSame('2026-07-11 14:00:00', $r['earliest']['schedule_at']);
        $this->assertSame('今天', $r['earliest']['day_label']);
        $this->assertSame('14:00', $r['earliest']['time']);
    }

    /** 单点铺开 + 相邻窗口共享边界点去重: 10:00-11:00 与 11:00-12:00 → 11:00 只留一个(7 点非 8)。 */
    public function test_build_slot_days_points_step_and_dedup(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');   // 最早可约 10:00
        $wd  = (int) $now->dayOfWeek;
        $windows = [
            ['id' => 1, 'day' => $wd, 'start_time' => '10:00:00', 'end_time' => '11:00:00'],
            ['id' => 2, 'day' => $wd, 'start_time' => '11:00:00', 'end_time' => '12:00:00'],
        ];
        $r = NezhaPreorder::buildSlotDays($windows, $now, 2, 3, 20);
        $this->assertCount(1, $r['days']);
        $pts = $r['days'][0]['points'];
        $this->assertCount(7, $pts);   // 10:00,10:20,10:40,11:00(去重),11:20,11:40,12:00
        $this->assertSame(['10:00', '10:20', '10:40', '11:00', '11:20', '11:40', '12:00'], array_column($pts, 'time'));
        $this->assertTrue($pts[0]['selectable']);   // 10:00 == now+2h → gte → 可选
    }

    /** 空窗口 → days 空、earliest null。 */
    public function test_build_slot_days_empty(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');
        $r = NezhaPreorder::buildSlotDays([], $now, 2, 3);
        $this->assertSame([], $r['days']);
        $this->assertNull($r['earliest']);
    }

    /** 今日时段全截止(唯一窗口整段早于提前量)→ 今天仍出 tab、点全不可选、has_selectable=false、earliest=null(空态出路)。 */
    public function test_build_slot_days_today_all_cutoff(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');
        $windows = [['id' => 9, 'day' => (int) $now->dayOfWeek, 'start_time' => '08:00:00', 'end_time' => '09:00:00']];   // 08:00-09:00 全 < now+2h(10:00)
        $r = NezhaPreorder::buildSlotDays($windows, $now, 2, 3, 20);
        $this->assertCount(1, $r['days']);
        $this->assertFalse($r['days'][0]['has_selectable']);
        $this->assertFalse($r['days'][0]['points'][0]['selectable']);
        $this->assertNull($r['earliest']);
    }
}
