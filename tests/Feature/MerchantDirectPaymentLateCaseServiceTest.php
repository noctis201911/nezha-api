<?php

namespace Tests\Feature;

use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy as Policy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCaseService;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentStrictUsdtVerifier as Verifier;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentUsdtObservationGateway;
use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\Http\Controllers\Admin\MerchantDirectPaymentLateCaseController as AdminLateCaseController;
use App\Http\Controllers\Api\V1\MerchantDirectPaymentLateCaseController;
use App\Models\MerchantDirectPaymentLateCase;
use App\Models\NezhaRefundRecord;
use App\Models\NezhaRefundRecordEvent;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MerchantDirectPaymentLateCaseServiceTest extends TestCase
{
    private const MERCHANT_BSC = '0x1111111111111111111111111111111111111111';

    private const CUSTOMER_BSC = '0x2222222222222222222222222222222222222222';

    private MerchantDirectPaymentLateCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Late-case tests may only run on SQLite :memory:.');
        }

        $this->createSchema();
        $this->service = app(MerchantDirectPaymentLateCaseService::class);
    }

    public function test_v2_casts_and_events_are_isolated_from_the_funds_refund_model(): void
    {
        $fundsCasts = (new NezhaRefundRecord)->getCasts();

        $this->assertArrayNotHasKey('late_payment_tx_hash', $fundsCasts);
        $this->assertArrayNotHasKey('late_refund_destination', $fundsCasts);
        $this->assertSame('encrypted', (new MerchantDirectPaymentLateCase)->getCasts()['late_payment_tx_hash']);
        $this->assertTrue(method_exists(MerchantDirectPaymentLateCase::class, 'events'));
        $this->assertFalse(method_exists(NezhaRefundRecord::class, 'events'));
    }

    public function test_report_is_idempotent_and_never_resurrects_the_timed_out_order(): void
    {
        $order = $this->order(701);
        $issued = NezhaPaymentAddressCredentialService::issue(1, 6, 31);

        $first = $this->service->report(
            $order,
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_repeat('a', 64),
            $issued['token'],
            'customer',
            1
        );
        $repeat = $this->service->report(
            $order->fresh(),
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_repeat('a', 64),
            $issued['token'],
            'customer',
            1
        );

        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(1, MerchantDirectPaymentLateCase::where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)->count());
        $this->assertSame(1, NezhaRefundRecordEvent::where('refund_record_id', $first->id)->count());
        $this->assertSame('canceled', $order->fresh()->order_status);
        $this->assertSame(701, $issued['credential']->fresh()->consumed_order_id);
    }

    public function test_funds_refund_identity_and_late_case_coexist_once_per_order(): void
    {
        $order = $this->order(709);
        $ordinary = NezhaRefundRecord::create([
            'order_id' => $order->id,
            'event_key' => "order:{$order->id}:refund",
            'restaurant_id' => 6,
            'user_id' => 1,
            'payment_channel' => 'usdt',
            'status' => 'pending_merchant_refund',
        ]);
        $issued = NezhaPaymentAddressCredentialService::issue(1, 6, 31);

        $case = $this->service->report(
            $order,
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_repeat('9', 64),
            $issued['token'],
            'customer',
            1
        );
        $repeat = $this->service->report(
            $order->fresh(),
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_repeat('9', 64),
            $issued['token'],
            'customer',
            1
        );

        $this->assertNotSame($ordinary->id, $case->id);
        $this->assertSame($case->id, $repeat->id);
        $this->assertSame(2, NezhaRefundRecord::where('order_id', $order->id)->count());
        $this->assertSame("order:{$order->id}:refund", $ordinary->fresh()->event_key);
        $this->assertNull($case->fresh()->event_key);
        $this->assertSame(MerchantDirectPaymentLateCaseService::SOURCE, $case->source_domain);
        $this->assertSame('canceled', $order->fresh()->order_status);
    }

    public function test_consumed_order_credential_is_reused_without_live_address_or_short_lived_token(): void
    {
        $order = $this->order(710);
        $issued = NezhaPaymentAddressCredentialService::issue(1, 6, 31);
        NezhaPaymentAddressCredentialService::consume($issued['credential'], 710, str_repeat('a', 64));

        $case = $this->service->report(
            $order,
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_repeat('a', 64),
            null,
            'customer',
            1
        );

        $this->assertSame($issued['credential']->id, $case->credential_id);
        $this->assertSame(710, $issued['credential']->fresh()->consumed_order_id);
    }

    public function test_duplicate_report_with_different_evidence_is_rejected(): void
    {
        $case = $this->reportedUsdtCase(711);

        try {
            $this->service->report(
                $case->order,
                Policy::CHANNEL_USDT_BEP20,
                31,
                Policy::WALLET_EXCHANGE,
                str_repeat('f', 64),
                null,
                'customer',
                1
            );
            $this->fail('Conflicting evidence was treated as an idempotent retry.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('late_payment_report_conflict', $exception->getMessage());
        }
    }

    public function test_service_rejects_customer_actor_who_does_not_own_order(): void
    {
        $order = $this->order(712);

        try {
            $this->service->report(
                $order,
                Policy::CHANNEL_ALIPAY,
                32,
                Policy::WALLET_SELF_CUSTODY,
                'ALIPAY-LATE-712',
                null,
                'customer',
                999
            );
            $this->fail('A different customer actor reported against the order.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('late_payment_order_owner_mismatch', $exception->getMessage());
        }
    }

    public function test_exchange_usdt_case_closes_only_after_exact_chain_verified_refund(): void
    {
        $case = $this->reportedUsdtCase(702);
        $case = $this->service->attributePayment(
            $case,
            '1200000',
            $this->bscObservation(self::MERCHANT_BSC, '1200000', 5, (string) $case->late_payment_tx_hash),
            'admin',
            1
        );
        $this->assertSame(Policy::STATE_REFUND_REQUIRED, $case->status);
        $this->assertNull($case->late_refund_destination);

        $case = $this->service->setRefundTerms($case, '1175000', self::CUSTOMER_BSC, 6, 1);
        $case = $this->service->submitRefund(
            $case,
            str_repeat('c', 64),
            $this->bscObservation(self::CUSTOMER_BSC, '1175000', 9, str_repeat('c', 64)),
            6,
            1,
            'Fee agreed with customer.'
        );

        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $case->status);
        $this->assertSame(Policy::EVIDENCE_CHAIN_VERIFIED, $case->evidence_authority);
        $this->assertSame('1175000', $case->refund_amount_atomic);
        $this->assertSame('merchant_contacts_customer_for_address', $case->refund_destination_source);
        $this->assertNotNull($case->closed_at);
        $this->assertSame('canceled', $case->order->order_status);
        $this->assertTrue($case->events()->where('event_type', 'usdt_refund_verified')->exists());
    }

    public function test_provider_unavailable_keeps_usdt_refund_pending_and_cannot_close(): void
    {
        $case = $this->reportedUsdtCase(703);
        $case = $this->service->attributePayment(
            $case,
            '1000000',
            $this->bscObservation(self::MERCHANT_BSC, '1000000', 1, (string) $case->late_payment_tx_hash),
            'admin',
            1
        );
        $case = $this->service->setRefundTerms($case, '1000000', self::CUSTOMER_BSC, 6, 1);
        $case = $this->service->submitRefund(
            $case,
            str_repeat('e', 64),
            ['provider_status' => 'unavailable', 'attested_transaction_hash' => str_repeat('e', 64)],
            6,
            1
        );

        $this->assertSame(Policy::STATE_USDT_REFUND_VERIFICATION_PENDING, $case->status);
        $this->assertNull($case->closed_at);
        $this->assertNull($case->evidence_authority);
        $this->assertSame('canceled', $case->order->order_status);
    }

    public function test_alipay_merchant_declaration_closes_and_customer_can_dispute_afterwards(): void
    {
        $order = $this->order(704);
        $case = $this->service->report(
            $order,
            Policy::CHANNEL_ALIPAY,
            32,
            Policy::WALLET_SELF_CUSTODY,
            'ALIPAY-LATE-704',
            null,
            'customer',
            1
        );
        $case = $this->service->attributePayment($case, '50000', [], 'merchant', 1);
        $case = $this->service->setRefundTerms($case, '49000', null, 6, 1);
        $case = $this->service->submitRefund($case, 'ALIPAY-REFUND-704', [], 6, 1);

        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $case->status);
        $this->assertSame(Policy::EVIDENCE_MERCHANT_DECLARED, $case->evidence_authority);
        $this->assertSame('canceled', $case->order->order_status);

        try {
            $this->service->disputeClosedRefund($case, 'customer', 999, 'Not mine.');
            $this->fail('Another customer disputed the case.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('late_payment_case_owner_mismatch', $exception->getMessage());
        }

        $case = $this->service->disputeClosedRefund($case, 'customer', 1, 'Not received.');
        $this->assertSame(Policy::STATE_DISPUTED, $case->status);
        $this->assertSame('canceled', $case->order->order_status);
    }

    public function test_restaurant_scope_and_ordinary_refund_rows_cannot_enter_v2_mutations(): void
    {
        $case = $this->reportedUsdtCase(705);
        $case = $this->service->attributePayment(
            $case,
            '100',
            $this->bscObservation(self::MERCHANT_BSC, '100', 1, (string) $case->late_payment_tx_hash),
            'admin',
            1
        );
        try {
            $this->service->setRefundTerms($case, '100', self::CUSTOMER_BSC, 999, 1);
            $this->fail('Another restaurant changed the case.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('late_payment_case_restaurant_mismatch', $exception->getMessage());
        }

        $ordinary = NezhaRefundRecord::create([
            'order_id' => 705,
            'restaurant_id' => 6,
            'payment_channel' => 'rmb',
            'status' => 'pending_merchant_refund',
        ]);
        try {
            $this->service->setRefundTerms($ordinary, '1', null, 6, 1);
            $this->fail('An ordinary refund entered the V2 state machine.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('not_a_direct_payment_late_v2_case', $exception->getMessage());
        }
    }

    public function test_database_and_model_both_reject_event_rewrite_or_delete(): void
    {
        $event = $this->reportedUsdtCase(706)->events()->firstOrFail();
        try {
            $event->event_type = 'rewritten';
            $event->save();
            $this->fail('Model permitted event rewrite.');
        } catch (\LogicException $exception) {
            $this->assertSame('refund_record_events_are_append_only', $exception->getMessage());
        }

        try {
            DB::table('nezha_refund_record_events')->where('id', $event->id)->delete();
            $this->fail('Database permitted event deletion.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_customer_controller_hides_another_customers_case_and_routes_keep_expected_owners(): void
    {
        $case = $this->reportedUsdtCase(707);
        $request = Request::create('/api/v1/customer/order/late-payment/'.$case->case_public_id, 'GET');
        $request->setUserResolver(static fn () => (object) ['id' => 999]);
        $response = (new MerchantDirectPaymentLateCaseController($this->service))->show(
            $request,
            (string) $case->case_public_id
        );
        $this->assertSame(404, $response->getStatusCode());

        $routes = $this->app['router']->getRoutes();
        $customer = $routes->match(Request::create('/api/v1/customer/order/late-payment/report', 'POST'));
        $this->assertSame(MerchantDirectPaymentLateCaseController::class.'@report', $customer->getActionName());
        $this->assertContains('apiGuestCheck', $customer->gatherMiddleware());

        $lookup = $routes->match(Request::create('/api/v1/customer/order/late-payment/order/707', 'GET'));
        $this->assertSame(MerchantDirectPaymentLateCaseController::class.'@lookup', $lookup->getActionName());

        $vendor = $routes->match(Request::create('/restaurant-panel/order/late-payment/case-id/submit-refund', 'PUT'));
        $this->assertSame(
            \App\Http\Controllers\Vendor\MerchantDirectPaymentLateCaseController::class.'@submitRefund',
            $vendor->getActionName()
        );
        $this->assertContains('module:regular_order', $vendor->gatherMiddleware());
        $vendorIndex = $routes->match(Request::create('/restaurant-panel/order/late-payment', 'GET'));
        $this->assertSame(
            \App\Http\Controllers\Vendor\MerchantDirectPaymentLateCaseController::class.'@index',
            $vendorIndex->getActionName()
        );

        $admin = $routes->match(Request::create('/admin/nezha-refund/late-payment/case-id/retry-usdt', 'POST'));
        $this->assertSame(
            \App\Http\Controllers\Admin\MerchantDirectPaymentLateCaseController::class.'@retryUsdt',
            $admin->getActionName()
        );
        $this->assertContains('module:refund', $admin->gatherMiddleware());
        $adminIndex = $routes->match(Request::create('/admin/nezha-refund/late-payment', 'GET'));
        $this->assertSame(
            \App\Http\Controllers\Admin\MerchantDirectPaymentLateCaseController::class.'@index',
            $adminIndex->getActionName()
        );
    }

    public function test_staff_projection_exposes_structured_evidence_without_credential_secret(): void
    {
        $case = $this->reportedUsdtCase(713);
        $projection = $this->service->staffProjection($case);

        $this->assertSame((string) $case->late_payment_tx_hash, $projection['payment_reference']);
        $this->assertSame('late_payment_reported', $projection['events'][0]['type']);
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret_hash', $encoded);
        $this->assertStringNotContainsString('credential_token', $encoded);
    }

    public function test_guest_lookup_requires_matching_order_contact_number(): void
    {
        $order = new Order;
        $order->forceFill([
            'id' => 714,
            'restaurant_id' => 6,
            'user_id' => 44,
            'is_guest' => true,
            'payment_method' => 'offline_payment',
            'payment_status' => 'unpaid',
            'order_status' => 'canceled',
            'order_amount' => 1000,
            'delivery_address' => json_encode(['contact_person_number' => '+37499123456']),
            'canceled' => now(),
        ])->save();
        $case = $this->service->report(
            $order->fresh(),
            Policy::CHANNEL_ALIPAY,
            32,
            Policy::WALLET_SELF_CUSTODY,
            'ALIPAY-LATE-714',
            null,
            'guest',
            44
        );
        $controller = new MerchantDirectPaymentLateCaseController($this->service);
        $wrong = Request::create('/api/v1/customer/order/late-payment/order/714', 'GET', [
            'guest_id' => 44,
            'contact_number' => '+37400000000',
        ]);
        $right = Request::create('/api/v1/customer/order/late-payment/order/714', 'GET', [
            'guest_id' => 44,
            'contact_number' => '37499123456',
        ]);

        $this->assertSame(404, $controller->lookup($wrong, 714)->getStatusCode());
        $response = $controller->lookup($right, 714);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($case->case_public_id, $response->getData(true)['data']['case_id']);
    }

    public function test_disabled_switch_fails_closed_before_any_case_write(): void
    {
        DB::table('business_settings')->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)->update(['value' => '0']);
        $order = $this->order(708);

        try {
            $this->service->report(
                $order,
                Policy::CHANNEL_ALIPAY,
                32,
                Policy::WALLET_SELF_CUSTODY,
                'ALIPAY-LATE-708',
                null,
                'customer',
                1
            );
            $this->fail('Disabled switch allowed a case write.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('direct_payment_late_v2_disabled', $exception->getMessage());
        }

        $this->assertSame(0, NezhaRefundRecord::where('order_id', 708)->count());
        $this->assertSame('canceled', $order->fresh()->order_status);
    }

    public function test_admin_controller_never_calls_chain_provider_when_disabled_or_state_is_not_reviewable(): void
    {
        $case = $this->reportedUsdtCase(713);
        $gateway = new class implements MerchantDirectPaymentUsdtObservationGateway
        {
            public function observe(string $channel, string $transactionHash): array
            {
                throw new \LogicException('Chain provider must not be called for a disabled or ineligible case.');
            }
        };
        $controller = new AdminLateCaseController($this->service, $gateway);
        DB::table('business_settings')
            ->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)
            ->update(['value' => '0']);

        $disabled = $controller->attribute(
            Request::create('/', 'POST', ['received_amount_atomic' => '100']),
            (string) $case->case_public_id
        );
        $this->assertSame(422, $disabled->getStatusCode());

        DB::table('business_settings')
            ->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)
            ->update(['value' => '1']);
        $case->status = Policy::STATE_CLOSED_NO_PAYMENT;
        $case->save();
        $notReviewable = $controller->attribute(
            Request::create('/', 'POST', ['received_amount_atomic' => '100']),
            (string) $case->case_public_id
        );
        $this->assertSame(422, $notReviewable->getStatusCode());
    }

    public function test_expired_manual_screenshot_is_purged_without_deleting_late_case_facts(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('late-payment/alipay-proof.png', 'synthetic image');
        $case = MerchantDirectPaymentLateCase::create([
            'order_id' => 709,
            'restaurant_id' => 6,
            'user_id' => 1,
            'source_domain' => MerchantDirectPaymentLateCaseService::SOURCE,
            'case_public_id' => 'case-retention-709',
            'payment_channel' => Policy::CHANNEL_ALIPAY,
            'status' => Policy::STATE_CLOSED_REFUNDED,
            'evidence_authority' => Policy::EVIDENCE_MERCHANT_DECLARED,
            'refund_proof_image' => 'late-payment/alipay-proof.png',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->artisan('nezha:purge-payment-proofs')->assertExitCode(0);

        Storage::disk('public')->assertMissing('late-payment/alipay-proof.png');
        $case = $case->fresh();
        $this->assertNotNull($case);
        $this->assertNull($case->refund_proof_image);
        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $case->status);
        $this->assertSame(Policy::EVIDENCE_MERCHANT_DECLARED, $case->evidence_authority);
    }

    private function reportedUsdtCase(int $orderId): NezhaRefundRecord
    {
        $order = $this->order($orderId);
        $issued = NezhaPaymentAddressCredentialService::issue(1, 6, 31);

        return $this->service->report(
            $order,
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            str_pad(dechex($orderId), 64, 'a', STR_PAD_LEFT),
            $issued['token'],
            'customer',
            1
        );
    }

    private function order(int $id): Order
    {
        $order = new Order;
        $order->forceFill([
            'id' => $id,
            'restaurant_id' => 6,
            'user_id' => 1,
            'is_guest' => false,
            'payment_method' => 'offline_payment',
            'payment_status' => 'unpaid',
            'order_status' => 'canceled',
            'order_amount' => 1000,
            'canceled' => now(),
        ])->save();

        return Order::whereKey($id)->firstOrFail();
    }

    private function bscObservation(string $to, string $amount, int $eventIndex, string $hash): array
    {
        return [
            'provider_status' => 'ok',
            'receipt_status' => 'success',
            'finalized_block_number' => '100',
            'attested_transaction_hash' => $hash,
            'events' => [[
                'event_index' => $eventIndex,
                'contract' => Verifier::BSC_USDT,
                'from' => self::MERCHANT_BSC,
                'to' => $to,
                'amount_atomic' => $amount,
                'block_number' => '100',
            ]],
        ];
    }

    private function createSchema(): void
    {
        foreach (['nezha_refund_record_events', 'nezha_refund_records', 'nezha_payment_address_credentials', 'offline_payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['payment_method', 'payment_status', 'is_guest', 'order_amount', 'delivery_address'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'is_guest' => $table->boolean($column)->default(false),
                        'order_amount' => $table->decimal($column, 24, 2)->default(0),
                        'delivery_address' => $table->json($column)->nullable(),
                        default => $table->string($column)->nullable(),
                    };
                });
            }
        }
        foreach (['usdt_address', 'usdt_bep20_address'] as $column) {
            if (! Schema::hasColumn('restaurants', $column)) {
                Schema::table('restaurants', fn (Blueprint $table) => $table->string($column)->nullable());
            }
        }

        Schema::create('offline_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('method_name');
            $table->integer('status')->default(1);
            $table->text('method_fields')->nullable();
            $table->text('method_informations')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_refund_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('event_key')->nullable()->unique('funds_refund_records_event_key_unique');
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('guest_id', 100)->nullable();
            $table->string('payment_channel', 20)->default('other');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('refund_amount', 24, 2)->default(0);
            $table->string('reason_category', 30)->nullable();
            $table->string('reason_note')->nullable();
            $table->string('route_locked_note')->nullable();
            $table->string('chain', 16)->nullable();
            $table->string('original_tx_hash', 120)->nullable();
            $table->string('locked_to_address', 120)->nullable();
            $table->string('refund_tx_hash', 120)->nullable();
            $table->string('chain_verify_status', 20)->default('na');
            $table->json('chain_verify_detail')->nullable();
            $table->string('refund_proof_image')->nullable();
            $table->boolean('customer_confirmed')->default(false);
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->string('risk_action', 20)->default('pass');
            $table->json('risk_hit')->nullable();
            $table->string('status', 40)->default('recorded');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('merchant_refunded_at')->nullable();
            $table->string('merchant_refund_note')->nullable();
            $table->timestamp('overdue_anchor_at')->nullable();
            $table->timestamps();
        });

        $credentialMigration = require database_path('migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php');
        $credentialMigration->up();
        $lateCaseMigration = require database_path('migrations/2026_07_19_180000_add_direct_payment_late_cases_to_nezha_refunds.php');
        $lateCaseMigration->up();

        DB::table('business_settings')->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)->update(['value' => '1']);
        DB::table('business_settings')->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)->update(['value' => '1']);
        DB::table('restaurants')->where('id', 6)->update([
            'usdt_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'usdt_bep20_address' => self::MERCHANT_BSC,
        ]);
        DB::table('offline_payment_methods')->insert([
            ['id' => 31, 'method_name' => 'USDT (BEP20)', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 32, 'method_name' => 'Alipay 支付宝', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
