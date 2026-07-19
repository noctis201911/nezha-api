<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\CentralLogics\NezhaTotp;
use App\Models\MerchantTwoFactorChallenge;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedDatabaseFixtures;
use Tests\TestCase;

class NezhaMerchantTwoFactorServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        IsolatedDatabaseFixtures::ensure($this->app);

        foreach (['vendors', 'vendor_employees'] as $table) {
            if (! Schema::hasColumn($table, 'auth_token')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('auth_token', 191)->nullable();
                });
            }
        }

        DB::table('vendors')->where('id', 1)->update([
            'password' => Hash::make('Correct-Horse-9!'),
            'auth_token' => 'existing-app-token',
            'two_factor_grace_pending' => false,
            'two_factor_required_at' => now(),
        ]);
    }

    public function test_existing_grace_and_new_account_enrollment_states_are_explicit(): void
    {
        $vendor = Vendor::findOrFail(1);
        $this->assertSame(NezhaMerchantTwoFactor::STATE_ENROLLMENT, NezhaMerchantTwoFactor::state($vendor));

        $vendor->forceFill([
            'two_factor_grace_pending' => true,
            'two_factor_required_at' => null,
        ])->save();
        $this->assertSame(NezhaMerchantTwoFactor::STATE_GRACE, NezhaMerchantTwoFactor::state($vendor->fresh()));

        $vendor->forceFill([
            'two_factor_grace_pending' => false,
            'two_factor_required_at' => now()->addDay(),
        ])->save();
        $this->assertSame(NezhaMerchantTwoFactor::STATE_GRACE, NezhaMerchantTwoFactor::state($vendor->fresh()));

        $at = now()->startOfSecond();
        $vendor->forceFill(['two_factor_required_at' => $at->copy()->addSecond()])->save();
        $this->assertSame(
            NezhaMerchantTwoFactor::STATE_GRACE,
            NezhaMerchantTwoFactor::state($vendor->fresh(), $at)
        );
        $this->assertSame(
            NezhaMerchantTwoFactor::STATE_ENROLLMENT,
            NezhaMerchantTwoFactor::state($vendor->fresh(), $at->copy()->addSeconds(2))
        );
    }

    public function test_enrollment_encrypts_secret_hashes_recovery_codes_and_rejects_totp_replay(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $counter = (int) floor(time() / 30);

        $result = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $counter),
            0,
            ['ip' => '127.0.0.9']
        );

        $enrolled = $result['actor']->fresh();
        $this->assertTrue($enrolled->two_factor_enabled);
        $this->assertSame($secret, $enrolled->two_factor_secret);
        $this->assertNotSame($secret, DB::table('vendors')->where('id', 1)->value('two_factor_secret'));
        $this->assertSame(1, $enrolled->auth_generation);
        $this->assertNull($enrolled->auth_token);
        $this->assertCount(8, $result['recovery_codes']);
        $this->assertCount(8, $enrolled->two_factor_recovery_codes);
        $this->assertTrue(Hash::check(
            str_replace('-', '', $result['recovery_codes'][0]),
            $enrolled->two_factor_recovery_codes[0]
        ));

        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::verifyTotp(
            $enrolled,
            NezhaTotp::codeAt($secret, $counter),
            1
        );
    }

    public function test_recovery_code_is_one_time_and_forces_reenrollment_and_revocation(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $result = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, (int) floor(time() / 30)),
            0
        );
        $enrolled = $result['actor'];
        $enrolled->forceFill(['auth_token' => 'issued-after-enrollment'])->save();

        $recovered = NezhaMerchantTwoFactor::consumeRecoveryCode(
            $enrolled,
            $result['recovery_codes'][0],
            1
        );

        $this->assertFalse($recovered->two_factor_enabled);
        $this->assertNull($recovered->two_factor_secret);
        $this->assertNull($recovered->two_factor_recovery_codes);
        $this->assertNull($recovered->auth_token);
        $this->assertSame(2, $recovered->auth_generation);
        $this->assertSame(NezhaMerchantTwoFactor::STATE_ENROLLMENT, NezhaMerchantTwoFactor::state($recovered));

        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::consumeRecoveryCode($recovered, $result['recovery_codes'][0], 2);
    }

    public function test_sensitive_step_up_requires_password_and_fresh_totp_not_recovery(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $enrollmentCounter = (int) floor(time() / 30);
        $result = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $enrollmentCounter),
            0
        );
        $nextCode = NezhaTotp::codeAt($secret, $enrollmentCounter + 1);

        NezhaMerchantTwoFactor::verifySensitiveStepUp(
            $result['actor'],
            'Correct-Horse-9!',
            $nextCode
        );
        $this->assertSame(
            $enrollmentCounter + 1,
            Vendor::findOrFail(1)->two_factor_last_counter
        );

        foreach ([$nextCode, $result['recovery_codes'][0]] as $replayOrRecovery) {
            try {
                NezhaMerchantTwoFactor::verifySensitiveStepUp(
                    Vendor::findOrFail(1),
                    'Correct-Horse-9!',
                    $replayOrRecovery
                );
                $this->fail('Sensitive step-up unexpectedly accepted a replay or recovery code.');
            } catch (\DomainException $exception) {
                $this->assertSame('merchant_2fa_step_up_failed', $exception->getMessage());
            }
        }
    }

    public function test_app_ticket_is_hashed_short_lived_and_consumed_once_before_token_issue(): void
    {
        $vendor = Vendor::findOrFail(1);
        $challenge = NezhaMerchantTwoFactor::startAppChallenge($vendor, '127.0.0.8');

        $row = MerchantTwoFactorChallenge::firstOrFail();
        $this->assertSame('enroll', $row->purpose);
        $this->assertNotSame($challenge['challenge_token'], $row->token_hash);
        $this->assertSame(hash('sha256', $challenge['challenge_token']), $row->token_hash);
        $this->assertTrue($row->expires_at->isFuture());

        $completed = NezhaMerchantTwoFactor::completeAppChallenge(
            $challenge['challenge_token'],
            NezhaTotp::codeAt($challenge['secret'], (int) floor(time() / 30)),
            '127.0.0.8'
        );
        $this->assertSame('authenticated', $completed['status']);
        $this->assertTrue(MerchantTwoFactorChallenge::firstOrFail()->consumed_at->isPast());

        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::completeAppChallenge(
            $challenge['challenge_token'],
            NezhaTotp::codeAt($challenge['secret'], (int) floor(time() / 30)),
            '127.0.0.8'
        );
    }
}
