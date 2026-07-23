<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaAtomicAmount;
use App\CentralLogics\NezhaCustomerRefundAddressCredentialService;
use App\CentralLogics\NezhaRefundControl;
use App\CentralLogics\NezhaRefundReconfirmationService;
use App\Models\NezhaCustomerRefundAddressCredential;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\NezhaRefundRecord;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NezhaRefundAddressBindingTest extends TestCase
{
    private const MERCHANT_TRC = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const REFUND_TRC = 'TJnwC8FcWiJQCQFzTYHxCj4DSW2iGwESVf';

    private const MERCHANT_BEP = '0x1111111111111111111111111111111111111111';

    private const REFUND_BEP = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->setSetting('nezha_payment_address_credential_status', '1');
        $this->setSetting('nezha_usdt_refund_binding_mode', 'enforce');
        $this->setSetting('nezha_usdt_refund_legal_gate', 'approved');
        $this->setSetting('nezha_refund_usdt_verify_status', '1');
        $this->setSetting('nezha_refund_bsc_finality_blocks', '12');
        $this->setSetting('nezha_refund_tron_finality_blocks', '20');
        $this->setSetting('nezha_sanction_screen_status', '1');
        $this->setSetting('nezha_refund_sanction_max_sync_age_hours', '48');
        $this->setSetting('nezha_sanction_last_sync', json_encode([
            'at' => now()->toDateTimeString(),
            'status' => 'ok',
            'total' => 1,
        ]));

        DB::table('restaurants')->insert([
            'id' => 20,
            'usdt_address' => self::MERCHANT_TRC,
            'usdt_bep20_address' => self::MERCHANT_BEP,
        ]);
        DB::table('offline_payment_methods')->insert([
            ['id' => 2, 'method_name' => 'USDT TRC20', 'status' => 1],
            ['id' => 3, 'method_name' => 'USDT BEP20', 'status' => 1],
        ]);
        DB::table('nezha_sanction_addresses')->insert([
            'addr_kind' => 'evm',
            'address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'source' => 'OFAC_SDN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'nezha_customer_refund_address_credentials',
            'nezha_payment_address_credentials',
            'nezha_refund_records',
            'offline_payments',
            'offline_payment_methods',
            'restaurants',
            'nezha_sanction_addresses',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('business_settings')->whereIn('key', [
            'nezha_payment_address_credential_status',
            'nezha_usdt_refund_binding_mode',
            'nezha_usdt_refund_legal_gate',
            'nezha_refund_reconfirm_ttl_seconds',
            'nezha_refund_bsc_finality_blocks',
            'nezha_refund_tron_finality_blocks',
            'nezha_sanction_screen_status',
            'nezha_sanction_last_sync',
            'nezha_refund_sanction_max_sync_age_hours',
        ])->delete();
        parent::tearDown();
    }

    public function test_refund_credential_is_customer_attested_and_cannot_equal_merchant_address(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );

        $this->assertSame('customer_attested', $issued['credential']->verification_status);
        $this->assertSame('refund-bound-v2', $issued['credential']->route_policy_version);
        $this->assertSame(self::REFUND_TRC, $issued['credential']->address_snapshot);
        $this->assertNull($issued['credential']->control_verified_at);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('refund_address_matches_merchant_receive_address');
        NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::MERCHANT_TRC,
            true
        );
    }

    public function test_new_usdt_requires_both_enforce_mode_and_approved_legal_gate(): void
    {
        $this->setSetting('nezha_usdt_refund_binding_mode', 'enforce');
        $this->setSetting('nezha_usdt_refund_legal_gate', 'pending');
        $this->assertFalse(NezhaCustomerRefundAddressCredentialService::acceptingNewPayments());

        $this->setSetting('nezha_usdt_refund_binding_mode', 'drain');
        $this->setSetting('nezha_usdt_refund_legal_gate', 'approved');
        $this->assertFalse(NezhaCustomerRefundAddressCredentialService::acceptingNewPayments());

        $this->setSetting('nezha_usdt_refund_binding_mode', 'enforce');
        $this->assertTrue(NezhaCustomerRefundAddressCredentialService::acceptingNewPayments());
    }

    public function test_customer_refund_binding_and_reconfirmation_routes_have_auth_guards(): void
    {
        $routes = file_get_contents(base_path('routes/api/v1/api.php'));
        $bindingController = file_get_contents(
            base_path('app/Http/Controllers/Api/V1/RefundAddressCredentialController.php')
        );

        $this->assertStringContainsString(
            "Route::post('customer/payment/refund-address-credential'",
            $routes
        );
        $this->assertStringContainsString(
            "Route::group(['prefix' => 'customer', 'middleware' => 'auth:api']",
            $routes
        );
        $this->assertStringContainsString(
            "Route::post('refund/reconfirm-challenge'",
            $routes
        );
        $this->assertStringContainsString(
            "Route::post('refund/reconfirm'",
            $routes
        );
        $this->assertStringContainsString('refund_address_login_required', $bindingController);
        $this->assertStringContainsString("Auth::guard('api')->user()", $bindingController);
    }

    public function test_refund_credential_must_match_user_order_method_network_and_is_single_use(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $resolved = NezhaCustomerRefundAddressCredentialService::resolveForProof(
            $issued['token'],
            10,
            20,
            2,
            900
        );
        $payment = $this->paymentCredential(10, 20, 2, 'TRC20', self::MERCHANT_TRC);

        NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
            $resolved,
            $payment,
            900,
            str_repeat('a', 64),
            'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            '12500000',
            '5000.00',
            'AMD'
        );

        $this->assertSame(900, $resolved->fresh()->consumed_order_id);
        $this->assertSame(900, $payment->fresh()->consumed_order_id);
        $this->assertSame('12500000', (string) $resolved->fresh()->paid_asset_amount_atomic);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('refund_credential_already_consumed');
        NezhaCustomerRefundAddressCredentialService::resolveForProof(
            $issued['token'],
            10,
            20,
            2,
            901
        );
    }

    public function test_pair_consumption_rolls_back_when_refund_credential_is_invalid(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $refund = $issued['credential'];
        $refund->method_id = 3;
        $refund->save();
        $payment = $this->paymentCredential(10, 20, 2, 'TRC20', self::MERCHANT_TRC);

        try {
            NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
                $refund,
                $payment,
                910,
                str_repeat('b', 64),
                null,
                '1000000',
                '400.00',
                'AMD'
            );
            $this->fail('The pair must fail atomically.');
        } catch (\DomainException $e) {
            $this->assertSame('refund_credential_binding_mismatch', $e->getMessage());
        }

        $this->assertNull($payment->fresh()->consumed_order_id);
        $this->assertNull($refund->fresh()->consumed_order_id);
    }

    public function test_consumed_payment_and_refund_evidence_cannot_be_replaced_on_retry(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $refund = NezhaCustomerRefundAddressCredentialService::resolveForProof(
            $issued['token'],
            10,
            20,
            2,
            911
        );
        $payment = $this->paymentCredential(10, 20, 2, 'TRC20', self::MERCHANT_TRC);
        $hash = str_repeat('c', 64);

        NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
            $refund,
            $payment,
            911,
            $hash,
            'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            '1000000',
            '400.00',
            'AMD'
        );

        try {
            NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
                $refund->fresh(),
                $payment->fresh(),
                911,
                $hash,
                'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                '2000000',
                '400.00',
                'AMD'
            );
            $this->fail('A retry must not replace the payment-time atomic amount');
        } catch (\DomainException $e) {
            $this->assertSame('refund_payment_evidence_changed', $e->getMessage());
        }

        $this->assertSame(
            '1000000',
            (string) $refund->fresh()->paid_asset_amount_atomic
        );
        $this->assertSame($hash, $payment->fresh()->submitted_tx_hash);
    }

    public function test_route_uses_bound_A_when_payment_from_is_B_and_modes_never_restore_B(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $payment = $this->paymentCredential(10, 20, 2, 'TRC20', self::MERCHANT_TRC);
        NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
            $issued['credential'],
            $payment,
            920,
            str_repeat('c', 64),
            self::MERCHANT_TRC,
            '1000000',
            '400.00',
            'AMD'
        );
        DB::table('offline_payments')->insert([
            'order_id' => 920,
            'payment_info' => json_encode([
                'method_id' => 2,
                'method_name' => 'USDT TRC20',
                'txid' => str_repeat('c', 64),
            ]),
        ]);
        $order = new Order();
        $order->id = 920;
        $order->payment_method = 'offline_payment';

        foreach (['enforce', 'drain'] as $mode) {
            $this->setSetting('nezha_usdt_refund_binding_mode', $mode);
            $route = NezhaRefundControl::lock_route($order);
            $this->assertSame(self::REFUND_TRC, $route['locked_to_address']);
            $this->assertSame(self::MERCHANT_TRC, $route['payment_from_address']);
            $this->assertNotSame($route['payment_from_address'], $route['locked_to_address']);
            $this->assertSame('bound', $route['route_status']);
        }

        $this->setSetting('nezha_usdt_refund_binding_mode', 'closed');
        $closed = NezhaRefundControl::lock_route($order);
        $this->assertSame(self::REFUND_TRC, $closed['locked_to_address']);
        $this->assertSame('refund_destination_hold', $closed['route_status']);
        $this->assertSame('refund_mode_closed', $closed['hold_reason']);
    }

    public function test_missing_binding_fails_closed_without_tx_from_fallback(): void
    {
        DB::table('offline_payments')->insert([
            'order_id' => 930,
            'payment_info' => json_encode([
                'method_id' => 2,
                'method_name' => 'USDT TRC20',
                'txid' => str_repeat('d', 64),
            ]),
        ]);
        $order = new Order();
        $order->id = 930;
        $order->payment_method = 'offline_payment';
        Http::fake();

        $route = NezhaRefundControl::lock_route($order);

        $this->assertNull($route['locked_to_address']);
        $this->assertSame('refund_destination_hold', $route['route_status']);
        $this->assertSame('legacy_rebind_required', $route['hold_reason']);
    }

    public function test_expired_or_revoked_refund_credential_is_never_consumable(): void
    {
        $expired = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $expired['credential']->expires_at = now()->subSecond();
        $expired['credential']->save();
        try {
            NezhaCustomerRefundAddressCredentialService::resolveForProof(
                $expired['token'],
                10,
                20,
                2,
                931
            );
            $this->fail('Expired credentials must fail closed.');
        } catch (\DomainException $e) {
            $this->assertSame('refund_credential_expired', $e->getMessage());
        }

        $revoked = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $revoked['credential']->revoked_at = now();
        $revoked['credential']->save();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('refund_credential_revoked');
        NezhaCustomerRefundAddressCredentialService::resolveForProof(
            $revoked['token'],
            10,
            20,
            2,
            932
        );
    }

    public function test_consumed_snapshot_ignores_new_credential_and_payment_info_addresses(): void
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $payment = $this->paymentCredential(10, 20, 2, 'TRC20', self::MERCHANT_TRC);
        NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential(
            $issued['credential'],
            $payment,
            933,
            str_repeat('3', 64),
            self::MERCHANT_TRC,
            '1000000',
            '400.00',
            'AMD'
        );
        DB::table('offline_payments')->insert([
            'order_id' => 933,
            'payment_info' => json_encode([
                'method_id' => 2,
                'method_name' => 'USDT TRC20',
                'txid' => str_repeat('4', 64),
                'refund_address' => 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                'destination' => self::MERCHANT_TRC,
            ]),
        ]);
        NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            true
        );

        $order = new Order();
        $order->id = 933;
        $order->payment_method = 'offline_payment';
        $route = NezhaRefundControl::lock_route($order);

        $this->assertSame(self::REFUND_TRC, $route['locked_to_address']);
        $this->assertNotSame(
            'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            $route['locked_to_address']
        );
    }

    public function test_sanction_match_or_unknown_destination_is_held_before_execution(): void
    {
        $credential = $this->consumedRefundCredential(934);
        DB::table('offline_payments')->insert([
            'order_id' => 934,
            'payment_info' => json_encode([
                'method_id' => 2,
                'method_name' => 'USDT TRC20',
                'txid' => str_repeat('5', 64),
            ]),
        ]);
        DB::table('nezha_sanction_addresses')->insert([
            'addr_kind' => 'tron',
            'address' => self::REFUND_TRC,
            'source' => 'OFAC_SDN',
            'sdn_uid' => 'test-hit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = new Order();
        $order->id = 934;
        $order->payment_method = 'offline_payment';

        $matched = NezhaRefundControl::lock_route($order);
        $this->assertSame('refund_destination_hold', $matched['route_status']);
        $this->assertSame(
            'refund_destination_sanction_match',
            $matched['hold_reason']
        );
        $this->assertSame('matched', $matched['destination_screening']['status']);

        DB::table('nezha_sanction_addresses')
            ->where('address', self::REFUND_TRC)
            ->delete();
        $this->setSetting('nezha_sanction_last_sync', json_encode([
            'at' => now()->subDays(3)->toDateTimeString(),
            'status' => 'ok',
            'total' => 1,
        ]));
        $unknown = NezhaRefundControl::lock_route($order);
        $this->assertSame(
            'refund_destination_sanction_unresolved',
            $unknown['hold_reason']
        );
        $this->assertSame('unresolved', $unknown['destination_screening']['status']);

        $this->assertSame($credential->address_snapshot, $unknown['locked_to_address']);
    }

    public function test_refund_amount_uses_exact_payment_time_atomic_snapshot(): void
    {
        $this->assertSame(
            '6250000',
            NezhaAtomicAmount::prorateFloor('12500000', '2500.00', '5000.00')
        );
        $this->assertSame(
            '12500000',
            NezhaAtomicAmount::prorateFloor('12500000', '9000.00', '5000.00')
        );
        $this->assertSame('12.5', NezhaAtomicAmount::atomicToDecimal('12500000', 6));

        $order = new Order();
        $order->setRawAttributes([
            'order_amount' => '9007199254740.99',
            'delivery_charge' => '0.01',
            'dm_tips' => '0.02',
            'additional_charge' => '0.03',
            'extra_packaging_amount' => '0.04',
        ]);
        $order->syncOriginal();
        $this->assertSame(
            '9007199254740.89',
            NezhaAtomicAmount::refundableAmdSnapshot($order),
            'Refundable currency math must not lose cents above float-safe precision.'
        );
    }

    public function test_manual_reconfirmation_is_scoped_single_use_and_cookie_only_cannot_pass(): void
    {
        $credential = $this->consumedRefundCredential(940);
        $record = $this->refundRecord($credential, [
            'order_id' => 940,
            'status' => 'awaiting_customer_reconfirm',
        ]);
        $user = new User();
        $user->forceFill([
            'id' => 10,
            'login_medium' => 'manual',
            'password' => Hash::make('correct-password'),
        ]);
        $request = Request::create('/refund/reconfirm', 'POST');
        $request->setUserResolver(fn () => $user);

        $challenge = NezhaRefundReconfirmationService::issueChallenge(
            $record,
            $user,
            $request
        );

        try {
            NezhaRefundReconfirmationService::confirm(
                $record->id,
                $user,
                $request,
                $challenge['challenge_token'],
                null
            );
            $this->fail('Cookie-only confirmation must not pass.');
        } catch (\DomainException $e) {
            $this->assertSame('fresh_auth_failed', $e->getMessage());
        }

        $confirmed = NezhaRefundReconfirmationService::confirm(
            $record->id,
            $user,
            $request,
            $challenge['challenge_token'],
            'correct-password'
        );
        $this->assertSame('pending_merchant_refund', $confirmed->status);
        $this->assertNotNull($confirmed->reconfirmed_at);

        $this->expectException(\DomainException::class);
        NezhaRefundReconfirmationService::confirm(
            $record->id,
            $user,
            $request,
            $challenge['challenge_token'],
            'correct-password'
        );
    }

    public function test_bep20_and_trc20_verifier_require_contract_target_atomic_amount_and_finality(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            if (str_contains($request->url(), '/v1/transactions/')) {
                return Http::response([
                    'data' => [[
                        'event_name' => 'Transfer',
                        'contract_address' => NezhaRefundControl::TRC_USDT,
                        'block_number' => 100,
                        'result' => [
                            'to' => self::REFUND_TRC,
                            'value' => '1000000',
                        ],
                    ]],
                ]);
            }
            if (str_contains($request->url(), '/wallet/getnowblock')) {
                return Http::response(['block_header' => ['raw_data' => ['number' => 130]]]);
            }
            $payload = $request->data();
            if (($payload['method'] ?? null) === 'eth_getTransactionReceipt') {
                return Http::response(['result' => [
                    'status' => '0x1',
                    'blockNumber' => '0x64',
                    'logs' => [[
                        'address' => NezhaRefundControl::BSC_USDT,
                        'topics' => [
                            NezhaRefundControl::TRANSFER_TOPIC,
                            '0x'.str_repeat('0', 64),
                            '0x'.str_repeat('0', 24).substr(self::REFUND_BEP, 2),
                        ],
                        'data' => '0xde0b6b3a7640000',
                    ]],
                ]]);
            }
            if (($payload['method'] ?? null) === 'eth_blockNumber') {
                return Http::response(['result' => '0x70']);
            }

            return Http::response(null, 404);
        });

        $bep = NezhaRefundControl::verify_refund_tx(
            '0x'.str_repeat('e', 64),
            'bsc',
            self::REFUND_BEP,
            '1000000000000000000',
            NezhaRefundControl::BSC_USDT,
            18,
            'exact'
        );
        $trc = NezhaRefundControl::verify_refund_tx(
            str_repeat('f', 64),
            'trc20',
            self::REFUND_TRC,
            '1000000',
            NezhaRefundControl::TRC_USDT,
            6,
            'exact'
        );
        $wrongAmount = NezhaRefundControl::verify_refund_tx(
            '0x'.str_repeat('e', 64),
            'bsc',
            self::REFUND_BEP,
            '999999999999999999',
            NezhaRefundControl::BSC_USDT,
            18,
            'exact'
        );

        $this->assertSame('verified', $bep['status']);
        $this->assertSame('1000000000000000000', $bep['detail']['amount_atomic']);
        $this->assertSame('verified', $trc['status']);
        $this->assertSame('failed', $wrongAmount['status']);
    }

    public function test_refund_hash_can_close_only_one_reconfirmed_record(): void
    {
        Http::fake(function (HttpClientRequest $request) {
            $payload = $request->data();
            if (($payload['method'] ?? null) === 'eth_getTransactionReceipt') {
                return Http::response(['result' => [
                    'status' => '0x1',
                    'blockNumber' => '0x64',
                    'logs' => [[
                        'address' => NezhaRefundControl::BSC_USDT,
                        'topics' => [
                            NezhaRefundControl::TRANSFER_TOPIC,
                            '0x'.str_repeat('0', 64),
                            '0x'.str_repeat('0', 24).substr(self::REFUND_BEP, 2),
                        ],
                        'data' => '0xde0b6b3a7640000',
                    ]],
                ]]);
            }
            if (($payload['method'] ?? null) === 'eth_blockNumber') {
                return Http::response(['result' => '0x70']);
            }

            return Http::response(null, 404);
        });
        $first = $this->refundRecord(null, [
            'order_id' => 950,
            'status' => 'pending_merchant_refund',
            'asset_network' => 'BEP20',
            'chain' => 'bsc',
            'locked_to_address' => self::REFUND_BEP,
            'asset_contract' => NezhaRefundControl::BSC_USDT,
            'asset_decimals' => 18,
            'refund_asset_amount_atomic' => '1000000000000000000',
            'reconfirmed_at' => now(),
        ]);
        $second = $this->refundRecord(null, [
            'order_id' => 951,
            'status' => 'pending_merchant_refund',
            'asset_network' => 'BEP20',
            'chain' => 'bsc',
            'locked_to_address' => self::REFUND_BEP,
            'asset_contract' => NezhaRefundControl::BSC_USDT,
            'asset_decimals' => 18,
            'refund_asset_amount_atomic' => '1000000000000000000',
            'reconfirmed_at' => now(),
        ]);
        $hash = '0x'.str_repeat('9', 64);

        $winner = NezhaRefundControl::verifyAndComplete($first, $hash, 20, 'done');
        $reused = NezhaRefundControl::verifyAndComplete($second, $hash, 20, 'reused');

        $this->assertSame('verified', $winner['status']);
        $this->assertSame('merchant_refunded', $winner['record']->status);
        $this->assertSame('failed', $reused['status']);
        $this->assertSame('refund_tx_hash_reused', $reused['reason']);
        $this->assertSame('pending_merchant_refund', $second->fresh()->status);
    }

    public function test_refund_completion_rechecks_current_destination_sanction_state(): void
    {
        $record = $this->refundRecord(null, [
            'order_id' => 952,
            'status' => 'pending_merchant_refund',
            'asset_network' => 'BEP20',
            'chain' => 'bsc',
            'locked_to_address' => self::REFUND_BEP,
            'asset_contract' => NezhaRefundControl::BSC_USDT,
            'asset_decimals' => 18,
            'refund_asset_amount_atomic' => '1000000000000000000',
            'reconfirmed_at' => now(),
        ]);
        $this->setSetting('nezha_sanction_last_sync', json_encode([
            'at' => now()->subDays(3)->toDateTimeString(),
            'status' => 'ok',
            'total' => 1,
        ]));

        $result = NezhaRefundControl::verifyAndComplete(
            $record,
            '0x'.str_repeat('8', 64),
            20,
            'must remain held'
        );

        $this->assertSame('manual_hold', $result['status']);
        $this->assertSame('refund_destination_sanction_unresolved', $result['reason']);
        $this->assertSame('pending_merchant_refund', $record->fresh()->status);
        $this->assertSame(
            'refund_destination_sanction_unresolved',
            $record->fresh()->hold_reason
        );
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('nezha_customer_refund_address_credentials');
        Schema::dropIfExists('nezha_payment_address_credentials');
        Schema::dropIfExists('nezha_refund_records');
        Schema::dropIfExists('offline_payments');
        Schema::dropIfExists('offline_payment_methods');
        Schema::dropIfExists('restaurants');
        Schema::dropIfExists('nezha_sanction_addresses');

        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->string('usdt_address')->nullable();
            $table->string('usdt_bep20_address')->nullable();
        });
        Schema::create('nezha_sanction_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('addr_kind', 12);
            $table->string('address', 128);
            $table->string('source', 32);
            $table->string('sdn_uid', 32)->nullable();
            $table->string('currency_type', 32)->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamp('last_seen_sync')->nullable();
            $table->timestamps();
            $table->unique(['addr_kind', 'address', 'source']);
        });
        Schema::create('offline_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('method_name');
            $table->boolean('status')->default(true);
        });
        Schema::create('offline_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->text('payment_info')->nullable();
            $table->string('status')->nullable();
        });
        Schema::create('nezha_payment_address_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('restaurant_id');
            $table->unsignedBigInteger('method_id');
            $table->string('network', 8);
            $table->text('address_snapshot');
            $table->char('address_fingerprint', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_order_id')->nullable()->unique();
            $table->text('submitted_tx_hash')->nullable();
            $table->char('submitted_tx_fingerprint', 64)->nullable()->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_refund_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('payment_channel', 20)->default('other');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('refund_amount', 24, 2)->default(0);
            $table->string('route_locked_note')->nullable();
            $table->string('chain', 16)->nullable();
            $table->string('original_tx_hash', 120)->nullable();
            $table->string('locked_to_address', 120)->nullable();
            $table->string('refund_tx_hash', 120)->nullable();
            $table->string('chain_verify_status', 20)->default('na');
            $table->json('chain_verify_detail')->nullable();
            $table->boolean('customer_confirmed')->default(false);
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->string('risk_action', 20)->default('pass');
            $table->string('status', 40)->default('recorded');
            $table->timestamp('merchant_refunded_at')->nullable();
            $table->string('merchant_refund_note')->nullable();
            $table->timestamps();
        });

        $migration = require database_path(
            'migrations/2026_07_23_130000_create_nezha_customer_refund_address_credentials.php'
        );
        $migration->up();
    }

    private function setSetting(string $key, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function paymentCredential(
        int $userId,
        int $restaurantId,
        int $methodId,
        string $network,
        string $address
    ): NezhaPaymentAddressCredential {
        $secret = str_repeat('1', 64);

        return NezhaPaymentAddressCredential::create([
            'public_id' => (string) Str::uuid(),
            'secret_hash' => hash('sha256', $secret),
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
            'method_id' => $methodId,
            'network' => $network,
            'address_snapshot' => $address,
            'address_fingerprint' => hash('sha256', $network.'|'.$address),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function consumedRefundCredential(int $orderId): NezhaCustomerRefundAddressCredential
    {
        $issued = NezhaCustomerRefundAddressCredentialService::issue(
            10,
            20,
            2,
            self::REFUND_TRC,
            true
        );
        $credential = $issued['credential'];
        $credential->consumed_order_id = $orderId;
        $credential->consumed_at = now();
        $credential->payment_tx_hash = str_repeat('8', 64);
        $credential->payment_from_address = self::MERCHANT_TRC;
        $credential->asset_contract = NezhaRefundControl::TRC_USDT;
        $credential->asset_decimals = 6;
        $credential->paid_asset_amount_atomic = '1000000';
        $credential->refundable_amd_snapshot = '400.00';
        $credential->order_currency_snapshot = 'AMD';
        $credential->save();

        return $credential;
    }

    private function refundRecord(
        ?NezhaCustomerRefundAddressCredential $credential,
        array $overrides = []
    ): NezhaRefundRecord {
        $defaults = [
            'order_id' => $credential?->consumed_order_id ?? 999,
            'restaurant_id' => 20,
            'user_id' => 10,
            'payment_channel' => 'usdt',
            'order_amount' => 400,
            'refund_amount' => 400,
            'status' => 'awaiting_customer_reconfirm',
            'chain' => 'trc20',
            'locked_to_address' => $credential?->address_snapshot ?? self::REFUND_TRC,
            'refund_address_credential_id' => $credential?->id,
            'route_policy_version' => 'refund-bound-v2',
            'verification_status' => 'customer_attested',
            'address_fingerprint' => $credential?->address_fingerprint
                ?? hash('sha256', 'BEP20|'.self::REFUND_BEP),
            'asset_network' => 'TRC20',
            'asset_contract' => NezhaRefundControl::TRC_USDT,
            'asset_decimals' => 6,
            'paid_asset_amount_atomic' => '1000000',
            'refund_asset_amount_atomic' => '1000000',
            'refundable_amd_snapshot' => '400.00',
            'order_currency_snapshot' => 'AMD',
            'chain_verify_status' => 'unverified',
        ];

        return NezhaRefundRecord::create(array_merge($defaults, $overrides));
    }
}
