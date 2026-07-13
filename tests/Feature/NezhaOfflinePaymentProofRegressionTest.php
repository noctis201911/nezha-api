<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\OrderController;
use App\Models\OfflinePaymentMethod;
use App\Models\OfflinePayments;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 顾客直付凭证回归：重交不得沿用旧核验；90 天只清短期 PII，不清链上证据快照。
 *
 * 本仓测试仍连接生产 MySQL，所有写入均包在 DatabaseTransactions 中回滚。
 * purge 用 17000 天保留期 + 1971 合成行，确保不会命中任何真实业务行或真实文件。
 */
class NezhaOfflinePaymentProofRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_refresh_replaces_old_usdt_result_after_resubmission(): void
    {
        $restaurantId = (int) DB::table('restaurants')->value('id');
        $userId = (int) DB::table('users')->value('id');
        if ($restaurantId < 1 || $userId < 1) {
            $this->markTestSkipped('No restaurant/user row available for FK-safe regression fixture.');
        }
        $methodId = (int) DB::table('offline_payment_methods')->insertGetId([
            'method_name' => 'USDT · TRC20 regression',
            'method_fields' => json_encode([[
                'input_field_name' => 'transaction_hash',
                'input_type' => 'text',
                'is_required' => 1,
            ]]),
            'method_informations' => json_encode([]),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'is_guest' => 1,
            'restaurant_id' => $restaurantId,
            'restaurant_discount_amount' => 0,
            'order_amount' => 4000,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = (int) DB::table('offline_payments')->insertGetId([
            'order_id' => $orderId,
            'payment_info' => json_encode([
                'method_id' => $methodId,
                'method_name' => 'USDT · TRC20 regression',
                'transaction_hash' => str_repeat('a', 64),
            ]),
            'nezha_auto_check' => json_encode([
                'tx_hash' => str_repeat('a', 64),
                'chain' => ['status' => 'verified', 'actual_amount' => 99],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = Request::create('/offline-payment-update', 'PUT', [
            'order_id' => $orderId,
            'guest_id' => $userId,
            'transaction_hash' => 'new-invalid-hash',
            'nezha_paid_amount' => '10',
        ]);

        $response = (new OrderController())->update_offline_payment_info($request);

        $this->assertSame(200, $response->getStatusCode());
        $payment = OfflinePayments::findOrFail($paymentId);
        $actual = $payment->nezha_auto_check;
        $this->assertNull($actual['tx_hash']);
        $this->assertSame('invalid_hash', $actual['chain']['status']);
        $this->assertNotSame(str_repeat('a', 64), $actual['tx_hash']);
        $this->assertEquals(10.0, $actual['paid_amount']);
        $this->assertSame('pending', $payment->status);
    }

    public function test_shared_check_keeps_fiat_amount_and_image_soft_flags(): void
    {
        $rate = (float) (DB::table('business_settings')
            ->where('key', 'nezha_rate_cny_to_amd')
            ->value('value') ?: 55);
        $payment = new OfflinePayments();
        $order = new Order([
            'order_amount' => $rate * 100,
            'restaurant_id' => 1,
        ]);
        $method = new OfflinePaymentMethod(['method_name' => '支付宝']);
        $request = Request::create('/offline-payment-update', 'PUT', [
            'nezha_paid_amount' => '100',
            'nezha_image_flags' => json_encode(['blurry' => true]),
        ]);

        $this->refreshCheck(
            $payment,
            $order,
            $method,
            ['method_id' => 1, 'method_name' => '支付宝', '付款截图' => 'offline_payment/proof.png'],
            $request
        );

        $actual = $payment->nezha_auto_check;
        $this->assertTrue($actual['amount_match']);
        $this->assertEquals(100.0, $actual['paid_amount']);
        $this->assertTrue($actual['image_flags']['blurry']);
        $this->assertArrayNotHasKey('chain', $actual);
    }

    public function test_payment_proof_purge_preserves_chain_evidence_snapshot(): void
    {
        $restaurantId = (int) DB::table('restaurants')->value('id');
        if ($restaurantId < 1) {
            $this->markTestSkipped('No restaurant row available for FK-safe regression fixture.');
        }

        $this->setSetting('nezha_payment_proof_retention_days', 17000);
        $orderId = (int) DB::table('orders')->insertGetId([
            'restaurant_id' => $restaurantId,
            'restaurant_discount_amount' => 0,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $txHash = str_repeat('b', 64);
        $paymentId = (int) DB::table('offline_payments')->insertGetId([
            'order_id' => $orderId,
            'payment_info' => json_encode([
                'method_id' => 2,
                'method_name' => 'USDT · TRC20',
                '交易哈希' => $txHash,
            ]),
            'method_fields' => json_encode([['input_field_name' => '交易哈希', 'input_type' => 'text']]),
            'customer_note' => 'short-lived customer note',
            'nezha_auto_check' => json_encode([
                'tx_hash' => $txHash,
                'usdt_network' => 'TRC20',
                'chain' => [
                    'status' => 'verified',
                    'actual_to' => 'TChainEvidenceAddress',
                    'actual_amount' => 10,
                ],
            ]),
            'created_at' => '1971-01-02 00:00:00',
            'updated_at' => '1971-01-02 00:00:00',
        ]);

        $this->assertSame(0, Artisan::call('nezha:purge-payment-proofs'));

        $row = DB::table('offline_payments')->where('id', $paymentId)->first();
        $this->assertNull($row->payment_info);
        $this->assertNull($row->method_fields);
        $this->assertNull($row->customer_note);
        $evidence = json_decode($row->nezha_auto_check, true);
        $this->assertSame($txHash, $evidence['tx_hash']);
        $this->assertSame('TRC20', $evidence['usdt_network']);
        $this->assertSame('verified', $evidence['chain']['status']);
        $this->assertSame('TChainEvidenceAddress', $evidence['chain']['actual_to']);
        $this->assertSame(10, $evidence['chain']['actual_amount']);
        $this->assertTrue(
            $this->hashWasReused($txHash, 0),
            'payment_info 到期清除后仍须用长期链上证据阻止 TxID 重复冒认'
        );
    }

    private function setSetting(string $key, $value): void
    {
        if (DB::table('business_settings')->where('key', $key)->exists()) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
            return;
        }

        DB::table('business_settings')->insert([
            'key' => $key,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refreshCheck(
        OfflinePayments $payment,
        Order $order,
        OfflinePaymentMethod $method,
        array $paymentInfo,
        Request $request
    ): void {
        $controller = new class extends OrderController {
            public function refreshForRegressionTest(
                OfflinePayments $payment,
                Order $order,
                OfflinePaymentMethod $method,
                array $paymentInfo,
                Request $request
            ): void {
                $this->refreshNezhaOfflinePaymentCheck($payment, $order, $method, $paymentInfo, $request);
            }
        };

        $controller->refreshForRegressionTest($payment, $order, $method, $paymentInfo, $request);
    }

    private function hashWasReused(string $hash, int $currentOrderId): bool
    {
        $controller = new class extends OrderController {
            public function hashWasReusedForRegressionTest(string $hash, int $currentOrderId): bool
            {
                return $this->nezhaOfflinePaymentHashWasReused($hash, $currentOrderId);
            }
        };

        return $controller->hashWasReusedForRegressionTest($hash, $currentOrderId);
    }
}
