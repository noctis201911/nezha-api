<?php

namespace Tests\Feature;

use App\Mail\EmailVerification;
use App\Models\User;
use App\Services\Auth\CustomerAuthFeatureFlags;
use App\Services\Auth\CustomerEmailAuthService;
use App\Services\Auth\CustomerLoginFinalizer;
use App\Services\Auth\EmailCanonicalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class CustomerEmailAuthServiceTest extends TestCase
{
    private CustomerEmailAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        config()->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'mail.status' => true,
            'customer_auth.terms_version' => 'terms-2026-07-22',
            'customer_auth.privacy_version' => 'privacy-2026-07-22',
            'customer_auth.challenge_ttl_seconds' => 600,
            'customer_auth.challenge_resend_seconds' => 60,
            'customer_auth.challenge_max_attempts' => 5,
        ]);
        foreach ([
            'email_auth_login_status',
            'email_auth_registration_status',
            'email_auth_mail_status',
        ] as $key) {
            DB::table('business_settings')->insert([
                'key' => $key,
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $finalizer = Mockery::mock(CustomerLoginFinalizer::class);
        $finalizer->shouldReceive('issue')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (User $user) => 'token-for-'.$user->id);
        $this->service = new CustomerEmailAuthService(
            app(CustomerAuthFeatureFlags::class),
            $finalizer,
            app(EmailCanonicalizer::class),
        );
        Mail::fake();
    }

    public function test_verified_existing_owner_logs_in_without_account_enumeration_before_code(): void
    {
        $user = $this->createUser('Owner@Example.com', 'owner@example.com', true);
        [$attempt, $otp] = $this->startAndReadOtp(' OWNER@example.com ');

        $this->assertSame('code_sent', $attempt['status']);
        $this->assertArrayNotHasKey('is_existing_user', $attempt);

        $result = $this->service->verify(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $otp,
        );

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('token-for-'.$user->id, $result['token']);
        $this->assertDatabaseHas('customer_email_auth_challenges', [
            'public_id' => $attempt['challenge_id'],
            'status' => 'consumed',
        ]);
    }

    public function test_new_verified_email_requires_explicit_terms_then_creates_phone_null_account(): void
    {
        [$attempt, $otp] = $this->startAndReadOtp('new@example.com');
        $verified = $this->service->verify(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $otp,
        );

        $this->assertSame('registration_required', $verified['status']);
        $result = $this->service->completeRegistration(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $verified['completion_token'],
            '测试 用户',
            true,
            'zh-CN',
            null,
        );

        $this->assertSame('authenticated', $result['status']);
        $user = User::query()->without('storage')->where('email_canonical', 'new@example.com')->firstOrFail();
        $this->assertNull($user->phone);
        $this->assertNull($user->password);
        $this->assertSame(1, (int) $user->is_email_verified);
        $this->assertDatabaseHas('customer_auth_consents', [
            'user_id' => $user->id,
            'action' => 'account_created',
            'terms_version' => 'terms-2026-07-22',
        ]);
    }

    public function test_legacy_email_needs_old_password_and_revokes_existing_tokens_before_login(): void
    {
        $user = $this->createUser('legacy@example.com', null, false, 'old-password');
        DB::table('oauth_access_tokens')->insert([
            'id' => 'old-access',
            'user_id' => $user->id,
            'revoked' => 0,
        ]);
        [$attempt, $otp] = $this->startAndReadOtp('legacy@example.com');
        $verified = $this->service->verify(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $otp,
        );

        $this->assertSame('legacy_link_required', $verified['status']);
        $this->assertArrayNotHasKey('user_id', $verified);
        $result = $this->service->proveLegacyPassword(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $verified['completion_token'],
            'old-password',
        );

        $this->assertSame('authenticated', $result['status']);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'old-access', 'revoked' => 1]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email_canonical' => 'legacy@example.com',
            'is_email_verified' => 1,
        ]);
    }

    public function test_ambiguous_legacy_email_never_selects_an_account_for_password_claim(): void
    {
        $this->createUser('duplicate@example.com', null, false, 'first-password');
        $this->createUser('DUPLICATE@example.com', null, false, 'second-password');
        [$attempt, $otp] = $this->startAndReadOtp('duplicate@example.com');

        $result = $this->service->verify(
            $attempt['challenge_id'],
            $attempt['browser_secret'],
            $otp,
        );

        $this->assertSame('legacy_link_required', $result['status']);
        $this->assertFalse($result['can_use_password']);
        $this->assertTrue($result['support_required']);
        $this->assertDatabaseHas('customer_email_auth_challenges', [
            'public_id' => $attempt['challenge_id'],
            'target_user_id' => null,
            'status' => 'legacy_link_required',
        ]);
    }

    public function test_wrong_code_decrements_persistently_and_never_creates_user(): void
    {
        [$attempt] = $this->startAndReadOtp('wrong@example.com');

        try {
            $this->service->verify(
                $attempt['challenge_id'],
                $attempt['browser_secret'],
                '000000',
            );
            $this->fail('Expected the wrong code to be rejected.');
        } catch (\App\Exceptions\CustomerLoginException $error) {
            $this->assertSame('email_auth_code_invalid', $error->errorCode);
        }

        $this->assertDatabaseHas('customer_email_auth_challenges', [
            'public_id' => $attempt['challenge_id'],
            'attempts_remaining' => 4,
            'status' => 'code_sent',
        ]);
        $this->assertDatabaseCount('users', 0);
    }

    private function startAndReadOtp(string $email): array
    {
        $result = $this->service->start($email);
        $otp = null;
        Mail::assertSent(EmailVerification::class, function (EmailVerification $mail) use (&$otp) {
            $property = new ReflectionProperty($mail, 'reset_url');
            $property->setAccessible(true);
            $otp = (string) $property->getValue($mail);

            return true;
        });

        return [$result, $otp];
    }

    private function createUser(
        string $email,
        ?string $canonical,
        bool $verified,
        ?string $password = null,
    ): User {
        $id = DB::table('users')->insertGetId([
            'f_name' => 'Test',
            'email' => $email,
            'email_canonical' => $canonical,
            'email_verified_at' => $verified ? now() : null,
            'email_verification_method' => $verified ? 'test' : null,
            'is_email_verified' => $verified ? 1 : 0,
            'is_phone_verified' => 0,
            'password' => $password ? Hash::make($password) : null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->without('storage')->findOrFail($id);
    }

    private function createSchema(): void
    {
        foreach ([
            'customer_auth_consents',
            'customer_email_auth_challenges',
            'oauth_refresh_tokens',
            'oauth_access_tokens',
            'external_identity_login_attempts',
            'business_settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name', 100)->nullable();
            $table->string('l_name', 100)->nullable();
            $table->string('phone')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('email_canonical', 191)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_method', 32)->nullable();
            $table->boolean('is_email_verified')->default(false);
            $table->boolean('is_phone_verified')->default(false);
            $table->string('password')->nullable();
            $table->boolean('status')->default(true);
            $table->string('login_medium')->nullable();
            $table->string('ref_code')->nullable();
            $table->unsignedBigInteger('ref_by')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_email_auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 43)->unique();
            $table->string('purpose', 32);
            $table->text('email_ciphertext');
            $table->char('email_lookup_hash', 64)->index();
            $table->char('active_email_hash', 64)->nullable()->unique();
            $table->char('otp_hash', 64);
            $table->char('browser_secret_hash', 64);
            $table->char('completion_token_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('status', 32);
            $table->unsignedTinyInteger('attempts_remaining');
            $table->unsignedInteger('generation');
            $table->text('registration_payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('resend_after');
            $table->timestamp('delivery_succeeded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_auth_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->string('terms_version', 64);
            $table->string('privacy_version', 64);
            $table->string('locale', 16);
            $table->string('channel', 32);
            $table->string('auth_method', 32);
            $table->timestamp('accepted_at');
        });
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->boolean('revoked')->default(false);
        });
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('access_token_id');
            $table->boolean('revoked')->default(false);
        });
        Schema::create('external_identity_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('status', 32);
            $table->text('provider_payload')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }
}
