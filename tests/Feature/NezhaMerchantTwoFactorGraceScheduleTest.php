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

    public function test_schedule_command_is_permanently_disabled_without_mutation(): void
    {
        $deadline = now()->addDays(7)->startOfSecond();
        $this->artisan('nezha:merchant-2fa-schedule', [
            'deadline' => $deadline->toIso8601String(),
        ])->expectsOutputToContain('voluntary')->assertFailed();

        $owner = Vendor::findOrFail(1);
        $employee = VendorEmployee::findOrFail(91);
        $this->assertTrue($owner->two_factor_grace_pending);
        $this->assertTrue($employee->two_factor_grace_pending);
        $this->assertNull($owner->two_factor_required_at);
        $this->assertNull($employee->two_factor_required_at);
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
