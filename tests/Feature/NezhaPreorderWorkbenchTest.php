<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

/**
 * screen05 集中配送作业台「预约」分区的纯逻辑单测(无 DB·安全对生产库·零写入)。
 * 覆盖 NezhaPreorder::workbenchGroupState —— 窗口分组呈现态(done/hot/upcoming)判定。
 */
class NezhaPreorderWorkbenchTest extends TestCase
{
    /** 全部派出(picked_up/delivered) → done(已完成), 不论剩余时间。 */
    public function test_all_dispatched_is_done(): void
    {
        $this->assertSame('done', NezhaPreorder::workbenchGroupState(200, true, 45));
        $this->assertSame('done', NezhaPreorder::workbenchGroupState(-30, true, 45));
        $this->assertSame('done', NezhaPreorder::workbenchGroupState(0, true, 45));
    }

    /** 未全派出 + 窗口起始 ≤ 阈值(含正好开始/已进行中的负值) → hot(临近·该集中备货叫车)。 */
    public function test_within_threshold_is_hot(): void
    {
        $this->assertSame('hot', NezhaPreorder::workbenchGroupState(45, false, 45));   // 边界 = 阈值
        $this->assertSame('hot', NezhaPreorder::workbenchGroupState(42, false, 45));   // mockup05 示例(42 < 45)
        $this->assertSame('hot', NezhaPreorder::workbenchGroupState(0, false, 45));    // 正好开始
        $this->assertSame('hot', NezhaPreorder::workbenchGroupState(-15, false, 45));  // 窗口进行中(已过起始)
    }

    /** 未全派出 + 窗口起始还早(> 阈值) → upcoming(未到时段)。 */
    public function test_beyond_threshold_is_upcoming(): void
    {
        $this->assertSame('upcoming', NezhaPreorder::workbenchGroupState(46, false, 45));   // 边界外一分钟
        $this->assertSame('upcoming', NezhaPreorder::workbenchGroupState(300, false, 45));  // 5 小时后
    }

    /** 阈值可调:同一剩余时间, 阈值变大即更早进入 hot。 */
    public function test_threshold_is_configurable(): void
    {
        $this->assertSame('upcoming', NezhaPreorder::workbenchGroupState(50, false, 45));
        $this->assertSame('hot', NezhaPreorder::workbenchGroupState(50, false, 60));
    }

    /** done 优先于时间态:已全派出即便窗口未到(未来正值)也算 done, 不误标 upcoming。 */
    public function test_done_takes_precedence_over_timing(): void
    {
        $this->assertSame('done', NezhaPreorder::workbenchGroupState(500, true, 45));
    }
}
