<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M4 —— 接单模式三态映射断言(纯逻辑·无 DB·安全对生产库)。
 * 只测 NezhaPreorder 的纯映射(modeToFlags/flagsToMode/modeEnablesPreorder);
 * 端点的总闸门 / ≥1时段守卫 / 原子写(触 DB·连生产库不造单)留 staging 真机验。
 */
class NezhaPreorderAcceptModeTest extends TestCase
{
    /** @test 三态 → 两 flag 权威映射逐态精确。 */
    public function mode_to_flags_maps_all_three_states(): void
    {
        $this->assertSame(['instant_order' => 1, 'schedule_order' => 0], NezhaPreorder::modeToFlags('instant'));
        $this->assertSame(['instant_order' => 1, 'schedule_order' => 1], NezhaPreorder::modeToFlags('instant_preorder'));
        $this->assertSame(['instant_order' => 0, 'schedule_order' => 1], NezhaPreorder::modeToFlags('preorder_only'));
    }

    /** @test 三态里没有任何一态是 0/0(既不即时也不预约=停业, 由构造排除, 不必再单独防 0/0)。 */
    public function no_mode_disables_both_flags(): void
    {
        foreach (['instant', 'instant_preorder', 'preorder_only'] as $m) {
            $f = NezhaPreorder::modeToFlags($m);
            $this->assertTrue($f['instant_order'] === 1 || $f['schedule_order'] === 1, "$m 不应两 flag 全关(停业态)");
        }
    }

    /** @test 非法 mode 返回 null → 端点据此拒绝, 绝不误写 flag。 */
    public function invalid_mode_returns_null(): void
    {
        $this->assertNull(NezhaPreorder::modeToFlags(''));
        $this->assertNull(NezhaPreorder::modeToFlags('preorder'));       // 近似词
        $this->assertNull(NezhaPreorder::modeToFlags('both'));
        $this->assertNull(NezhaPreorder::modeToFlags('INSTANT'));        // 大小写敏感
    }

    /** @test 反向 flagsToMode(渲染选中态): 三态可逆, 且 0/0 脏值安全回落即时。 */
    public function flags_to_mode_reverse_and_safe_default(): void
    {
        $this->assertSame('instant', NezhaPreorder::flagsToMode(1, 0));
        $this->assertSame('instant_preorder', NezhaPreorder::flagsToMode(1, 1));
        $this->assertSame('preorder_only', NezhaPreorder::flagsToMode(0, 1));
        $this->assertSame('instant', NezhaPreorder::flagsToMode(0, 0));  // 异常 0/0 回落即时(永不落停业)
        $this->assertSame('instant_preorder', NezhaPreorder::flagsToMode('1', '1')); // 字符串值也稳(DB 常存字符串)
    }

    /** @test modeEnablesPreorder: 只有开预约(schedule_order=1)两态为真——决定是否要 ≥1 时段守卫; 非法 mode → false。 */
    public function mode_enables_preorder_predicate(): void
    {
        $this->assertFalse(NezhaPreorder::modeEnablesPreorder('instant'));
        $this->assertTrue(NezhaPreorder::modeEnablesPreorder('instant_preorder'));
        $this->assertTrue(NezhaPreorder::modeEnablesPreorder('preorder_only'));
        $this->assertFalse(NezhaPreorder::modeEnablesPreorder('bogus'));
    }
}
