<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M6 —— 下单选窗口的时序校验断言(validateWindowTiming·纯函数·无 DB·安全对生产库)。
 * 端点侧(窗口属本店/启用中·总闸门·写 window_id)触 DB 留 staging。now 用固定 Carbon(非 now()), 断言确定。
 */
class NezhaPreorderTimingTest extends TestCase
{
    // 固定"现在"= 2026-07-13(周一·dayOfWeek=1) 10:00。minLead=2h·maxDays=3d。
    private function now(): Carbon
    {
        return Carbon::parse('2026-07-13 10:00:00');
    }

    /** @test 合法:周三(dow=3) 14:00 窗口, schedule_at=2026-07-15 14:00(now+2天4h, 在 [+2h,+3d] 内·星期时刻都对)→ null。 */
    public function valid_window_order_passes(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 14:00:00'), 3, '14:00', $this->now(), 2, 3
        );
        $this->assertNull($err);
    }

    /** @test 约过去 → 拒(最先判)。 */
    public function past_is_rejected(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-12 14:00:00'), 6, '14:00', $this->now(), 2, 3
        );
        $this->assertSame('不能预约过去的时段', $err);
    }

    /** @test 星期与窗口不匹配 → 拒。 */
    public function day_mismatch_is_rejected(): void
    {
        // 2026-07-16 是周四(dow=4), 却拿 windowDay=3
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-16 14:00:00'), 3, '14:00', $this->now(), 2, 3
        );
        $this->assertSame('所选日期与配送时段不匹配', $err);
    }

    /** @test 时刻不等于窗口起始 → 拒。 */
    public function time_mismatch_is_rejected(): void
    {
        // 周三对了, 但窗口起始 14:00, schedule_at 却 15:00
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 15:00:00'), 3, '14:00', $this->now(), 2, 3
        );
        $this->assertSame('所选时间与配送时段不匹配', $err);
    }

    /** @test 提前量不足(< now+minLead) → 拒(星期/时刻都对但太近)。 */
    public function too_soon_is_rejected(): void
    {
        // 周一 11:00 窗口, schedule_at=当天 11:00 = now+1h < now+2h
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-13 11:00:00'), 1, '11:00', $this->now(), 2, 3
        );
        $this->assertSame('需至少提前 2 小时预约', $err);
    }

    /** @test 超最远可约(> now+maxDays) → 拒。 */
    public function too_far_is_rejected(): void
    {
        // 周六 14:00 窗口, schedule_at=2026-07-18(now+5天)> now+3天
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-18 14:00:00'), 6, '14:00', $this->now(), 2, 3
        );
        $this->assertSame('最多提前 3 天预约', $err);
    }

    /** @test minLead 边界:恰好 now+2h 通过(不是 <)。 */
    public function exactly_min_lead_passes(): void
    {
        // 周一 12:00 = now+2h, 星期/时刻对, 恰好达标
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-13 12:00:00'), 1, '12:00', $this->now(), 2, 3
        );
        $this->assertNull($err);
    }
}
