<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\CentralLogics\NezhaPaymentAddressChangeView;
use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\CentralLogics\NezhaTotp;
use App\Models\Admin;
use App\Models\NezhaPaymentAddressChange;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\NezhaPaymentNetworkState;
use App\Models\Restaurant;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * These tests are deliberately SQLite :memory: only. They must never touch a
 * configured MySQL database because the subject is a funds-address workflow.
 */
class NezhaPaymentAddressChangeServiceTest extends TestCase
{
    private const SECRET_A = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const SECRET_B = 'KRSXG5DSNFXGOIDBKRSXG5DSNFXGOIDB';

    private const TRON_A = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const TRON_B = 'TJnwC8FcWiJQCQFzTYHxCj4DSW2iGwESVf';

    private const BEP_A = '0x55d398326f99059fF775485246999027B3197955';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('安全中止：地址状态机测试只允许 SQLite :memory:');
        }

        $this->createSchema();
        $this->seedBase();
    }

    public function test_disabled_switch_blocks_all_change_writes(): void
    {
        DB::table('business_settings')
            ->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)
            ->update(['value' => '0']);

        $this->expectDomainCode('address_change_feature_disabled', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(1),
                20,
                'TRC20',
                self::TRON_B,
                'scheduled rotation',
                $this->totp(self::SECRET_A),
                'request-disabled-0001'
            );
        });

        $this->assertSame(0, DB::table('nezha_payment_address_changes')->count());
        $this->assertSame(0, DB::table('nezha_payment_address_change_events')->count());
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_change_migration_is_additive_and_seeds_only_disabled_defaults(): void
    {
        foreach ([
            'nezha_payment_address_change_events',
            'nezha_payment_address_changes',
            'nezha_payment_network_states',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('business_settings')->whereIn('key', [
            NezhaPaymentAddressChangeService::SWITCH_KEY,
            NezhaPaymentAddressChangeService::APPROVAL_TTL_KEY,
        ])->delete();
        $before = DB::table('restaurants')->where('id', 20)->first();

        $migration = require database_path(
            'migrations/2026_07_14_090000_create_nezha_payment_address_change_tables.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('nezha_payment_network_states'));
        $this->assertTrue(Schema::hasTable('nezha_payment_address_changes'));
        $this->assertTrue(Schema::hasTable('nezha_payment_address_change_events'));
        $this->assertSame('0', (string) DB::table('business_settings')
            ->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)->value('value'));
        $this->assertSame('1440', (string) DB::table('business_settings')
            ->where('key', NezhaPaymentAddressChangeService::APPROVAL_TTL_KEY)->value('value'));
        $this->assertSame(0, DB::table('nezha_payment_network_states')->count());
        $after = DB::table('restaurants')->where('id', 20)->first();
        $this->assertSame($before->usdt_address, $after->usdt_address);
        $this->assertSame($before->usdt_bep20_address, $after->usdt_bep20_address);

        $migration->down();
        $this->assertFalse(Schema::hasTable('nezha_payment_address_change_events'));
        $this->assertFalse(Schema::hasTable('nezha_payment_address_changes'));
        $this->assertFalse(Schema::hasTable('nezha_payment_network_states'));
    }

    public function test_change_migration_refuses_to_drop_initialized_or_audit_data(): void
    {
        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'TRC20');
        DB::table('business_settings')->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)
            ->update(['value' => '0']);
        $migration = require database_path(
            'migrations/2026_07_14_090000_create_nezha_payment_address_change_tables.php'
        );

        try {
            $migration->down();
            $this->fail('已初始化的网络状态不得被普通 migration rollback 删除');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non-empty', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('nezha_payment_network_states'));
        $this->assertSame(1, DB::table('nezha_payment_network_states')->count());
    }

    public function test_request_requires_valid_step_up_and_strict_chain_address(): void
    {
        $this->expectDomainCode('address_change_totp_invalid', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(1), 20, 'TRC20', self::TRON_B, 'rotation', 'not-a-code', 'invalid-totp-0001'
            );
        });

        $this->expectDomainCode('address_change_address_invalid', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(1),
                20,
                'TRC20',
                substr(self::TRON_B, 0, -1).'u',
                'rotation',
                $this->totp(self::SECRET_A),
                'invalid-address-0001'
            );
        });

        $this->expectDomainCode('address_change_address_unchanged', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(1),
                20,
                'TRC20',
                self::TRON_A,
                'rotation',
                $this->totp(self::SECRET_A),
                'same-address-0001'
            );
        });

        $this->assertSame(0, DB::table('nezha_payment_address_changes')->count());
    }

    public function test_request_fails_safe_when_activation_state_was_not_initialized(): void
    {
        DB::table('nezha_payment_network_states')->delete();

        $this->expectDomainCode('address_change_network_state_missing', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(1),
                20,
                'TRC20',
                self::TRON_B,
                'rotation',
                $this->totp(self::SECRET_A),
                'missing-state-0001'
            );
        });
        $this->assertSame(0, DB::table('nezha_payment_address_changes')->count());
    }

    public function test_request_is_encrypted_audited_and_idempotent(): void
    {
        $code = $this->totp(self::SECRET_A);
        $change = NezhaPaymentAddressChangeService::requestChange(
            $this->admin(1), 20, 'TRC20', self::TRON_B, 'scheduled rotation', $code, 'idem-request-0001'
        );

        $this->assertSame('pending_merchant', $change->state);
        $this->assertSame(self::TRON_A, $change->old_address);
        $this->assertSame(self::TRON_B, $change->new_address);
        $this->assertSame(1, DB::table('nezha_payment_address_changes')->count());

        $raw = DB::table('nezha_payment_address_changes')->where('id', $change->id)->first();
        $this->assertStringNotContainsString(self::TRON_A, (string) $raw->old_address);
        $this->assertStringNotContainsString(self::TRON_B, (string) $raw->new_address);
        $this->assertStringNotContainsString('scheduled rotation', (string) $raw->reason);

        $event = DB::table('nezha_payment_address_change_events')->first();
        $this->assertSame('requested', $event->event_type);
        $this->assertSame('admin', $event->actor_type);
        $this->assertNotNull($event->totp_counter);
        $this->assertFalse(property_exists($event, 'totp_code'));
        $this->assertSame(1, DB::table('user_notifications')->count());
        $notification = json_decode((string) DB::table('user_notifications')->value('data'), true);
        $this->assertSame('nezha_payment_address_security', $notification['type']);
        $this->assertStringNotContainsString(self::TRON_A, $notification['description']);
        $this->assertStringNotContainsString(self::TRON_B, $notification['description']);

        // A retry after a lost response returns the same request and does not consume
        // the same TOTP step twice or append duplicate state/notification events.
        $retried = NezhaPaymentAddressChangeService::requestChange(
            $this->admin(1), 20, 'TRC20', self::TRON_B, 'scheduled rotation', $code, 'idem-request-0001'
        );
        $this->assertSame($change->id, $retried->id);
        $this->assertSame(2, DB::table('nezha_payment_address_change_events')->count());
        $this->assertSame(1, DB::table('user_notifications')->count());
    }

    public function test_opening_merchant_payment_page_marks_only_security_notifications_viewed(): void
    {
        $this->requestedChange('notification-viewed-0001');
        DB::table('user_notifications')->insert([
            'data' => json_encode(['type' => 'new_order'], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'vendor_id' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, NezhaPaymentAddressChangeView::markMerchantSecurityNotificationsViewed(100));
        $this->assertSame(0, (int) DB::table('user_notifications')->where('id', 1)->value('status'));
        $this->assertSame(1, (int) DB::table('user_notifications')->where('id', 2)->value('status'));
    }

    public function test_same_totp_step_cannot_authorize_a_second_sensitive_action(): void
    {
        $code = $this->totp(self::SECRET_A);
        NezhaPaymentAddressChangeService::requestChange(
            $this->admin(1), 20, 'TRC20', self::TRON_B, 'rotation', $code, 'replay-request-0001'
        );

        $this->expectDomainCode('address_change_totp_replayed', function () use ($code): void {
            NezhaPaymentAddressChangeService::emergencyPause(
                $this->admin(1), 21, 'BEP20', 'incident containment', $code
            );
        });

        $this->assertNull(DB::table('nezha_payment_network_states')
            ->where('restaurant_id', 21)->where('network', 'BEP20')->value('paused_at'));
    }

    public function test_only_one_open_change_can_exist_for_a_restaurant_network(): void
    {
        $first = $this->requestedChange('single-open-0001');

        $this->expectDomainCode('address_change_already_pending', function (): void {
            NezhaPaymentAddressChangeService::requestChange(
                $this->admin(2),
                20,
                'TRC20',
                'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                'competing request',
                $this->totp(self::SECRET_B),
                'single-open-0002'
            );
        });

        $this->assertSame($first->id, NezhaPaymentNetworkState::first()->pending_change_id);
        $this->assertSame(1, DB::table('nezha_payment_address_changes')->count());
    }

    public function test_only_restaurant_owner_can_confirm_exact_fingerprint(): void
    {
        $change = $this->requestedChange();

        $this->expectDomainCode('address_change_vendor_mismatch', function () use ($change): void {
            NezhaPaymentAddressChangeService::merchantConfirm(
                $this->vendor(101), $change->public_id, $change->new_fingerprint
            );
        });
        $this->expectDomainCode('address_change_fingerprint_mismatch', function () use ($change): void {
            NezhaPaymentAddressChangeService::merchantConfirm(
                $this->vendor(100), $change->public_id, str_repeat('a', 64)
            );
        });

        $confirmed = NezhaPaymentAddressChangeService::merchantConfirm(
            $this->vendor(100), $change->public_id, $change->new_fingerprint
        );
        $this->assertSame('pending_distinct_admin', $confirmed->state);
        $this->assertSame(100, $confirmed->merchant_confirmed_by_vendor_id);
    }

    public function test_requester_cannot_approve_and_distinct_admin_is_mandatory(): void
    {
        $change = $this->confirmedChange();

        $this->expectDomainCode('address_change_distinct_admin_required', function () use ($change): void {
            NezhaPaymentAddressChangeService::approveChange(
                $this->admin(1),
                $change->public_id,
                $change->new_fingerprint,
                $this->totp(self::SECRET_A, 1)
            );
        });

        DB::table('admins')->where('id', 2)->update(['two_factor_enabled' => 0]);
        $this->expectDomainCode('address_change_step_up_required', function () use ($change): void {
            NezhaPaymentAddressChangeService::approveChange(
                $this->admin(2),
                $change->public_id,
                $change->new_fingerprint,
                $this->totp(self::SECRET_B)
            );
        });

        $this->assertSame('pending_distinct_admin', $change->fresh()->state);
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_merchant_rejection_releases_the_network_without_address_write(): void
    {
        $change = $this->requestedChange('merchant-reject-0001');
        $rejected = NezhaPaymentAddressChangeService::merchantReject(
            $this->vendor(100), $change->public_id, $change->new_fingerprint
        );

        $this->assertSame('rejected', $rejected->state);
        $state = NezhaPaymentNetworkState::firstOrFail();
        $this->assertSame('active', $state->state);
        $this->assertNull($state->pending_change_id);
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_admin_can_cancel_a_draining_change_but_cannot_write_an_address(): void
    {
        $change = $this->confirmedChange('admin-cancel-0001');
        $approved = NezhaPaymentAddressChangeService::approveChange(
            $this->admin(2), $change->public_id, $change->new_fingerprint, $this->totp(self::SECRET_B)
        );
        $canceled = NezhaPaymentAddressChangeService::cancelChange(
            $this->admin(1), $approved->public_id, $this->totp(self::SECRET_A, 1)
        );

        $this->assertSame('canceled', $canceled->state);
        $state = NezhaPaymentNetworkState::firstOrFail();
        $this->assertSame('active', $state->state);
        $this->assertNull($state->pending_change_id);
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_stale_unapproved_change_expires_and_releases_the_network(): void
    {
        $change = $this->requestedChange('expire-change-0001');
        DB::table('nezha_payment_address_changes')->where('id', $change->id)
            ->update(['expires_at' => now()->subSecond()]);

        $this->assertSame(1, NezhaPaymentAddressChangeService::expireStaleChanges());
        $this->assertSame('expired', $change->fresh()->state);
        $state = NezhaPaymentNetworkState::firstOrFail();
        $this->assertSame('active', $state->state);
        $this->assertNull($state->pending_change_id);
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_approval_drains_existing_credentials_then_applies_atomically(): void
    {
        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'TRC20');
        $issued = NezhaPaymentAddressCredentialService::issue(200, 20, 30);
        $credential = $issued['credential'];
        $this->assertSame(self::TRON_A, $credential->address_snapshot);

        $change = $this->confirmedChange('drain-request-0001');
        $approved = NezhaPaymentAddressChangeService::approveChange(
            $this->admin(2),
            $change->public_id,
            $change->new_fingerprint,
            $this->totp(self::SECRET_B)
        );

        $this->assertSame('draining', $approved->state);
        $this->assertTrue($approved->drain_until->equalTo($credential->expires_at));
        $this->expectDomainCode('credential_network_unavailable', function (): void {
            NezhaPaymentAddressCredentialService::issue(200, 20, 30);
        });
        $this->expectDomainCode('address_change_drain_pending', function () use ($approved): void {
            NezhaPaymentAddressChangeService::applyReadyChange($approved->public_id);
        });
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));

        DB::table('nezha_payment_address_credentials')->where('id', $credential->id)
            ->update(['expires_at' => now()->subSecond()]);
        DB::table('nezha_payment_address_changes')->where('id', $approved->id)
            ->update(['drain_until' => now()->subSecond()]);
        DB::table('nezha_payment_network_states')->where('restaurant_id', 20)->where('network', 'TRC20')
            ->update(['drain_until' => now()->subSecond()]);

        $applied = NezhaPaymentAddressChangeService::applyReadyChange($approved->public_id);
        $this->assertSame('applied', $applied->state);
        $this->assertSame(self::TRON_B, DB::table('restaurants')->where('id', 20)->value('usdt_address'));

        $network = NezhaPaymentNetworkState::where('restaurant_id', 20)->where('network', 'TRC20')->firstOrFail();
        $this->assertSame('active', $network->state);
        $this->assertSame(2, $network->active_version);
        $this->assertNull($network->pending_change_id);

        $next = NezhaPaymentAddressCredentialService::issue(200, 20, 30);
        $this->assertSame(self::TRON_B, $next['credential']->address_snapshot);
    }

    public function test_apply_detects_out_of_band_address_drift_and_pauses_without_overwrite(): void
    {
        $change = $this->confirmedChange('drift-request-0001');
        $approved = NezhaPaymentAddressChangeService::approveChange(
            $this->admin(2), $change->public_id, $change->new_fingerprint, $this->totp(self::SECRET_B)
        );
        DB::table('restaurants')->where('id', 20)->update(['usdt_address' => 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8']);
        DB::table('nezha_payment_address_changes')->where('id', $approved->id)
            ->update(['drain_until' => now()->subSecond()]);
        DB::table('nezha_payment_network_states')->where('restaurant_id', 20)->where('network', 'TRC20')
            ->update(['drain_until' => now()->subSecond()]);

        $failed = NezhaPaymentAddressChangeService::applyReadyChange($approved->public_id);
        $this->assertSame('failed', $failed->state);
        $this->assertSame('old_address_drift', $failed->failure_code);
        $this->assertSame('paused', NezhaPaymentNetworkState::first()->state);
        $this->assertSame('TJRabPrwbZy45sbavfcjinPJC18kjpRTv8', DB::table('restaurants')->where('id', 20)->value('usdt_address'));
    }

    public function test_emergency_pause_revokes_only_unconsumed_credentials_for_one_network(): void
    {
        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'TRC20');
        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'BEP20');
        $unconsumed = NezhaPaymentAddressCredentialService::issue(200, 20, 30)['credential'];
        $consumed = NezhaPaymentAddressCredentialService::issue(201, 20, 30)['credential'];
        $consumed->consumed_at = now();
        $consumed->consumed_order_id = 9001;
        $consumed->save();
        $bep = NezhaPaymentAddressCredentialService::issue(200, 20, 31)['credential'];

        $state = NezhaPaymentAddressChangeService::emergencyPause(
            $this->admin(1), 20, 'TRC20', 'suspected compromise', $this->totp(self::SECRET_A)
        );

        $this->assertSame('paused', $state->state);
        $this->assertNotNull($unconsumed->fresh()->revoked_at);
        $this->assertNull($consumed->fresh()->revoked_at);
        $this->assertNull($bep->fresh()->revoked_at);
        $this->assertSame(self::TRON_A, DB::table('restaurants')->where('id', 20)->value('usdt_address'));

        DB::table('business_settings')->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)
            ->update(['value' => '0']);
        $this->expectDomainCode('credential_network_unavailable', function (): void {
            NezhaPaymentAddressCredentialService::issue(202, 20, 30);
        });
    }

    public function test_legacy_usdt_direct_write_is_rejected_only_when_switch_is_on(): void
    {
        // Avoid booting unrelated Restaurant observers; this guard only reads
        // the three in-memory attributes supplied by the controller.
        $restaurant = new Restaurant();
        $restaurant->setRawAttributes([
            'id' => 20,
            'usdt_address' => self::TRON_A,
            'usdt_bep20_address' => self::BEP_A,
            'usdt_network' => 'TRC20',
        ], true);
        $input = [
            'usdt_address' => self::TRON_B,
            'usdt_bep20_address' => self::BEP_A,
            'usdt_network' => 'TRC20',
        ];

        $this->expectDomainCode('address_change_legacy_write_blocked', function () use ($restaurant, $input): void {
            NezhaPaymentAddressChangeService::assertLegacyUsdtWriteAllowed($restaurant, $input);
        });

        DB::table('business_settings')->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)
            ->update(['value' => '0']);
        NezhaPaymentAddressChangeService::assertLegacyUsdtWriteAllowed($restaurant, $input);
        $this->addToAssertionCount(1);
    }

    public function test_admin_and_vendor_routes_use_dedicated_controllers(): void
    {
        $routes = $this->app['router']->getRoutes();
        $this->assertSame(
            \App\Http\Controllers\Admin\NezhaPaymentAddressChangeController::class.'@store',
            $routes->getByName('admin.restaurant.payment-address-change.store')->getActionName()
        );
        $this->assertSame(
            \App\Http\Controllers\Admin\NezhaPaymentAddressChangeController::class.'@approve',
            $routes->getByName('admin.restaurant.payment-address-change.approve')->getActionName()
        );
        $this->assertSame(
            \App\Http\Controllers\Vendor\NezhaPaymentAddressChangeController::class.'@confirm',
            $routes->getByName('vendor.payment-address-change.confirm')->getActionName()
        );
        $this->assertSame(
            \App\Http\Controllers\Vendor\NezhaPaymentAddressChangeController::class.'@reject',
            $routes->getByName('vendor.payment-address-change.reject')->getActionName()
        );
    }

    public function test_operational_commands_are_non_mutating_until_explicit_apply_or_switch(): void
    {
        DB::table('business_settings')->where('key', NezhaPaymentAddressChangeService::SWITCH_KEY)
            ->update(['value' => '0']);
        $beforeStates = DB::table('nezha_payment_network_states')->count();

        $this->artisan('nezha:payment-address-state-init')
            ->expectsOutputToContain('"mode":"dry-run"')
            ->assertExitCode(0);
        $this->assertSame($beforeStates, DB::table('nezha_payment_network_states')->count());

        $this->artisan('nezha:payment-address-maintain')
            ->expectsOutput('{"status":"disabled"}')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('nezha_payment_address_changes')->count());
    }

    private function requestedChange(string $idempotency = 'request-change-0001'): NezhaPaymentAddressChange
    {
        return NezhaPaymentAddressChangeService::requestChange(
            $this->admin(1),
            20,
            'TRC20',
            self::TRON_B,
            'scheduled rotation',
            $this->totp(self::SECRET_A),
            $idempotency
        );
    }

    private function confirmedChange(string $idempotency = 'confirm-change-0001'): NezhaPaymentAddressChange
    {
        $change = $this->requestedChange($idempotency);

        return NezhaPaymentAddressChangeService::merchantConfirm(
            $this->vendor(100), $change->public_id, $change->new_fingerprint
        );
    }

    private function admin(int $id): Admin
    {
        return Admin::findOrFail($id);
    }

    private function vendor(int $id): Vendor
    {
        return Vendor::findOrFail($id);
    }

    private function totp(string $secret, int $offset = 0): string
    {
        return NezhaTotp::codeAt($secret, (int) floor(time() / 30) + $offset);
    }

    private function expectDomainCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected domain error: '.$code);
        } catch (\DomainException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    private function seedBase(): void
    {
        DB::table('business_settings')->insert([
            ['key' => NezhaPaymentAddressChangeService::SWITCH_KEY, 'value' => '1'],
            ['key' => NezhaPaymentAddressCredentialService::SWITCH_KEY, 'value' => '1'],
            ['key' => 'nezha_timeout_unpaid_cancel_min', 'value' => '10'],
            ['key' => NezhaPaymentAddressChangeService::APPROVAL_TTL_KEY, 'value' => '1440'],
        ]);

        foreach ([[1, 'admin-a@example.test', self::SECRET_A], [2, 'admin-b@example.test', self::SECRET_B]] as [$id, $email, $secret]) {
            $admin = new Admin();
            $admin->forceFill([
                'id' => $id,
                'email' => $email,
                'password' => 'not-used',
                'two_factor_secret' => $secret,
                'two_factor_enabled' => true,
            ]);
            $admin->save();
        }

        DB::table('vendors')->insert([
            ['id' => 100, 'status' => 1],
            ['id' => 101, 'status' => 1],
        ]);
        DB::table('restaurants')->insert([
            ['id' => 20, 'vendor_id' => 100, 'usdt_address' => self::TRON_A, 'usdt_bep20_address' => self::BEP_A, 'usdt_network' => 'TRC20'],
            ['id' => 21, 'vendor_id' => 100, 'usdt_address' => self::TRON_A, 'usdt_bep20_address' => self::BEP_A, 'usdt_network' => 'BEP20'],
        ]);
        DB::table('offline_payment_methods')->insert([
            ['id' => 30, 'method_name' => 'USDT (TRC20)', 'status' => 1],
            ['id' => 31, 'method_name' => 'USDT (BEP20)', 'status' => 1],
        ]);
        NezhaPaymentAddressChangeService::initializeNetworkState(20, 'TRC20');
    }

    private function createSchema(): void
    {
        foreach ([
            'nezha_payment_address_change_events',
            'nezha_payment_address_changes',
            'nezha_payment_network_states',
            'nezha_payment_address_credentials',
            'nezha_notification_log',
            'user_notifications',
            'offline_payment_methods',
            'business_settings',
            'restaurants',
            'vendors',
            'admins',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->boolean('status')->default(true);
            $table->string('email')->nullable();
            $table->string('firebase_token')->nullable();
            $table->timestamps();
        });
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->string('usdt_address')->nullable();
            $table->string('usdt_bep20_address')->nullable();
            $table->string('usdt_network')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('nezha_notify_email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->text('data')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_notification_log', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('target', 16);
            $table->string('event_type', 40);
            $table->string('outcome', 16);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->string('detail')->nullable();
            $table->timestamps();
        });
        Schema::create('offline_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('method_name');
            $table->integer('status')->default(1);
            $table->text('method_fields')->nullable();
            $table->text('method_informations')->nullable();
            $table->timestamps();
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
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_payment_network_states', function (Blueprint $table): void {
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
        Schema::create('nezha_payment_address_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('restaurant_id');
            $table->string('network', 8);
            $table->string('source_state', 16);
            $table->text('old_address');
            $table->text('new_address');
            $table->char('old_fingerprint', 64);
            $table->char('new_fingerprint', 64);
            $table->unsignedInteger('expected_version');
            $table->string('state', 32);
            $table->unsignedBigInteger('requested_by_admin_id');
            $table->char('idempotency_hash', 64);
            $table->text('reason');
            $table->unsignedBigInteger('merchant_confirmed_by_vendor_id')->nullable();
            $table->timestamp('merchant_confirmed_at')->nullable();
            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('drain_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->unique(['requested_by_admin_id', 'idempotency_hash']);
        });
        Schema::create('nezha_payment_address_change_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_id')->nullable();
            $table->unsignedBigInteger('network_state_id');
            $table->string('event_type', 32);
            $table->string('state_from', 32)->nullable();
            $table->string('state_to', 32);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('totp_counter')->nullable();
            $table->text('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['actor_type', 'actor_id', 'totp_counter']);
        });
    }
}
