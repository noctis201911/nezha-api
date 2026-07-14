<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M6 —— 下单选窗口的时序校验断言(validateWindowTiming·纯函数·无 DB)。
 * 端点侧(窗口属本店/启用中·总闸门·写 window_id)触 DB 留 staging。now 用固定 Carbon(非 now()), 断言确定。
 */
class NezhaPreorderTimingTest extends TestCase
{
    // 固定"现在"= 2026-07-13(周一·dayOfWeek=1) 10:00。minLead=2h·maxDays=3d。
    private function now(): Carbon
    {
        return Carbon::parse('2026-07-13 10:00:00');
    }

    /** @test 合法:周三(dow=3) 窗口 14:00–16:00, schedule_at=2026-07-15 14:00(now+2天4h, 在 [+2h,+3d]·窗内且对齐)→ null。 */
    public function valid_window_point_passes(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 14:00:00'), 3, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertNull($err);
    }

    /** @test 单点模型:窗口内非起始的对齐点(14:20)也合法(旧模型只允许窗口起始)。 */
    public function mid_window_point_passes(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 14:20:00'), 3, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertNull($err);
    }

    /** @test 约过去 → 拒(最先判)。 */
    public function past_is_rejected(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-12 14:00:00'), 6, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertSame('不能预约过去的时段', $err);
    }

    /** @test 星期与窗口不匹配 → 拒。 */
    public function day_mismatch_is_rejected(): void
    {
        // 2026-07-16 是周四(dow=4), 却拿 windowDay=3
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-16 14:00:00'), 3, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertSame('所选日期与配送时段不匹配', $err);
    }

    /** @test 时刻超出窗口范围(晚于 end) → 拒。 */
    public function point_out_of_window_is_rejected(): void
    {
        // 周三窗口 14:00–14:40, schedule_at 却 15:00 > end
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 15:00:00'), 3, '14:00', '14:40', $this->now(), 2, 3, 20
        );
        $this->assertSame('所选时间不在配送时段内', $err);
    }

    /** @test 点未按 step 对齐(14:10, step=20) → 拒。 */
    public function misaligned_point_is_rejected(): void
    {
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-15 14:10:00'), 3, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertSame('所选送达时间无效', $err);
    }

    /** @test 提前量不足(< now+minLead) → 拒(星期/窗内都对但太近)。 */
    public function too_soon_is_rejected(): void
    {
        // 周一窗口 11:00–12:00, schedule_at=当天 11:00 = now+1h < now+2h
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-13 11:00:00'), 1, '11:00', '12:00', $this->now(), 2, 3, 20
        );
        $this->assertSame('需至少提前 2 小时预约', $err);
    }

    /** @test 超最远可约(> now+maxDays) → 拒。 */
    public function too_far_is_rejected(): void
    {
        // 周六窗口 14:00–16:00, schedule_at=2026-07-18(now+5天)> now+3天
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-18 14:00:00'), 6, '14:00', '16:00', $this->now(), 2, 3, 20
        );
        $this->assertSame('最多提前 3 天预约', $err);
    }

    /** @test minLead 边界:恰好 now+2h 通过(不是 <)。 */
    public function exactly_min_lead_passes(): void
    {
        // 周一窗口 12:00–13:00, point 12:00 = now+2h, 恰好达标
        $err = NezhaPreorder::validateWindowTiming(
            Carbon::parse('2026-07-13 12:00:00'), 1, '12:00', '13:00', $this->now(), 2, 3, 20
        );
        $this->assertNull($err);
    }
}
