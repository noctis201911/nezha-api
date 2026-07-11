<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaOrderTimeout;
use App\Models\Order;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 哪吒 预约下单 P0 —— 窗口锚定时钟断言(锁死"预约单承诺窗口前不被超时清扫误杀" + "即时单零回归")。
 * 纯计算断言: 反射注入 settings、构造内存 Order(无 DB)。改 NezhaOrderTimeout 触 L1-1/L1-2, 业主 2026-07-11 批准。
 */
class NezhaPreorderTimeoutTest extends TestCase
{
    private function injectSettings(int $lead = 0): void
    {
        $ref = new \ReflectionProperty(NezhaOrderTimeout::class, 'settingsCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'status' => 1, 'remind' => 5, 'email_merchant' => 10,
            'unpaid_cancel' => 10, 'cancel' => 20, 'prep_orange' => 5,
            'prep_red' => 15, 'handover' => 45, 'picked' => 90,
            'preorder_lead' => $lead,
        ]);
    }

    protected function tearDown(): void
    {
        // 清掉注入, 免污染其它用真实 settings 的测试
        $ref = new \ReflectionProperty(NezhaOrderTimeout::class, 'settingsCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        parent::tearDown();
    }

    private function makeOrder(array $attrs): Order
    {
        $o = new Order();
        $o->forceFill(array_merge([
            'order_status'   => 'pending',
            'payment_method' => 'offline_payment',
            'order_type'     => 'delivery',
            'scheduled'      => 0,
            'schedule_at'    => Carbon::now(),
            'created_at'     => Carbon::now(),
        ], $attrs));
        $o->setRelation('offline_payments', null); // 免 lazy-load 打 DB; clockStart 退到 created_at
        return $o;
    }

    /** @test P0 核心: 预约单在承诺窗口前, phase=null(展示不显超时)、clockStart=null(sweep !$start continue 跳过, 绝不误杀)。 */
    public function preorder_far_from_window_is_dormant_for_timeout(): void
    {
        $this->injectSettings(0);
        $order = $this->makeOrder([
            'scheduled'   => 1,
            'schedule_at' => Carbon::now()->addDays(2),      // 两天后的配送窗口
            'created_at'  => Carbon::now()->subMinutes(30),  // 30 分钟前下单(即时单口径下早该被杀)
        ]);
        $this->assertNull(NezhaOrderTimeout::phase($order), '预约单窗口前 phase 应为 null');
        $this->assertNull(
            NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_PROOF),
            '预约单窗口前 clockStart 应为 null → sweep continue 跳过, 不被当"商家超时"误杀'
        );
    }

    /** @test 未付款预约单窗口临近: clockStart 非空且锚在窗口点(非 created_at)→ sweep 进入清扫流程, 不秒超时。 */
    public function preorder_near_window_starts_clock_at_window_not_created_at(): void
    {
        $this->injectSettings(0);
        $order = $this->makeOrder([
            'scheduled'   => 1,
            'schedule_at' => Carbon::now()->subMinutes(5),  // 窗口已开始约 5 分钟
            'created_at'  => Carbon::now()->subDays(1),      // 一天前下单
        ]);
        $start = NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_PROOF);
        $this->assertNotNull($start, '窗口临近后 clockStart 应非空');
        $this->assertLessThanOrEqual(
            6, $start->diffInMinutes(Carbon::now()),
            'clockStart 应锚在窗口点(约5min前), 不是 created_at(1天前)——否则一进阶段就秒超时'
        );
        $this->assertNotNull(NezhaOrderTimeout::phase($order), '窗口临近后 phase 应非空');
    }

    /** @test 即时单(scheduled=0)零回归: clockStart 仍锚 created_at、phase 仍为 PROOF, 与旧行为逐字一致。 */
    public function instant_order_is_unchanged_zero_regression(): void
    {
        $this->injectSettings(0);
        $created = Carbon::now()->subMinutes(15);
        $order = $this->makeOrder([
            'scheduled'   => 0,
            'schedule_at' => Carbon::now(),
            'created_at'  => $created,
        ]);
        $start = NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_PROOF);
        $this->assertNotNull($start);
        $this->assertEquals(
            $created->toDateTimeString(), $start->toDateTimeString(),
            '即时单 clockStart 必须等于 created_at(零回归)'
        );
        $this->assertEquals(
            NezhaOrderTimeout::PHASE_PROOF, NezhaOrderTimeout::phase($order),
            '即时单 phase 必须为 PROOF(零回归)'
        );
    }

    /** @test isScheduled 判据: 仅 scheduled=1 且有 schedule_at 为真; 即时单恒 false(所有窗口锚定逻辑不触发)。 */
    public function is_scheduled_predicate(): void
    {
        $this->assertTrue(NezhaOrderTimeout::isScheduled(
            $this->makeOrder(['scheduled' => 1, 'schedule_at' => Carbon::now()->addDay()])
        ));
        $this->assertFalse(NezhaOrderTimeout::isScheduled($this->makeOrder(['scheduled' => 0])));
    }
}
