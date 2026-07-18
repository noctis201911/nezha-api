<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NezhaPaymentAddressIntegrationContractTest extends TestCase
{
    public function test_legacy_controller_guard_runs_before_any_payment_write(): void
    {
        $source = file_get_contents($this->path('app/Http/Controllers/Admin/VendorController.php'));
        $guard = strpos($source, 'assertLegacyUsdtWriteAllowed');
        $imageWrite = strpos($source, "if (\$request->has('rmb_qr_image'))", $guard);
        $disabledOnly = strpos($source, 'NezhaPaymentAddressChangeService::enabled())', $guard);
        $addressWrite = strpos($source, '$restaurant->usdt_address = $request->usdt_address;', $guard);

        $this->assertNotFalse($guard);
        $this->assertNotFalse($imageWrite);
        $this->assertNotFalse($disabledOnly);
        $this->assertNotFalse($addressWrite);
        $this->assertLessThan($imageWrite, $guard);
        $this->assertLessThan($addressWrite, $disabledOnly);
        $this->assertLessThan($addressWrite, $guard);
    }

    public function test_credential_resolution_precedes_payment_proof_parsing_and_consumes_in_transaction(): void
    {
        $source = file_get_contents($this->path('app/Http/Controllers/Api/V1/OrderController.php'));
        $resolve = strpos($source, 'NezhaPaymentAddressCredentialService::resolveForProof');
        $proofParsing = strpos($source, "\$methodFields = \$method->method_fields", $resolve);
        $begin = strpos($source, 'DB::beginTransaction();', $resolve);
        $consume = strpos($source, 'NezhaPaymentAddressCredentialService::consume', $begin);
        $commit = strpos($source, 'DB::commit();', $consume);

        $this->assertNotFalse($resolve);
        $this->assertNotFalse($proofParsing);
        $this->assertLessThan($proofParsing, $resolve);
        $this->assertNotFalse($begin);
        $this->assertNotFalse($consume);
        $this->assertNotFalse($commit);
        $this->assertLessThan($consume, $begin);
        $this->assertLessThan($commit, $consume);
    }

    public function test_active_governance_switches_and_maintenance_schedule_are_registered(): void
    {
        $switches = require $this->path('config/nezha_switches.php');
        $schedule = file_get_contents($this->path('bootstrap/app.php'));
        $docs = file_get_contents($this->path('docs/PRELAUNCH_SWITCHES.md'));

        $registry = collect($switches['switches'])->keyBy('key');
        foreach ([
            'nezha_payment_address_credential_status',
            'nezha_payment_address_change_status',
        ] as $key) {
            $this->assertTrue($registry->has($key));
            $this->assertSame('B', $registry[$key]['section']);
            $this->assertSame(1, $registry[$key]['expected']);
            $this->assertStringContainsString($key, $docs);
            $this->assertStringContainsString("| `{$key}` | 1 |", $docs);
        }
        $this->assertStringContainsString('nezha:payment-address-maintain', $schedule);
        $this->assertStringContainsString('nezha:payment-address-credential-retain', $schedule);
        $this->assertStringContainsString("dailyAt('03:35')", $schedule);
    }

    public function test_sensitive_models_use_explicit_fillable_and_never_define_a_totp_code_column(): void
    {
        foreach ([
            'app/Models/NezhaPaymentAddressCredential.php',
            'app/Models/NezhaPaymentNetworkState.php',
            'app/Models/NezhaPaymentAddressChange.php',
            'app/Models/NezhaPaymentAddressChangeEvent.php',
        ] as $path) {
            $source = file_get_contents($this->path($path));
            $this->assertStringContainsString('protected $fillable', $source, $path);
            $this->assertStringNotContainsString('protected $guarded = []', $source, $path);
            $this->assertStringNotContainsString("'totp_code'", $source, $path);
        }

        $migration = file_get_contents($this->path(
            'database/migrations/2026_07_14_090000_create_nezha_payment_address_change_tables.php'
        ));
        $this->assertStringNotContainsString("totp_code", $migration);
        $this->assertStringContainsString("totp_counter", $migration);
    }

    public function test_sensitive_migrations_fail_closed_on_mysql_encryption_errors(): void
    {
        $paths = [
            'database/migrations/2026_07_13_210000_create_nezha_payment_address_credentials.php' => [
                'nezha_payment_address_credentials',
            ],
            'database/migrations/2026_07_14_090000_create_nezha_payment_address_change_tables.php' => [
                'nezha_payment_network_states',
                'nezha_payment_address_changes',
                'nezha_payment_address_change_events',
            ],
            'database/migrations/2026_07_14_160000_add_retention_marker_to_nezha_payment_address_credentials.php' => [
                'nezha_payment_address_credentials',
            ],
        ];

        foreach ($paths as $path => $tables) {
            $migration = file_get_contents($this->path($path));

            $this->assertStringContainsString("getDriverName() !== 'mysql'", $migration, $path);
            $this->assertStringContainsString("ENCRYPTION='Y'", $migration, $path);
            $this->assertStringContainsString('information_schema.TABLES', $migration, $path);
            $this->assertStringContainsString('CREATE_OPTIONS AS create_options', $migration, $path);
            $this->assertStringContainsString('tablespace encryption verification failed', $migration, $path);
            $this->assertStringNotContainsString('catch (\\Throwable', $migration, $path);

            foreach ($tables as $table) {
                $this->assertStringContainsString(
                    "\$this->assertTablespaceEncrypted('{$table}')",
                    $migration,
                    $path
                );
            }
        }

        $credentialMigration = str_replace(
            ["\r\n", "\r"],
            "\n",
            file_get_contents($this->path(array_key_first($paths)))
        );
        $createEnd = strpos($credentialMigration, "        }\n\n        // MySQL DDL auto-commits");
        $encryptionCheck = strpos(
            $credentialMigration,
            "\$this->assertTablespaceEncrypted('nezha_payment_address_credentials')"
        );
        $this->assertNotFalse($createEnd);
        $this->assertNotFalse($encryptionCheck);
        $this->assertGreaterThan($createEnd, $encryptionCheck);
    }

    public function test_confirmed_a_plus_c_ui_is_wired_to_real_state_machine_routes(): void
    {
        $admin = file_get_contents($this->path(
            'resources/views/admin-views/vendor/view/partials/_payment-address-security.blade.php'
        ));
        $merchant = file_get_contents($this->path(
            'resources/views/vendor-views/wallet-method/partials/_payment-address-change.blade.php'
        ));
        $adminController = file_get_contents($this->path(
            'app/Http/Controllers/Admin/NezhaPaymentAddressChangeController.php'
        ));
        $vendorController = file_get_contents($this->path(
            'app/Http/Controllers/Vendor/NezhaPaymentAddressChangeController.php'
        ));

        $this->assertStringContainsString('data-payment-address-security="admin-a"', $admin);
        $this->assertStringContainsString('data-payment-address-review-drawer="admin-c"', $admin);
        $this->assertStringContainsString('payment-address-change.store', $admin);
        $this->assertStringContainsString('payment-address-change.approve', $admin);
        $this->assertStringContainsString('payment-address-change.pause', $admin);
        $this->assertStringContainsString('本页面不会自动初始化或改变地址', $admin);
        $this->assertStringNotContainsString('退回申请', $admin);

        $this->assertStringContainsString('data-payment-address-security="merchant-a"', $merchant);
        $this->assertStringContainsString('payment-address-change.confirm', $merchant);
        $this->assertStringContainsString('payment-address-change.reject', $merchant);
        $this->assertStringContainsString('候选地址 · 尚未生效', $merchant);

        $this->assertStringContainsString('expectsJson()', $adminController);
        $this->assertStringContainsString('expectsJson()', $vendorController);
        $this->assertStringContainsString("route('admin.restaurant.view'", $adminController);
        $this->assertStringContainsString("route('vendor.wallet-method.index'", $vendorController);
    }

    public function test_security_notification_copy_omits_addresses_and_records_real_outcomes(): void
    {
        $notifier = file_get_contents($this->path(
            'app/CentralLogics/NezhaPaymentAddressChangeNotifier.php'
        ));
        $service = file_get_contents($this->path(
            'app/CentralLogics/NezhaPaymentAddressChangeService.php'
        ));

        $this->assertStringContainsString('nezha_payment_address_security', $notifier);
        $this->assertStringContainsString("'channel_outcomes'", $notifier);
        $this->assertStringContainsString("NezhaNotifyLog::record('site'", $notifier);
        $this->assertStringContainsString("NezhaNotifyLog::record('telegram'", $notifier);
        $this->assertStringContainsString("NezhaNotifyLog::record('email'", $notifier);
        $this->assertStringContainsString("NezhaNotifyLog::record('push'", $notifier);
        $this->assertStringNotContainsString('$change->old_address', $notifier);
        $this->assertStringNotContainsString('$change->new_address', $notifier);
        $this->assertStringNotContainsString('$change->reason', $notifier);
        $this->assertStringNotContainsString('totp_code', $notifier);
        $this->assertStringContainsString("NezhaPaymentAddressChangeNotifier::change(\$change, 'requested')", $service);
        $this->assertStringContainsString('NezhaPaymentAddressChangeNotifier::emergencyPause($state)', $service);
    }

    public function test_customer_order_address_is_projected_from_credential_evidence_only(): void
    {
        $credentialService = file_get_contents($this->path(
            'app/CentralLogics/NezhaPaymentAddressCredentialService.php'
        ));
        $formatter = file_get_contents($this->path('app/CentralLogics/Helpers.php'));

        $this->assertStringContainsString("'address' => (string) \$credential->address_snapshot", $credentialService);
        $this->assertStringContainsString("data_get(\$user_data->nezha_auto_check, 'address_credential')", $formatter);
        $this->assertStringContainsString("'credential_id', 'address_version', 'network', 'address'", $formatter);
        $this->assertStringNotContainsString("'secret_hash'", substr(
            $formatter,
            strpos($formatter, '$credentialKeys'),
            500
        ));
    }

    public function test_disabled_credential_endpoint_preserves_guest_compatibility_before_auth_gate(): void
    {
        $routes = file_get_contents($this->path('routes/api/v1/api.php'));
        $controller = file_get_contents($this->path(
            'app/Http/Controllers/Api/V1/PaymentAddressCredentialController.php'
        ));

        $routePosition = strpos($routes, "Route::post('customer/payment/address-credential'");
        $authGroupPosition = strpos(
            $routes,
            "Route::group(['prefix' => 'customer', 'middleware' => 'auth:api']"
        );
        $disabledPosition = strpos($controller, "payment_address_credential_disabled");
        $loginPosition = strpos($controller, "address_credential_login_required");

        $this->assertNotFalse($routePosition);
        $this->assertNotFalse($authGroupPosition);
        $this->assertLessThan($authGroupPosition, $routePosition);
        $this->assertNotFalse($disabledPosition);
        $this->assertNotFalse($loginPosition);
        $this->assertLessThan($loginPosition, $disabledPosition);
        $this->assertStringContainsString("Auth::guard('api')->user()", $controller);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
