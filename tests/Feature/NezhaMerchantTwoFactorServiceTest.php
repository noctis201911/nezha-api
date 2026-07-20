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

    public function test_disabled_actor_is_optional_regardless_of_legacy_schedule_fields(): void
    {
        $vendor = Vendor::findOrFail(1);
        $this->assertSame(NezhaMerchantTwoFactor::STATE_OPTIONAL, NezhaMerchantTwoFactor::state($vendor));

        foreach ([
            [true, null],
            [false, now()->addDay()],
            [false, now()->subDay()],
            [false, null],
        ] as [$gracePending, $requiredAt]) {
            $vendor->forceFill([
                'two_factor_grace_pending' => $gracePending,
                'two_factor_required_at' => $requiredAt,
            ])->save();
            $this->assertSame(
                NezhaMerchantTwoFactor::STATE_OPTIONAL,
                NezhaMerchantTwoFactor::state($vendor->fresh())
            );
        }

        $vendor->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => null])->save();
        $this->assertSame(
            NezhaMerchantTwoFactor::STATE_ENROLLMENT,
            NezhaMerchantTwoFactor::state($vendor->fresh()),
            'An inconsistent enabled actor must fail closed instead of silently downgrading to password-only.'
        );
    }

    public function test_enrollment_encrypts_secret_issues_no_recovery_codes_and_rejects_totp_replay(): void
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
        $this->assertArrayNotHasKey('recovery_codes', $result);
        $this->assertNull($enrolled->two_factor_recovery_codes);
        $this->assertNull(DB::table('vendors')->where('id', 1)->value('two_factor_recovery_codes'));

        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::verifyTotp(
            $enrolled,
            NezhaTotp::codeAt($secret, $counter),
            1
        );
    }

    public function test_self_service_disable_requires_password_and_fresh_totp_then_revokes_sessions(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $counter = (int) floor(time() / 30);
        $enrolled = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $counter),
            0
        )['actor'];
        $enrolled->forceFill(['auth_token' => 'issued-after-enrollment'])->save();

        // Wrong password is refused even with a valid code.
        try {
            NezhaMerchantTwoFactor::disableTwoFactor(
                $enrolled,
                'Wrong-Password-1!',
                NezhaTotp::codeAt($secret, $counter + 1)
            );
            $this->fail('Disable unexpectedly accepted a wrong password.');
        } catch (\DomainException $e) {
            $this->assertSame('merchant_2fa_step_up_failed', $e->getMessage());
        }

        // A replayed code is refused even with the right password.
        try {
            NezhaMerchantTwoFactor::disableTwoFactor(
                $enrolled->fresh(),
                'Correct-Horse-9!',
                NezhaTotp::codeAt($secret, $counter)
            );
            $this->fail('Disable unexpectedly accepted a replayed TOTP code.');
        } catch (\DomainException $e) {
            $this->assertSame('merchant_2fa_step_up_failed', $e->getMessage());
        }

        $this->assertTrue($enrolled->fresh()->two_factor_enabled, 'Failed attempts must not disable 2FA.');

        $disabled = NezhaMerchantTwoFactor::disableTwoFactor(
            $enrolled->fresh(),
            'Correct-Horse-9!',
            NezhaTotp::codeAt($secret, $counter + 1)
        );

        $this->assertFalse($disabled->two_factor_enabled);
        $this->assertNull($disabled->two_factor_secret);
        $this->assertNull($disabled->auth_token);
        $this->assertSame(NezhaMerchantTwoFactor::STATE_OPTIONAL, NezhaMerchantTwoFactor::state($disabled));
        $this->assertSame(
            1,
            DB::table('merchant_two_factor_events')->where('event_type', 'disabled_by_merchant')->count()
        );

        // Already disabled: the entry point closes instead of silently succeeding.
        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::disableTwoFactor($disabled->fresh(), 'Correct-Horse-9!', '000000');
    }

    public function test_sensitive_step_up_requires_password_and_a_fresh_non_replayed_totp(): void
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

        // A replayed code and a recovery-code-shaped string are both refused.
        foreach ([$nextCode, 'A3F91-7C2E0'] as $replayOrBearerCode) {
            try {
                NezhaMerchantTwoFactor::verifySensitiveStepUp(
                    Vendor::findOrFail(1),
                    'Correct-Horse-9!',
                    $replayOrBearerCode
                );
                $this->fail('Sensitive step-up unexpectedly accepted a replay or recovery-shaped code.');
            } catch (\DomainException $exception) {
                $this->assertSame('merchant_2fa_step_up_failed', $exception->getMessage());
            }
        }
    }

    public function test_disabled_actor_sensitive_step_up_requires_password_but_not_totp(): void
    {
        $vendor = Vendor::findOrFail(1);

        $verified = NezhaMerchantTwoFactor::verifySensitiveStepUp(
            $vendor,
            'Correct-Horse-9!',
            null
        );
        $this->assertSame($vendor->id, $verified->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('merchant_2fa_step_up_failed');
        NezhaMerchantTwoFactor::verifySensitiveStepUp($vendor, 'Wrong-Horse-9!', null);
    }

    public function test_app_ticket_is_hashed_short_lived_and_consumed_once_before_token_issue(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $enrolled = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, (int) floor(time() / 30)),
            0
        )['actor'];
        $challenge = NezhaMerchantTwoFactor::startAppChallenge($enrolled, '127.0.0.8');

        $row = MerchantTwoFactorChallenge::firstOrFail();
        $this->assertSame('challenge', $row->purpose);
        $this->assertNotSame($challenge['challenge_token'], $row->token_hash);
        $this->assertSame(hash('sha256', $challenge['challenge_token']), $row->token_hash);
        $this->assertTrue($row->expires_at->isFuture());

        $completed = NezhaMerchantTwoFactor::completeAppChallenge(
            $challenge['challenge_token'],
            NezhaTotp::codeAt($secret, (int) floor(time() / 30) + 1),
            '127.0.0.8'
        );
        $this->assertSame('authenticated', $completed['status']);
        $this->assertTrue(MerchantTwoFactorChallenge::firstOrFail()->consumed_at->isPast());

        $this->expectException(\DomainException::class);
        NezhaMerchantTwoFactor::completeAppChallenge(
            $challenge['challenge_token'],
            NezhaTotp::codeAt($secret, (int) floor(time() / 30) + 2),
            '127.0.0.8'
        );
    }

    public function test_app_challenge_has_no_recovery_fallback_and_never_downgrades_two_factor(): void
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $counter = (int) floor(time() / 30);
        $enrollment = NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $counter),
            0
        );
        $challenge = NezhaMerchantTwoFactor::startAppChallenge($enrollment['actor'], '127.0.0.8');

        try {
            NezhaMerchantTwoFactor::completeAppChallenge(
                $challenge['challenge_token'],
                'A3F91-7C2E0',
                '127.0.0.8'
            );
            $this->fail('App challenge unexpectedly accepted a recovery-code-shaped input.');
        } catch (\DomainException $exception) {
            $this->assertSame('merchant_2fa_invalid_code', $exception->getMessage());
        }

        $actor = Vendor::findOrFail(1);
        $this->assertTrue($actor->two_factor_enabled, 'A rejected code must never disable two-factor.');
        $this->assertSame($secret, $actor->two_factor_secret);
        $this->assertSame(NezhaMerchantTwoFactor::STATE_CHALLENGE, NezhaMerchantTwoFactor::state($actor));
        $this->assertSame(
            0,
            DB::table('merchant_two_factor_events')->where('event_type', 'recovery_code_consumed')->count()
        );
        $this->assertSame(1, MerchantTwoFactorChallenge::query()->whereNull('consumed_at')->count());
    }
}
