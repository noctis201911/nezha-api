<?php

namespace Tests\Feature;

use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy as Policy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCaseService;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentStrictUsdtVerifier as Verifier;
use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/** Target-engine proof; skipped unless an explicitly isolated MySQL 5.7 endpoint is supplied. */
class MerchantDirectPaymentLateCaseMySql57Test extends TestCase
{
    private const CONNECTION = 'mysql57_v2';

    private const MERCHANT = '0x1111111111111111111111111111111111111111';

    private const CUSTOMER = '0x2222222222222222222222222222222222222222';

    private ?PDO $admin = null;

    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('NEZHA_V2_MYSQL57_PORT');
        if (! is_string($port) || $port === '') {
            $this->markTestSkipped('Set NEZHA_V2_MYSQL57_PORT for an isolated MySQL 5.7 instance.');
        }

        $host = getenv('NEZHA_V2_MYSQL57_HOST') ?: '127.0.0.1';
        $user = getenv('NEZHA_V2_MYSQL57_USER') ?: 'root';
        $password = getenv('NEZHA_V2_MYSQL57_PASSWORD') ?: '';
        $this->admin = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $version = (string) $this->admin->query('SELECT VERSION()')->fetchColumn();
        if (! str_starts_with($version, '5.7.')) {
            $this->markTestSkipped("Target engine must be MySQL 5.7, got {$version}.");
        }

