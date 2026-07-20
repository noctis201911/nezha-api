<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaTotp;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaMerchantTwoFactorRecoveryCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        if (! Schema::hasColumn('vendors', 'auth_token')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->string('auth_token', 191)->nullable();
            });
        }
        DB::table('admins')->insert([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Approver',
            'email' => 'second-approver@example.test',
            'password' => Hash::make('unused'),
            'role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admins')->where('id', 1)->update(['email' => 'first-approver@example.test']);

        $vendor = Vendor::findOrFail(1);
        $vendor->forceFill([
            'email' => 'recovery-target@example.test',
            'two_factor_secret' => NezhaTotp::generateSecret(),
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => [Hash::make('AAAAABBBBB')],
            'two_factor_enrolled_at' => now(),
            'two_factor_grace_pending' => false,
            'auth_generation' => 4,
            'auth_token' => 'active-token',
        ])->save();
    }

    public function test_support_recovery_runs_on_a_single_super_admin_and_records_no_secrets(): void
    {
        $exitCode = Artisan::call('nezha:merchant-2fa-recover', [
            'actor_type' => 'owner',
            'actor_id' => 1,
            'approver_email' => 'first-approver@example.test',
            '--reason' => 'Verified identity after authenticator loss.',
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $vendor = Vendor::findOrFail(1);
        $this->assertFalse($vendor->two_factor_enabled);
        $this->assertNull($vendor->two_factor_secret);
        $this->assertNull($vendor->two_factor_recovery_codes);
        $this->assertNull($vendor->two_factor_required_at);
        $this->assertNull($vendor->auth_token);
        $this->assertSame(5, $vendor->auth_generation);

        $event = DB::table('merchant_two_factor_events')->where('event_type', 'support_recovery')->first();
        $this->assertNotNull($event);
        $this->assertSame(1, (int) $event->approver_one_id);
        $this->assertNull($event->approver_two_id);
        $this->assertSame('Verified identity after authenticator loss.', $event->reason);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('active-token', $serialized);
        $this->assertStringNotContainsString('AAAAABBBBB', $serialized);
        Mail::assertSentCount(1);
    }

    public function test_optional_second_approver_is_recorded_when_supplied(): void
    {
        $exitCode = Artisan::call('nezha:merchant-2fa-recover', [
            'actor_type' => 'owner',
            'actor_id' => 1,
            'approver_email' => 'first-approver@example.test',
            '--second-approver' => 'second-approver@example.test',
            '--reason' => 'Dual approval available.',
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $event = DB::table('merchant_two_factor_events')->where('event_type', 'support_recovery')->first();
        $this->assertSame(1, (int) $event->approver_one_id);
        $this->assertSame(2, (int) $event->approver_two_id);
    }

    public function test_unknown_or_duplicate_approver_is_rejected_without_mutation(): void
    {
        $this->artisan('nezha:merchant-2fa-recover', [
            'actor_type' => 'owner',
            'actor_id' => 1,
            'approver_email' => 'not-an-admin@example.test',
            '--reason' => 'Unknown approver.',
        ])->assertFailed();

        $this->artisan('nezha:merchant-2fa-recover', [
            'actor_type' => 'owner',
            'actor_id' => 1,
            'approver_email' => 'first-approver@example.test',
            '--second-approver' => 'first-approver@example.test',
            '--reason' => 'Invalid duplicate approval.',
        ])->assertFailed();

        $vendor = Vendor::findOrFail(1);
        $this->assertTrue($vendor->two_factor_enabled);
        $this->assertSame('active-token', $vendor->auth_token);
        $this->assertSame(0, DB::table('merchant_two_factor_events')->where('event_type', 'support_recovery')->count());
        Mail::assertNothingSent();
    }

    public function test_notification_failure_is_reported_after_recovery_has_safely_revoked_access(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('simulated transport failure'));

        $this->artisan('nezha:merchant-2fa-recover', [
            'actor_type' => 'owner',
            'actor_id' => 1,
            'approver_email' => 'first-approver@example.test',
            '--reason' => 'Verified identity; notification transport test.',
        ])->assertFailed();

        $vendor = Vendor::findOrFail(1);
        $this->assertFalse($vendor->two_factor_enabled);
        $this->assertNull($vendor->auth_token);
        $this->assertSame(5, $vendor->auth_generation);
        $this->assertSame(
            1,
            DB::table('merchant_two_factor_events')->where('event_type', 'support_recovery')->count()
        );
    }
}
