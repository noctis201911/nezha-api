<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

/**
 * screen05 单点版作业台「预约配送」每单卡态的纯逻辑单测(无 DB·安全对生产库·零写入)。
 * 覆盖 NezhaPreorder::pointCardState —— 按 order_status + 是否到建议叫车时间判 due/upcoming/called/delivered。
 */
class NezhaPreorderWorkbenchTest extends TestCase
{
    /** 已送达(delivered)→ delivered, 不论是否到建议叫车时间。 */
    public function test_delivered_is_delivered(): void
    {
        $this->assertSame('delivered', NezhaPreorder::pointCardState('delivered', true));
        $this->assertSame('delivered', NezhaPreorder::pointCardState('delivered', false));
    }

    /** 已叫车(picked_up)→ called, 不论是否到建议叫车时间。 */
    public function test_picked_up_is_called(): void
    {
        $this->assertSame('called', NezhaPreorder::pointCardState('picked_up', true));
        $this->assertSame('called', NezhaPreorder::pointCardState('picked_up', false));
    }

    /** 未派出(confirmed/processing/handover) + 已到建议叫车时间 → due(该叫车·带[叫车])。 */
    public function test_active_and_call_time_reached_is_due(): void
    {
        foreach (['confirmed', 'processing', 'handover'] as $st) {
            $this->assertSame('due', NezhaPreorder::pointCardState($st, true), $st);
        }
    }

    /** 未派出 + 建议叫车时间未到 → upcoming(未到时间·无按钮)。 */
    public function test_active_and_call_time_not_reached_is_upcoming(): void
    {
        foreach (['confirmed', 'processing', 'handover'] as $st) {
            $this->assertSame('upcoming', NezhaPreorder::pointCardState($st, false), $st);
        }
    }

    /** 派出态(picked_up/delivered)优先于时间: 即便未到建议叫车时间也不回落 due/upcoming(作业台待办优先·已完事沉底)。 */
    public function test_dispatched_states_take_precedence_over_timing(): void
    {
        $this->assertSame('called', NezhaPreorder::pointCardState('picked_up', false));
        $this->assertSame('delivered', NezhaPreorder::pointCardState('delivered', false));
    }

    /** 在场感知(阶段③④叫车推送用): 打点后 20s 内判在看; 未打点/已清=不在看。叫车推送据此抑制。 */
    public function test_workbench_presence_roundtrip(): void
    {
        \Illuminate\Support\Facades\Cache::forget('nezha_wb_seen_99999');
        $this->assertFalse(NezhaPreorder::isViewingWorkbench(99999));
        NezhaPreorder::markWorkbenchSeen(99999);
        $this->assertTrue(NezhaPreorder::isViewingWorkbench(99999));
        \Illuminate\Support\Facades\Cache::forget('nezha_wb_seen_99999');
        $this->assertFalse(NezhaPreorder::isViewingWorkbench(99999));
    }

    public function test_dispatch_reminder_dry_run_does_not_write_cooldown(): void
    {
        $source = file_get_contents(app_path('Console/Commands/NezhaPreorderDispatchReminder.php'));
        $start = strpos($source, 'if ($dry) {');
        $end = strpos($source, 'continue;', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $dryRunBlock = substr($source, $start, $end - $start);
        $this->assertStringNotContainsString('Cache::put', $dryRunBlock);
    }
}
