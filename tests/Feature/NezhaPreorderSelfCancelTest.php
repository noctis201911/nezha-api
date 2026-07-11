<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M6b / 11-6 —— confirmed 预约单自助取消谓词断言(confirmedSelfCancelAllowed·纯函数·无 DB)。
 * 谓词只判"该不该允许";总闸门(enabled())+ 锁内 fresh 复核 + 原路退款下游 触 DB, 留 staging。now 用固定 Carbon。
 */
class NezhaPreorderSelfCancelTest extends TestCase
{
    private function now(): Carbon
    {
        return Carbon::parse('2026-07-13 10:00:00'); // lead 默认 2h
    }

    /** @test 合法:预约 + confirmed + 窗口 > 2h 后 → 允许。 */
    public function scheduled_confirmed_far_enough_allowed(): void
    {
        $this->assertTrue(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', '2026-07-15 14:00:00', $this->now(), 2));
    }

    /** @test 即时单(scheduled=0)→ 永不走 11-6。 */
    public function instant_order_not_allowed(): void
    {
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(0, 'confirmed', '2026-07-15 14:00:00', $this->now(), 2));
    }

    /** @test 非 confirmed 态(pending/processing)→ 不走 11-6(pending 归普通取消;processing=已备货不许自助)。 */
    public function non_confirmed_status_not_allowed(): void
    {
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'pending', '2026-07-15 14:00:00', $this->now(), 2));
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'processing', '2026-07-15 14:00:00', $this->now(), 2));
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'handover', '2026-07-15 14:00:00', $this->now(), 2));
    }

    /** @test 窗口太近(< now+lead)→ 不许免费自助取消(须走商家审批)。 */
    public function too_close_to_window_not_allowed(): void
    {
        // 窗口 11:00 = now+1h < now+2h
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', '2026-07-13 11:00:00', $this->now(), 2));
    }

    /** @test 恰好 now+lead 边界(2h)→ 允许(≥ 含等于)。 */
    public function exactly_lead_boundary_allowed(): void
    {
        $this->assertTrue(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', '2026-07-13 12:00:00', $this->now(), 2));
    }

    /** @test schedule_at 为空 → 不允许(无窗口锚点)。 */
    public function empty_schedule_at_not_allowed(): void
    {
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', null, $this->now(), 2));
        $this->assertFalse(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', '', $this->now(), 2));
    }

    /** @test schedule_at 传 Carbon 实例也稳(控制器可能传已 cast 的 Carbon)。 */
    public function accepts_carbon_instance(): void
    {
        $this->assertTrue(NezhaPreorder::confirmedSelfCancelAllowed(1, 'confirmed', Carbon::parse('2026-07-15 14:00:00'), $this->now(), 2));
    }
}
