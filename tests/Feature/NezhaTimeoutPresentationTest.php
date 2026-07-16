<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaOrderTimeout;
use App\Models\Order;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 订单信任批补充包：只锁定 describe() 展示文案和只读阈值投影。
 * 纯内存 Order + 反射注入阈值，不执行 sweep、不写订单。
 */
class NezhaTimeoutPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $settings = new \ReflectionProperty(NezhaOrderTimeout::class, 'settingsCache');
        $settings->setAccessible(true);
        $settings->setValue(null, [
            'status' => 1,
            'remind' => 6,
            'email_merchant' => 11,
            'unpaid_cancel' => 17,
            'cancel' => 23,
            'prep_orange' => 5,
            'prep_red' => 15,
            'handover' => 45,
            'picked' => 90,
            'preorder_lead' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $settings = new \ReflectionProperty(NezhaOrderTimeout::class, 'settingsCache');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unpaidOrder(int $elapsedMinutes): Order
    {
        $order = new Order();
        $order->forceFill([
            'order_status' => 'pending',
            'payment_method' => 'offline_payment',
            'order_type' => 'delivery',
            'scheduled' => 0,
            'pending' => Carbon::now()->subMinutes($elapsedMinutes),
            'created_at' => Carbon::now()->subMinutes($elapsedMinutes),
        ]);
        $order->setRelation('offline_payments', null);

        return $order;
    }

    public function test_describe_adds_dynamic_threshold_projection_and_unexpired_unpaid_copy(): void
    {
        $description = NezhaOrderTimeout::describe($this->unpaidOrder(3));

        $this->assertIsArray($description);
        $this->assertSame(6, $description['remind_min']);
        $this->assertSame(11, $description['email_merchant_min']);
        $this->assertSame(23, $description['cancel_min']);
        $this->assertSame(17, $description['unpaid_cancel_min']);
        $this->assertStringContainsString(
            '本单未完成付款凭证提交，已无法在订单内补交凭证；未付款无需操作，系统将在约 17 分钟后自动取消。如您已付款，请联系商家核实。',
            $description['next_step']
        );
    }

    public function test_describe_uses_expired_unpaid_copy_after_dynamic_threshold(): void
    {
        $description = NezhaOrderTimeout::describe($this->unpaidOrder(18));

        $this->assertIsArray($description);
        $this->assertStringContainsString(
            '本单未完成付款凭证提交且已超过 17 分钟时限，系统正在处理；如未自动取消，请稍候或联系客服。如您已付款，请联系商家核实。',
            $description['next_step']
        );
        $this->assertStringNotContainsString(
            '若 10 分钟内仍未完成付款，系统将自动取消本单。',
            $description['next_step']
        );
    }

    public function test_paid_timeout_copy_notifies_merchant_without_claiming_contact(): void
    {
        $order = new Order();
        $order->forceFill([
            'order_status' => 'confirmed',
            'payment_method' => 'offline_payment',
            'order_type' => 'delivery',
            'scheduled' => 0,
            'confirmed' => Carbon::now()->subMinutes(12),
            'created_at' => Carbon::now()->subMinutes(12),
        ]);

        $description = NezhaOrderTimeout::describe($order);

        $this->assertStringContainsString('通知商家原路退款', $description['next_step']);
        $this->assertStringNotContainsString('联系你原路退款', $description['next_step']);
    }
}