        $this->database = 'nz_v2_'.bin2hex(random_bytes(6));
        $this->admin->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => (int) $port,
            'database' => $this->database,
            'username' => $user,
            'password' => $password,
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            // Match the production Laravel connection profile in config/database.php.
            'strict' => false,
            'engine' => null,
        ]]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        $this->assertSame('NO_ENGINE_SUBSTITUTION', (string) DB::selectOne('SELECT @@session.sql_mode AS mode')->mode);
        $this->createBaseSchema();
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect(self::CONNECTION);
            if ($this->admin && $this->database && str_starts_with($this->database, 'nz_v2_')) {
                $this->admin->exec("DROP DATABASE `{$this->database}`");
            }
        } finally {
            DB::setDefaultConnection('sqlite');
            $this->admin = null;
            $this->database = null;
            parent::tearDown();
        }
    }

    public function test_migration_refuses_before_funds_refund_identity_is_present(): void
    {
        DB::statement('ALTER TABLE nezha_refund_records DROP INDEX funds_refund_records_event_key_unique');
        DB::statement('ALTER TABLE nezha_refund_records DROP COLUMN event_key');
        $migration = require database_path('migrations/2026_07_19_180000_add_direct_payment_late_cases_to_nezha_refunds.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Funds refund event identity migration must run before');

        $migration->up();
    }

    public function test_migration_up_down_encryption_and_append_only_triggers_on_mysql57(): void
    {
        $migration = $this->runMigrations();

        $this->assertSame('0', (string) DB::table('business_settings')
            ->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)->value('value'));
        $this->assertTrue(Schema::hasColumns('nezha_refund_records', [
            'source_domain',
            'case_public_id',
            'late_payment_claim_key',
            'late_refund_claim_key',
            'late_refund_destination',
        ]));
        $this->assertTrue(Schema::hasTable('nezha_refund_record_events'));
        $options = strtoupper((string) DB::selectOne(
            'SELECT CREATE_OPTIONS AS options FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [$this->database, 'nezha_refund_record_events']
        )->options);
        $this->assertMatchesRegularExpression('/ENCRYPTION=[\'\"]Y[\'\"]/', $options);
        $triggers = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS '
            .'WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME IN (?, ?)',
            [$this->database, 'nz_ref_evt_no_update', 'nz_ref_evt_no_delete']
        );
        $this->assertSame(2, (int) $triggers->aggregate);

        $migration->down();
        $this->assertFalse(Schema::hasTable('nezha_refund_record_events'));
        $this->assertFalse(Schema::hasColumn('nezha_refund_records', 'case_public_id'));
        $this->assertTrue(Schema::hasColumn('nezha_refund_records', 'event_key'));
        $fundsIndex = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',
            [$this->database, 'nezha_refund_records', 'funds_refund_records_event_key_unique']
        );
        $this->assertSame(1, (int) $fundsIndex->aggregate);
        $status = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS length FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->database, 'nezha_refund_records', 'status']
        );
        $this->assertSame(40, (int) $status->length);
    }

    public function test_full_fake_provider_flow_is_idempotent_encrypted_and_keeps_order_canceled(): void
    {
        $migration = $this->runMigrations();
        DB::table('business_settings')->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)->update(['value' => '1']);
        DB::table('business_settings')->where('key', NezhaPaymentAddressCredentialService::SWITCH_KEY)->update(['value' => '1']);
        DB::table('restaurants')->insert([
            'id' => 6,
            'name' => 'Synthetic merchant',
            'usdt_bep20_address' => self::MERCHANT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offline_payment_methods')->insert([
            'id' => 31,
            'method_name' => 'USDT (BEP20)',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = new Order;
        $order->forceFill([
            'id' => 9001,
            'restaurant_id' => 6,
            'user_id' => 1,
            'is_guest' => false,
            'payment_method' => 'offline_payment',
            'payment_status' => 'unpaid',
            'order_status' => 'canceled',
            'order_amount' => 1000,
            'canceled' => now(),
        ])->save();
        $order = Order::whereKey(9001)->firstOrFail();
        $ordinaryId = DB::table('nezha_refund_records')->insertGetId([
            'order_id' => $order->id,
            'event_key' => "order:{$order->id}:refund",
            'restaurant_id' => 6,
            'user_id' => 1,
            'payment_channel' => 'usdt',
            'status' => 'pending_merchant_refund',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $issued = NezhaPaymentAddressCredentialService::issue(1, 6, 31);
        $service = app(MerchantDirectPaymentLateCaseService::class);
        $paymentHash = str_repeat('a', 64);
        $case = $service->report(
            $order,
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            $paymentHash,
            $issued['token'],
            'customer',
            1
        );
        $repeat = $service->report(
            $order->fresh(),
            Policy::CHANNEL_USDT_BEP20,
            31,
            Policy::WALLET_EXCHANGE,
            $paymentHash,
            $issued['token'],
            'customer',
            1
        );
        $this->assertSame($case->id, $repeat->id);
        $this->assertSame(2, DB::table('nezha_refund_records')->where('order_id', $order->id)->count());
        $this->assertSame("order:{$order->id}:refund", DB::table('nezha_refund_records')->where('id', $ordinaryId)->value('event_key'));
        $this->assertNull(DB::table('nezha_refund_records')->where('id', $case->id)->value('event_key'));

        $case = $service->attributePayment(
            $case,
            '1200000',
            $this->observation($paymentHash, self::MERCHANT, '1200000', 4),
            'admin',
            1
        );
        $case = $service->setRefundTerms($case, '1175000', self::CUSTOMER, 6, 1);
        $refundHash = str_repeat('b', 64);
        $case = $service->submitRefund(
            $case,
            $refundHash,
            $this->observation($refundHash, self::CUSTOMER, '1175000', 8),
            6,
            1
        );

        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $case->status);
        $this->assertSame(Policy::EVIDENCE_CHAIN_VERIFIED, $case->evidence_authority);
        $this->assertSame('canceled', $order->fresh()->order_status);
        $this->assertSame(5, $case->events()->count());
        $raw = DB::table('nezha_refund_records')->where('id', $case->id)->first();
        $this->assertNotSame($paymentHash, $raw->late_payment_tx_hash);
        $this->assertNotSame($refundHash, $raw->late_refund_tx_hash);
        $this->assertStringNotContainsString(self::CUSTOMER, (string) $raw->late_refund_destination);
        $rawEvent = DB::table('nezha_refund_record_events')->where('refund_record_id', $case->id)->first();
        $this->assertStringNotContainsString('1200000', (string) $rawEvent->payload);

        try {
            DB::table('nezha_refund_record_events')->where('id', $rawEvent->id)->update(['event_type' => 'rewrite']);
            $this->fail('MySQL trigger permitted event rewrite.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        try {
            DB::table('nezha_refund_records')->where('id', $case->id)->delete();
            $this->fail('Foreign key permitted deleting a refund aggregate with evidence.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('constraint', strtolower($exception->getMessage()));
        }
        try {
            $migration->down();
            $this->fail('Migration dropped non-empty late-payment evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('enabled', $exception->getMessage());
        }
        DB::table('business_settings')->where('key', MerchantDirectPaymentLateCaseService::SWITCH_KEY)->update(['value' => '0']);
        try {
            $migration->down();
            $this->fail('Migration dropped non-empty V2 evidence after disabling the switch.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('non-empty V2 late-payment evidence', $exception->getMessage());
        }
    }

    private function runMigrations(): object
    {
        $credential = require database_path('migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php');
        $credential->up();
        $migration = require database_path('migrations/2026_07_19_180000_add_direct_payment_late_cases_to_nezha_refunds.php');
        $migration->up();

        return $migration;
    }

    private function observation(string $hash, string $to, string $amount, int $eventIndex): array
    {
        return [
            'provider_status' => 'ok',
            'receipt_status' => 'success',
            'finalized_block_number' => '100',
            'attested_transaction_hash' => $hash,
            'events' => [[
                'event_index' => $eventIndex,
                'contract' => Verifier::BSC_USDT,
                'from' => self::MERCHANT,
                'to' => $to,
                'amount_atomic' => $amount,
                'block_number' => '100',
            ]],
        ];
    }

    private function createBaseSchema(): void
    {
        DB::statement('CREATE TABLE business_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `key` VARCHAR(191) NOT NULL, value TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
        DB::statement('CREATE TABLE restaurants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) NOT NULL, usdt_address VARCHAR(191) NULL, usdt_bep20_address VARCHAR(191) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
        DB::statement('CREATE TABLE offline_payment_methods (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, method_name VARCHAR(191) NOT NULL, status INT NOT NULL DEFAULT 1, method_fields TEXT NULL, method_informations TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
        DB::statement("CREATE TABLE orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, is_guest TINYINT(1) NOT NULL DEFAULT 0, payment_method VARCHAR(40) NULL, payment_status VARCHAR(40) NULL, order_status VARCHAR(40) NOT NULL DEFAULT 'pending', order_amount DECIMAL(24,2) NOT NULL DEFAULT 0, canceled TIMESTAMP NULL, delivered TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX orders_restaurant_id_idx (restaurant_id), INDEX orders_user_id_idx (user_id)) ENGINE=InnoDB");
        DB::statement("CREATE TABLE nezha_refund_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(191) NULL, refund_id BIGINT UNSIGNED NULL, restaurant_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL, guest_id VARCHAR(100) NULL, payment_channel VARCHAR(20) NOT NULL DEFAULT 'other', order_amount DECIMAL(24,2) NOT NULL DEFAULT 0, refund_amount DECIMAL(24,2) NOT NULL DEFAULT 0, reason_category VARCHAR(30) NULL, reason_note VARCHAR(191) NULL, route_locked_note VARCHAR(191) NULL, chain VARCHAR(16) NULL, original_tx_hash VARCHAR(120) NULL, locked_to_address VARCHAR(120) NULL, refund_tx_hash VARCHAR(120) NULL, chain_verify_status VARCHAR(20) NOT NULL DEFAULT 'na', chain_verify_detail JSON NULL, refund_proof_image VARCHAR(191) NULL, customer_confirmed TINYINT(1) NOT NULL DEFAULT 0, customer_confirmed_at TIMESTAMP NULL, risk_action VARCHAR(20) NOT NULL DEFAULT 'pass', risk_hit JSON NULL, status VARCHAR(40) NOT NULL DEFAULT 'recorded', operator_id BIGINT UNSIGNED NULL, reviewed_by BIGINT UNSIGNED NULL, reviewed_at TIMESTAMP NULL, review_note VARCHAR(191) NULL, merchant_refunded_at TIMESTAMP NULL, merchant_refund_note VARCHAR(191) NULL, overdue_anchor_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE KEY funds_refund_records_event_key_unique (event_key), INDEX nz_ref_order_idx (order_id), INDEX nz_ref_rest_idx (restaurant_id)) ENGINE=InnoDB");
    }
}
