<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M5 —— 配送时段窗口的纯校验断言(hmToMinutes / rangeWithinAnyBlock·无 DB·安全对生产库)。
 * 端点的总闸门 / IDOR 作用域 / 去重 / 有订单拒删(触 DB·连生产库不造单)留 staging 真机验。
 */
class NezhaPreorderWindowValidationTest extends TestCase
{
    /** @test hmToMinutes: 'H:i' / 'H:i:s' 归一为分钟, 非法返回 null。 */
    public function hm_to_minutes_parses_and_rejects(): void
    {
        $this->assertSame(0, NezhaPreorder::hmToMinutes('00:00'));
        $this->assertSame(1439, NezhaPreorder::hmToMinutes('23:59'));
        $this->assertSame(600, NezhaPreorder::hmToMinutes('10:00:00')); // 营业时段的 H:i:s 也吃
        $this->assertSame(545, NezhaPreorder::hmToMinutes('09:05'));
        $this->assertSame(1320, NezhaPreorder::hmToMinutes('22:00:00'));
        // 非法
        $this->assertNull(NezhaPreorder::hmToMinutes('24:00'));  // 时 >23
        $this->assertNull(NezhaPreorder::hmToMinutes('12:60'));  // 分 >59
        $this->assertNull(NezhaPreorder::hmToMinutes('9:5'));    // 分非两位
        $this->assertNull(NezhaPreorder::hmToMinutes(''));
        $this->assertNull(NezhaPreorder::hmToMinutes('abc'));
        $this->assertNull(NezhaPreorder::hmToMinutes(null));
    }

    private function hours($o, $c): array
    {
        return ['opening_time' => $o, 'closing_time' => $c];
    }

    /** @test 窗口整段落在单个营业块内 → true(含精确等于边界)。 */
    public function window_within_single_block(): void
    {
        $b = [$this->hours('10:00:00', '22:00:00')];
        $this->assertTrue(NezhaPreorder::rangeWithinAnyBlock('14:00', '16:00', $b));
        $this->assertTrue(NezhaPreorder::rangeWithinAnyBlock('10:00', '22:00', $b)); // 精确等于营业边界
    }

    /** @test 越界 → false(起点早于开门 / 终点晚于关门·mockup02 状态B)。 */
    public function window_out_of_hours_is_false(): void
    {
        $b = [$this->hours('10:00:00', '22:00:00')];
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('09:00', '11:00', $b)); // 早于开门
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('21:30', '23:00', $b)); // 晚于关门(状态B)
    }

    /** @test 多营业块:落在第二块 → true;落在闭店缝 / 跨两块 → false。 */
    public function multi_block_containment(): void
    {
        $b = [$this->hours('10:00:00', '12:00:00'), $this->hours('15:00:00', '18:00:00')];
        $this->assertTrue(NezhaPreorder::rangeWithinAnyBlock('16:00', '17:00', $b));  // 第二块内
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('13:00', '14:00', $b)); // 落在闭店缝
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('11:00', '16:00', $b)); // 跨两块(无单块含它)
    }

    /** @test start>=end / 空营业(当天不营业)→ false。 */
    public function invalid_range_or_no_hours_is_false(): void
    {
        $b = [$this->hours('10:00:00', '22:00:00')];
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('16:00', '14:00', $b)); // 倒挂
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('14:00', '14:00', $b)); // 零长
        $this->assertFalse(NezhaPreorder::rangeWithinAnyBlock('14:00', '16:00', [])); // 当天无营业块
    }

    /** @test 23:59:59 收尾的营业块, 窗口可贴到 23:59 → true(秒粒度忽略不误拒)。 */
    public function end_of_day_block_boundary(): void
    {
        $b = [$this->hours('00:00:00', '23:59:59')];
        $this->assertTrue(NezhaPreorder::rangeWithinAnyBlock('20:00', '23:59', $b));
    }
}
