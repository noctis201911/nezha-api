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

    public function test_dormant_switches_and_maintenance_schedule_are_registered(): void
    {
        $switches = file_get_contents($this->path('config/nezha_switches.php'));
        $schedule = file_get_contents($this->path('bootstrap/app.php'));
        $docs = file_get_contents($this->path('docs/PRELAUNCH_SWITCHES.md'));

        foreach ([
            'nezha_payment_address_credential_status',
            'nezha_payment_address_change_status',
        ] as $key) {
            $this->assertStringContainsString($key, $switches);
            $this->assertStringContainsString($key, $docs);
        }
        $this->assertStringContainsString('nezha:payment-address-maintain', $schedule);
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

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
