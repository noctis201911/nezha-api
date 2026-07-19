<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabaseFixtures;
use Tests\TestCase;

class NezhaMerchantTwoFactorGraceScheduleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        IsolatedDatabaseFixtures::ensure($this->app);

        DB::table('vendors')->where('id', 1)->update([
            'two_factor_enabled' => false,
            'two_factor_grace_pending' => true,
            'two_factor_required_at' => null,
        ]);
        DB::table('vendor_employees')->insert([
            'id' => 91,
            'f_name' => 'Grace',
            'l_name' => 'Employee',
            'email' => 'grace-employee@example.test',
            'password' => bcrypt('Unused-Password-9!'),
            'employee_role_id' => 1,
            'vendor_id' => 1,
            'restaurant_id' => 1,
            'status' => 1,
            'two_factor_enabled' => false,
            'two_factor_grace_pending' => true,
            'auth_generation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_exact_future_deadline_is_persisted_once_for_pending_legacy_actors(): void
    {
        $deadline = now()->addDays(7)->startOfSecond();
        $this->artisan('nezha:merchant-2fa-schedule', [
            'deadline' => $deadline->toIso8601String(),
        ])->assertSuccessful();

        $owner = Vendor::findOrFail(1);
        $employee = VendorEmployee::findOrFail(91);
        $this->assertFalse($owner->two_factor_grace_pending);
        $this->assertFalse($employee->two_factor_grace_pending);
        $this->assertTrue($owner->two_factor_required_at->equalTo($deadline));
        $this->assertTrue($employee->two_factor_required_at->equalTo($deadline));

        $this->artisan('nezha:merchant-2fa-schedule', [
            'deadline' => $deadline->copy()->addDay()->toIso8601String(),
        ])->expectsOutputToContain('owners=0, employees=0')->assertSuccessful();
        $this->assertTrue(Vendor::findOrFail(1)->two_factor_required_at->equalTo($deadline));
    }

    public function test_ambiguous_or_past_deadline_is_rejected_without_mutation(): void
    {
        foreach (['next Friday', now()->subMinute()->toIso8601String()] as $deadline) {
            $this->artisan('nezha:merchant-2fa-schedule', [
                'deadline' => $deadline,
            ])->assertFailed();
        }

        $this->assertTrue(Vendor::findOrFail(1)->two_factor_grace_pending);
        $this->assertNull(Vendor::findOrFail(1)->two_factor_required_at);
        $this->assertTrue(VendorEmployee::findOrFail(91)->two_factor_grace_pending);
    }
}
