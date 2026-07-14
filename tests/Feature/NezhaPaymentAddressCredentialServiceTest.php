<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\Http\Controllers\Api\V1\PaymentAddressCredentialController;
use App\Models\NezhaPaymentAddressCredential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 本测试必须以 DB_CONNECTION=sqlite、DB_DATABASE=:memory: 运行。
 * setUp 会拒绝其它连接，杜绝项目默认测试配置误连生产 MySQL。
 */
class NezhaPaymentAddressCredentialServiceTest extends TestCase
{
    private const TRON_ADDRESS = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const BEP_ADDRESS_A = '0x55d398326f99059fF775485246999027B3197955';

    private const BEP_ADDRESS_B = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('安全中止：本测试只允许在 SQLite :memory: 运行');
        }

        $this->createSchema();
    }

    public function test_disabled_switch_cannot_issue_and_writes_nothing(): void
    {
        $this->seedBase('0');

        try {
            NezhaPaymentAddressCredentialService::issue(10, 20, 30);
            $this->fail('关闭总闸时不得签发凭据');
        } catch (\DomainException $e) {
            $this->assertSame('credential_feature_disabled', $e->getMessage());
        }

        $this->assertSame(0, DB::table('nezha_payment_address_credentials')->count());
    }

    public function test_migration_is_additive_and_seeds_a_disabled_switch(): void
    {
        Schema::dropIfExists('nezha_payment_address_credentials');
        DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)
            ->delete();

        $migration = require database_path(
            'migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('nezha_payment_address_credentials'));
        $this->assertTrue(Schema::hasColumns('nezha_payment_address_credentials', [
            'public_id',
            'secret_hash',
            'address_snapshot',
            'address_fingerprint',
            'consumed_order_id',
            'submitted_tx_hash',
        ]));
        $this->assertSame(
            '0',
            (string) DB::table('business_settings')
                ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)
                ->value('value')
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('nezha_payment_address_credentials'));
        $this->assertSame(1, DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)->count());
    }

    public function test_credential_migration_is_idempotent_without_a_unique_setting_key(): void
    {
        Schema::dropIfExists('nezha_payment_address_credentials');
        DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)
            ->delete();

        $migration = require database_path(
            'migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php'
        );
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)->count());
        $this->assertSame('0', (string) DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)->value('value'));
    }

    public function test_credential_migration_rollback_detects_any_enabled_duplicate(): void
    {
        DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)
            ->delete();
        DB::table('business_settings')->insert([
            ['key' => NezhaPaymentAddressCredentialService::SWITCH_KEY, 'value' => '0'],
            ['key' => NezhaPaymentAddressCredentialService::SWITCH_KEY, 'value' => '1'],
        ]);
        $migration = require database_path(
            'migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('enabled');
        $migration->down();
    }

    public function test_credential_migration_refuses_to_drop_non_empty_evidence(): void
    {
        $this->seedBase('0');
        DB::table('nezha_payment_address_credentials')->insert([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'secret_hash' => str_repeat('a', 64),
            'user_id' => 10,
            'restaurant_id' => 20,
            'method_id' => 30,
            'network' => 'TRC20',
            'address_snapshot' => 'encrypted-evidence',
            'address_fingerprint' => str_repeat('b', 64),
            'issued_at' => now(),
            'expires_at' => now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path(
            'migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php'
        );

        try {
            $migration->down();
            $this->fail('非空凭据证据表不得被普通 migration rollback 删除');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non-empty', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('nezha_payment_address_credentials'));
    }

    public function test_issue_only_writes_credential_ledger_and_never_creates_order_side_effects(): void
    {
        $this->seedBase('1');
        DB::table('orders')->insert(['id' => 501]);
        DB::table('carts')->insert(['id' => 601]);
        DB::table('coupon_uses')->insert(['id' => 701]);
        $before = $this->sideEffectCounts();

        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 30);
        $credential = $issued['credential'];

        $this->assertSame($before, $this->sideEffectCounts());
        $this->assertSame(1, DB::table('nezha_payment_address_credentials')->count());
        $this->assertStringStartsWith((string) $credential->public_id.'.', $issued['token']);
        $this->assertSame(self::TRON_ADDRESS, $credential->address_snapshot);

        $raw = DB::table('nezha_payment_address_credentials')->where('id', $credential->id)->first();
        $this->assertStringNotContainsString(self::TRON_ADDRESS, (string) $raw->address_snapshot);
        $this->assertStringNotContainsString(substr($issued['token'], 37), (string) $raw->secret_hash);
    }

    public function test_invalid_current_address_is_rejected_before_ledger_write(): void
    {
        $this->seedBase('1', substr(self::TRON_ADDRESS, 0, -1).'u');

        try {
            NezhaPaymentAddressCredentialService::issue(10, 20, 30);
            $this->fail('校验和错误的 TRC20 地址不得签发');
        } catch (\DomainException $e) {
            $this->assertSame('credential_address_invalid', $e->getMessage());
        }

        $this->assertSame(0, DB::table('nezha_payment_address_credentials')->count());
    }

    public function test_enabled_change_switch_requires_an_active_matching_network_state(): void
    {
        $this->seedBase('1');
        DB::table('business_settings')->insert([
            'key' => NezhaPaymentAddressChangeService::SWITCH_KEY,
            'value' => '1',
        ]);

        try {
            NezhaPaymentAddressCredentialService::issue(10, 20, 30);
            $this->fail('状态机开启但网络状态未初始化时不得签发');
        } catch (\DomainException $e) {
            $this->assertSame('credential_network_unavailable', $e->getMessage());
        }

        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'TRC20');
        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 30);
        $this->assertSame(self::TRON_ADDRESS, $issued['credential']->address_snapshot);

        DB::table('nezha_payment_network_states')
            ->where('restaurant_id', 20)
            ->where('network', 'TRC20')
            ->update(['state' => 'paused']);
        try {
            NezhaPaymentAddressCredentialService::issue(10, 20, 30);
            $this->fail('暂停网络不得签发新凭据');
        } catch (\DomainException $e) {
            $this->assertSame('credential_network_unavailable', $e->getMessage());
        }
    }

    public function test_authenticated_issue_endpoint_is_registered_and_has_no_order_side_effects(): void
    {
        $this->seedBase('1');
        $route = $this->app['router']->getRoutes()->match(
            Request::create('/api/v1/customer/payment/address-credential', 'POST')
        );
        $this->assertSame(
            PaymentAddressCredentialController::class.'@store',
            $route->getActionName()
        );

        $request = Request::create('/api/v1/customer/payment/address-credential', 'POST', [
            'restaurant_id' => 20,
            'method_id' => 30,
        ]);
        $request->setUserResolver(static fn () => (object) ['id' => 10]);
        $response = (new PaymentAddressCredentialController)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(self::TRON_ADDRESS, $payload['address']);
        $this->assertSame('TRC20', $payload['network']);
        $this->assertArrayHasKey('credential_token', $payload);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('carts')->count());
        $this->assertSame(0, DB::table('coupon_uses')->count());
    }

    public function test_guest_gets_disabled_compatibility_code_but_cannot_issue_when_enabled(): void
    {
        $this->seedBase('0');
        $request = Request::create('/api/v1/customer/payment/address-credential', 'POST', [
            'restaurant_id' => 20,
            'method_id' => 30,
        ]);

        $disabled = (new PaymentAddressCredentialController)->store($request);
        $this->assertSame(403, $disabled->getStatusCode());
        $this->assertSame(
            'payment_address_credential_disabled',
            $disabled->getData(true)['errors'][0]['code']
        );
        $this->assertSame(0, NezhaPaymentAddressCredential::count());

        DB::table('business_settings')
            ->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)
            ->update(['value' => '1']);
        $guard = \Mockery::mock();
        $guard->shouldReceive('user')->once()->andReturn(null);
        Auth::shouldReceive('guard')->once()->with('api')->andReturn($guard);
        $enabled = (new PaymentAddressCredentialController)->store($request);
        $this->assertSame(401, $enabled->getStatusCode());
        $this->assertSame(
            'address_credential_login_required',
            $enabled->getData(true)['errors'][0]['code']
        );
        $this->assertSame(0, NezhaPaymentAddressCredential::count());
    }

    public function test_token_is_bound_to_customer_restaurant_method_and_single_order(): void
    {
        $this->seedBase('1');
        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 30);

        $resolved = NezhaPaymentAddressCredentialService::resolveForProof(
            $issued['token'], 10, 20, 30, 800
        );
        $this->assertSame($issued['credential']->id, $resolved->id);

        foreach ([
            [$issued['token'], 11, 20, 30, 800, 'credential_binding_mismatch'],
            [$issued['token'], 10, 21, 30, 800, 'credential_binding_mismatch'],
            [$issued['token'], 10, 20, 31, 800, 'credential_binding_mismatch'],
            [
                substr($issued['token'], 0, -1).(substr($issued['token'], -1) === '0' ? '1' : '0'),
                10,
                20,
                30,
                800,
                'credential_invalid',
            ],
        ] as [$token, $user, $restaurant, $method, $order, $expected]) {
            try {
                NezhaPaymentAddressCredentialService::resolveForProof(
                    $token, $user, $restaurant, $method, $order
                );
                $this->fail('错误绑定或篡改凭据不得通过');
            } catch (\DomainException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
        }

        NezhaPaymentAddressCredentialService::consume($resolved, 800, str_repeat('a', 64));
        $sameOrder = NezhaPaymentAddressCredentialService::resolveForProof(
            $issued['token'], 10, 20, 30, 800
        );
        $this->assertSame(800, $sameOrder->consumed_order_id);

        try {
            NezhaPaymentAddressCredentialService::resolveForProof(
                $issued['token'], 10, 20, 30, 801
            );
            $this->fail('同一凭据不得绑定第二个订单');
        } catch (\DomainException $e) {
            $this->assertSame('credential_already_consumed', $e->getMessage());
        }
    }

    public function test_old_address_remains_queryable_after_restaurant_switches_to_new_address(): void
    {
        $this->seedBase('1', self::TRON_ADDRESS, self::BEP_ADDRESS_A, 31, 'USDT (BEP20)');
        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 31);

        DB::table('restaurants')->where('id', 20)->update([
            'usdt_bep20_address' => self::BEP_ADDRESS_B,
        ]);

        $resolved = NezhaPaymentAddressCredentialService::resolveForProof(
            $issued['token'], 10, 20, 31, 900
        );
        $this->assertSame(strtolower(self::BEP_ADDRESS_A), $resolved->address_snapshot);
        $evidence = NezhaPaymentAddressCredentialService::evidence($resolved);
        $this->assertSame(strtolower(self::BEP_ADDRESS_A), $evidence['address']);
        $this->assertFalse($evidence['is_current_address']);

        NezhaPaymentAddressCredentialService::consume($resolved, 900, str_repeat('b', 64));
        $stored = NezhaPaymentAddressCredential::findOrFail($resolved->id);

        $this->assertSame(900, $stored->consumed_order_id);
        $this->assertSame(str_repeat('b', 64), $stored->submitted_tx_hash);
        $this->assertSame('consumed', NezhaPaymentAddressCredentialService::evidence($stored)['state']);
    }

    public function test_customer_order_projection_exposes_only_the_credential_address_snapshot(): void
    {
        $row = new \App\Models\OfflinePayments();
        $row->status = 'pending';
        $row->payment_info = json_encode([
            'method_id' => 30,
            'method_name' => 'USDT (TRC20)',
            '交易哈希' => str_repeat('a', 64),
        ]);
        $row->method_fields = '[]';
        $row->nezha_auto_check = [
            'address_credential' => [
                'credential_id' => 'credential-public-id',
                'address_version' => '0123456789abcdef',
                'network' => 'TRC20',
                'address' => self::TRON_ADDRESS,
                'issued_at' => '2026-07-14T10:00:00+00:00',
                'expires_at' => '2026-07-14T10:10:00+00:00',
                'state' => 'consumed',
                'is_current_address' => false,
                'secret_hash' => 'must-not-leak',
            ],
            'chain' => ['expected_to' => 'must-not-leak'],
        ];

        $formatted = \App\CentralLogics\Helpers::offline_payment_formater($row);
        $credential = $formatted['data']['address_credential'];

        $this->assertSame(self::TRON_ADDRESS, $credential['address']);
        $this->assertSame('0123456789abcdef', $credential['address_version']);
        $this->assertArrayNotHasKey('secret_hash', $credential);
        $this->assertArrayNotHasKey('chain', $formatted['data']);
    }

    public function test_expired_or_revoked_unconsumed_credential_is_rejected_but_evidence_remains(): void
    {
        $this->seedBase('1');
        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 30);
        $credential = $issued['credential'];

        $credential->expires_at = now()->subSecond();
        $credential->save();
        $this->assertCredentialRejected($issued['token'], 'credential_expired');
        $this->assertSame('expired', NezhaPaymentAddressCredentialService::evidence($credential->fresh())['state']);

        $credential->expires_at = now()->addMinute();
        $credential->revoked_at = now();
        $credential->revoked_reason = 'emergency address stop';
        $credential->save();
        $this->assertCredentialRejected($issued['token'], 'credential_revoked');
        $this->assertSame('revoked', NezhaPaymentAddressCredentialService::evidence($credential->fresh())['state']);
    }

    public function test_consumption_rechecks_expiry_and_preserves_first_submitted_hash(): void
    {
        $this->seedBase('1');
        $issued = NezhaPaymentAddressCredentialService::issue(10, 20, 30);
        $resolved = NezhaPaymentAddressCredentialService::resolveForProof(
            $issued['token'], 10, 20, 30, 950
        );

        DB::table('nezha_payment_address_credentials')
            ->where('id', $resolved->id)
            ->update(['expires_at' => now()->subSecond()]);
        try {
            NezhaPaymentAddressCredentialService::consume($resolved, 950, str_repeat('c', 64));
            $this->fail('A credential expiring before the locked write must not be consumed');
        } catch (\DomainException $e) {
            $this->assertSame('credential_expired', $e->getMessage());
        }

        DB::table('nezha_payment_address_credentials')
            ->where('id', $resolved->id)
            ->update(['expires_at' => now()->addMinute()]);
        $fresh = $resolved->fresh();
        NezhaPaymentAddressCredentialService::consume($fresh, 950, str_repeat('d', 64));
        NezhaPaymentAddressCredentialService::consume($fresh->fresh(), 950, str_repeat('e', 64));

        $this->assertSame(
            str_repeat('d', 64),
            $fresh->fresh()->submitted_tx_hash
        );
    }

    private function seedBase(
        string $enabled,
        string $tronAddress = self::TRON_ADDRESS,
        string $bepAddress = self::BEP_ADDRESS_A,
        int $methodId = 30,
        string $methodName = 'USDT (TRC20)'
    ): void {
        DB::table('business_settings')->insert([
            ['key' => NezhaPaymentAddressCredentialService::SWITCH_KEY, 'value' => $enabled],
            ['key' => 'nezha_timeout_unpaid_cancel_min', 'value' => '10'],
        ]);
        DB::table('restaurants')->insert([
            'id' => 20,
            'usdt_address' => $tronAddress,
            'usdt_bep20_address' => $bepAddress,
        ]);
        DB::table('offline_payment_methods')->insert([
            'id' => $methodId,
            'method_name' => $methodName,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sideEffectCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'carts' => DB::table('carts')->count(),
            'coupon_uses' => DB::table('coupon_uses')->count(),
        ];
    }

    private function assertCredentialRejected(string $token, string $expectedCode): void
    {
        try {
            NezhaPaymentAddressCredentialService::resolveForProof($token, 10, 20, 30, 940);
            $this->fail('Inactive credential must not be accepted for a new proof');
        } catch (\DomainException $e) {
            $this->assertSame($expectedCode, $e->getMessage());
        }
    }

    private function createSchema(): void
    {
        foreach ([
            'nezha_payment_address_credentials',
            'nezha_payment_network_states',
            'offline_payment_methods',
            'business_settings',
            'restaurants',
            'orders',
            'carts',
            'coupon_uses',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('usdt_address')->nullable();
            $table->string('usdt_bep20_address')->nullable();
        });
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name');
            $table->integer('status')->default(1);
            $table->text('method_fields')->nullable();
            $table->text('method_informations')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_payment_address_credentials', function (Blueprint $table) {
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
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_payment_network_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_id');
            $table->string('network', 8);
            $table->string('state', 16)->default('active');
            $table->char('active_address_fingerprint', 64);
            $table->unsignedInteger('active_version')->default(1);
            $table->unsignedBigInteger('pending_change_id')->nullable();
            $table->timestamp('drain_until')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedBigInteger('paused_by_admin_id')->nullable();
            $table->text('pause_reason')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'network']);
        });
        Schema::create('orders', fn (Blueprint $table) => $table->id());
        Schema::create('carts', fn (Blueprint $table) => $table->id());
        Schema::create('coupon_uses', fn (Blueprint $table) => $table->id());
    }
}
