<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPaymentSnapshot;
use App\Http\Controllers\Api\V1\OrderController;
use App\Models\NezhaPaymentIntent;
use App\Models\OfflinePaymentMethod;
use App\Models\OfflinePayments;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 只用内存模型，不连接或写入数据库。
 */
class NezhaPaymentSnapshotTest extends TestCase
{
    public function test_snapshot_freezes_authoritative_amount_rates_networks_and_addresses(): void
    {
        $order = new Order();
        $order->forceFill(['order_amount' => 4000]);
        $restaurant = new Restaurant();
        $restaurant->forceFill([
            'name' => '测试商家',
            'usdt_address' => 'T-FROZEN-TRC20',
            'usdt_bep20_address' => '0xFROZENBEP20',
        ]);
        $restaurant->id = 9;
        $restaurant->setRelation('translations', collect());

        $trc = new OfflinePaymentMethod();
        $trc->forceFill([
            'method_name' => 'USDT · TRC20',
            'method_fields' => [['input_field_name' => '交易哈希', 'input_type' => 'text', 'is_required' => 1]],
        ]);
        $trc->id = 2;
        $bep = new OfflinePaymentMethod();
        $bep->forceFill([
            'method_name' => 'USDT · BEP20',
            'method_fields' => [['input_field_name' => '交易哈希', 'input_type' => 'text', 'is_required' => 1]],
        ]);
        $bep->id = 3;
        $frozenAt = Carbon::parse('2026-07-13 18:00:00', 'UTC');

        $snapshot = NezhaPaymentSnapshot::build(
            $order,
            $restaurant,
            collect([$trc, $bep]),
            ['cny_to_amd' => 50, 'usd_to_amd' => 400],
            $frozenAt,
            $frozenAt->copy()->addMinutes(10)
        );

        $this->assertSame(4000.0, $snapshot['order_amount_amd']);
        $this->assertSame(400.0, $snapshot['rates']['usd_to_amd']);
        $this->assertCount(2, $snapshot['methods']);
        $this->assertSame('TRC20', $snapshot['methods'][0]['network']);
        $this->assertSame('T-FROZEN-TRC20', $snapshot['methods'][0]['address']);
        $this->assertSame('BEP20', $snapshot['methods'][1]['network']);
        $this->assertSame('0xFROZENBEP20', $snapshot['methods'][1]['address']);
        $this->assertSame(10.0, $snapshot['methods'][1]['expected_amount']);

        $restaurant->usdt_address = 'T-CHANGED-LATER';
        $this->assertSame('T-FROZEN-TRC20', $snapshot['methods'][0]['address']);
    }

    public function test_snapshot_can_freeze_only_the_unpaid_partial_payment_amount(): void
    {
        $order = new Order();
        $order->forceFill(['order_amount' => 4000]);
        $restaurant = new Restaurant();
        $restaurant->forceFill([
            'name' => '测试商家',
            'usdt_address' => 'T-FROZEN-TRC20',
        ]);
        $restaurant->id = 9;
        $restaurant->setRelation('translations', collect());
        $method = new OfflinePaymentMethod();
        $method->forceFill(['method_name' => 'USDT · TRC20']);
        $method->id = 2;

        $snapshot = NezhaPaymentSnapshot::build(
            $order,
            $restaurant,
            collect([$method]),
            ['cny_to_amd' => 50, 'usd_to_amd' => 400],
            Carbon::parse('2026-07-13 18:00:00', 'UTC'),
            null,
            1200
        );

        $this->assertSame(1200.0, $snapshot['order_amount_amd']);
        $this->assertSame(3.0, $snapshot['methods'][0]['expected_amount']);
    }

    public function test_resubmission_check_uses_frozen_rate_instead_of_current_settings(): void
    {
        $snapshot = [
            'frozen_at' => '2026-07-13T18:00:00+00:00',
            'order_amount_amd' => 4000,
            'rates' => ['cny_to_amd' => 40, 'usd_to_amd' => 500],
            'methods' => [[
                'method_id' => 1,
                'method_name' => '支付宝',
                'kind' => 'alipay',
                'currency' => 'CNY',
                'expected_amd' => 4000,
                'expected_amount' => 100,
                'method_fields' => [],
            ]],
        ];
        $intent = new NezhaPaymentIntent();
        $intent->forceFill(['snapshot' => $snapshot, 'status' => 'prepared']);
        $intent->id = 77;
        $order = new Order();
        $order->forceFill(['order_amount' => 4000, 'restaurant_id' => 9]);
        $order->setRelation('nezha_payment_intent', $intent);
        $method = new OfflinePaymentMethod();
        $method->forceFill(['method_name' => '支付宝']);
        $method->id = 1;
        $payment = new OfflinePayments();
        $request = Request::create('/offline-payment-update', 'PUT', [
            'nezha_paid_amount' => '100',
        ]);

        $controller = new class extends OrderController {
            public function refreshForTest($payment, $order, $method, $request, $frozen): void
            {
                $this->refreshNezhaOfflinePaymentCheck(
                    $payment,
                    $order,
                    $method,
                    ['method_id' => 1, 'method_name' => '支付宝'],
                    $request,
                    $frozen
                );
            }
        };
        $controller->refreshForTest($payment, $order, $method, $request, $snapshot['methods'][0]);

        $actual = $payment->nezha_auto_check;
        $this->assertSame(77, $actual['payment_intent_id']);
        $this->assertEquals(40.0, $actual['rate_cny_to_amd']);
        $this->assertEquals(100.0, $actual['expected_rmb']);
        $this->assertTrue($actual['amount_match']);
    }

    public function test_failed_recheck_clears_stale_result(): void
    {
        $payment = new OfflinePayments();
        $payment->nezha_auto_check = ['tx_hash' => str_repeat('a', 64), 'chain' => ['status' => 'verified']];
        $order = new Order();
        $order->setRelation('nezha_payment_intent', null);
        $method = new OfflinePaymentMethod();
        $method->forceFill(['method_name' => '支付宝']);
        $request = Request::create('/offline-payment-update', 'PUT');

        $controller = new class extends OrderController {
            protected function buildNezhaOfflinePaymentCheck(
                Order $order,
                OfflinePaymentMethod $method,
                array $paymentInfo,
                Request $request,
                ?array $frozenMethod = null
            ): array {
                throw new \RuntimeException('synthetic recheck failure');
            }

            public function refreshForTest($payment, $order, $method, $request): void
            {
                $this->refreshNezhaOfflinePaymentCheck($payment, $order, $method, [], $request);
            }
        };
        $controller->refreshForTest($payment, $order, $method, $request);

        $this->assertNull($payment->nezha_auto_check);
    }
}
