<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 顾客选配送时段 READ 逻辑的纯单测(无 DB·安全对生产库·零写入)。
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

    /** buildSlotDays 主路径: 今日 14:00 可选、今日 09:00 早于提前量不可选、明日 10:00 可选; earliest=最早可选。 */
    public function test_build_slot_days_selectable_and_earliest(): void
    {
        $now   = Carbon::parse('2026-07-11 08:00:00');   // min_lead=2h → 最早可约 = 10:00
        $todayWd    = (int) $now->dayOfWeek;
        $tomorrowWd = (int) $now->copy()->addDay()->dayOfWeek;

        $windows = [
            ['id' => 1, 'day' => $todayWd,    'start_time' => '14:00:00', 'end_time' => '16:00:00'],   // 今天 14:00 → 可选
            ['id' => 2, 'day' => $todayWd,    'start_time' => '09:00:00', 'end_time' => '11:00:00'],   // 今天 09:00 → 早于 now+2h → 不可选
            ['id' => 3, 'day' => $tomorrowWd, 'start_time' => '10:00:00', 'end_time' => '12:00:00'],   // 明天 10:00 → 可选
        ];
        $r = NezhaPreorder::buildSlotDays($windows, $now, 2, 3);

        $this->assertCount(2, $r['days']);                                // 今天 + 明天(其余 weekday 无窗口)

        $today = $r['days'][0];
        $this->assertSame('今天', $today['day_label']);
        $this->assertSame($todayWd, $today['weekday']);
        $this->assertCount(2, $today['slots']);                          // 按 start 升序: 09:00, 14:00
        $this->assertSame('09:00', $today['slots'][0]['start']);
        $this->assertFalse($today['slots'][0]['selectable']);            // 09:00 早于提前量
        $this->assertSame('14:00', $today['slots'][1]['start']);
        $this->assertTrue($today['slots'][1]['selectable']);
        $this->assertSame('下午', $today['slots'][1]['period']);
        $this->assertSame('2026-07-11 14:00:00', $today['slots'][1]['schedule_at']);   // 与 place_order 自洽
        $this->assertTrue($today['has_selectable']);

        $this->assertSame('明天', $r['days'][1]['day_label']);

        // earliest = 最早可选(今天 14:00 早于明天 10:00)
        $this->assertNotNull($r['earliest']);
        $this->assertSame(1, $r['earliest']['window_id']);
        $this->assertSame('2026-07-11 14:00:00', $r['earliest']['schedule_at']);
        $this->assertSame('今天', $r['earliest']['day_label']);
    }

    /** 空窗口 → days 空、earliest null。 */
    public function test_build_slot_days_empty(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');
        $r = NezhaPreorder::buildSlotDays([], $now, 2, 3);
        $this->assertSame([], $r['days']);
        $this->assertNull($r['earliest']);
    }

    /** 今日时段全截止(唯一窗口早于提前量)→ 今天仍出 tab、slot 不可选、has_selectable=false、earliest=null(mockup03 状态C)。 */
    public function test_build_slot_days_today_all_cutoff(): void
    {
        $now = Carbon::parse('2026-07-11 08:00:00');
        $windows = [['id' => 9, 'day' => (int) $now->dayOfWeek, 'start_time' => '09:00:00', 'end_time' => '11:00:00']];   // 09:00 < now+2h
        $r = NezhaPreorder::buildSlotDays($windows, $now, 2, 3);
        $this->assertCount(1, $r['days']);
        $this->assertFalse($r['days'][0]['has_selectable']);
        $this->assertFalse($r['days'][0]['slots'][0]['selectable']);
        $this->assertNull($r['earliest']);
    }
}
