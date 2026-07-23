<?php

namespace Tests\Feature;

use App\Exceptions\EmailLoginException;
use App\Mail\CustomerEmailVerificationCode;
use App\Models\User;
use App\Services\Auth\CustomerAccessTokenIssuer;
use App\Services\Auth\EmailLoginService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class EmailLoginServiceTest extends TestCase
{
    private CustomerAccessTokenIssuer $tokenIssuer;

    private EmailLoginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Cache::flush();
        Mail::fake();

        config()->set([
            'app.key' => 'base64:email-auth-test-key',
            'cache.default' => 'array',
            'mail.status' => true,
            'nezha_email_auth.enabled' => true,
            'nezha_email_auth.allow_new_accounts' => true,
            'nezha_email_auth.challenge_ttl_seconds' => 600,
            'nezha_email_auth.max_code_attempts' => 5,
            'nezha_email_auth.start_email_limit' => 5,
            'nezha_email_auth.start_ip_limit' => 10,
            'nezha_email_auth.start_decay_seconds' => 600,
        ]);

        $this->tokenIssuer = Mockery::mock(CustomerAccessTokenIssuer::class);
        $this->tokenIssuer->shouldReceive('issue')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (User $user) => 'token-for-user-'.$user->id);
        $this->service = new EmailLoginService($this->tokenIssuer);
    }

    public function test_new_email_is_created_and_authenticated_when_code_is_verified(): void
    {
        $start = $this->service->begin(
            'abcdefghijklmnopqrstuv@Example.com',
            '127.0.0.1',
            'zh-CN',
            true,
        );
        Mail::assertSent(
            CustomerEmailVerificationCode::class,
            fn ($mail) => $mail->hasTo('abcdefghijklmnopqrstuv@example.com'),
        );
        $this->assertSame(0, DB::table('users')->count());

        $result = $this->service->verify(
            $start['challenge_id'],
            $start['browser_secret'],
            '123456',
        );

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('email_otp', $result['login_type']);
        $this->assertDatabaseHas('users', [
            'email' => 'abcdefghijklmnopqrstuv@example.com',
            'f_name' => 'abcdefghijklmnopqrst',
            'l_name' => '',
            'is_email_verified' => 1,
            'login_medium' => 'email_otp',
        ]);
        $this->assertNull(DB::table('users')->value('password'));
    }

    public function test_start_requires_terms_and_keeps_database_empty_when_declined(): void
    {
        try {
            $this->service->begin(
                'terms@example.com',
                '127.0.0.20',
                'zh-CN',
                false,
            );
            $this->fail('Email login unexpectedly ignored the agreement requirement.');
        } catch (EmailLoginException $error) {
            $this->assertSame('terms_required', $error->errorCode);
        }

        $this->assertSame(0, DB::table('users')->count());
        Mail::assertNothingSent();
    }

    public function test_verified_existing_email_logs_in_without_creating_another_user(): void
    {
        $user = $this->createUser('verified@example.com', true, 'old-password');
        $start = $this->service->begin(
            'VERIFIED@example.com',
            '127.0.0.2',
            termsAccepted: true,
        );
        $result = $this->service->verify(
            $start['challenge_id'],
            $start['browser_secret'],
            '123456',
        );

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('token-for-user-'.$user->id, $result['token']);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_unverified_historical_email_logs_in_and_invalidates_its_old_password(): void
    {
        $user = $this->createUser('legacy@example.com', false, 'correct-password');
        $oldPassword = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertTrue(Hash::check('correct-password', $oldPassword));

        $start = $this->service->begin(
            'legacy@example.com',
            '127.0.0.3',
            termsAccepted: true,
        );
        $result = $this->service->verify(
            $start['challenge_id'],
            $start['browser_secret'],
            '123456',
        );

        $newPassword = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertSame('token-for-user-'.$user->id, $result['token']);
        $this->assertSame(1, (int) DB::table('users')->where('id', $user->id)->value('is_email_verified'));
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('email_verified_at'));
        $this->assertNotSame($oldPassword, $newPassword);
        $this->assertFalse(Hash::check('correct-password', $newPassword));
    }

    public function test_multiple_accounts_with_the_same_email_require_support(): void
    {
        $this->createUser('conflict@example.com', true, 'first-password');
        $this->createUser('conflict@example.com', false, 'second-password');
        $start = $this->service->begin(
            'conflict@example.com',
            '127.0.0.30',
            termsAccepted: true,
        );

        try {
            $this->service->verify(
                $start['challenge_id'],
                $start['browser_secret'],
                '123456',
            );
            $this->fail('A duplicate email identity unexpectedly logged in.');
        } catch (EmailLoginException $error) {
            $this->assertSame('identity_conflict', $error->errorCode);
        }

        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_code_attempt_limit_locks_the_challenge_without_creating_a_user(): void
    {
        $start = $this->service->begin(
            'locked@example.com',
            '127.0.0.4',
            termsAccepted: true,
        );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->service->verify(
                    $start['challenge_id'],
                    $start['browser_secret'],
                    '000000',
                );
                $this->fail('Incorrect code unexpectedly passed.');
            } catch (EmailLoginException $error) {
                $this->assertSame(
                    $attempt === 5 ? 'email_auth_locked' : 'email_auth_code_invalid',
                    $error->errorCode,
                );
            }
        }

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_challenge_cannot_be_used_from_a_different_browser_secret(): void
    {
        $start = $this->service->begin(
            'browser-bound@example.com',
            '127.0.0.40',
            termsAccepted: true,
        );

        try {
            $this->service->verify(
                $start['challenge_id'],
                'different-browser-secret',
                '123456',
            );
            $this->fail('A different browser unexpectedly used the email challenge.');
        } catch (EmailLoginException $error) {
            $this->assertSame('email_auth_expired', $error->errorCode);
        }

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_email_rate_limit_stops_repeated_delivery_without_creating_users(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->service->begin(
                'rate-limited@example.com',
                '127.0.0.41',
                termsAccepted: true,
            );
        }

        try {
            $this->service->begin(
                'rate-limited@example.com',
                '127.0.0.42',
                termsAccepted: true,
            );
            $this->fail('Email delivery rate limit unexpectedly allowed a sixth request.');
        } catch (EmailLoginException $error) {
            $this->assertSame('email_auth_rate_limited', $error->errorCode);
        }

        Mail::assertSent(CustomerEmailVerificationCode::class, 5);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_registration_gate_does_not_create_an_account_after_email_verification(): void
    {
        config()->set('nezha_email_auth.allow_new_accounts', false);
        $start = $this->service->begin(
            'gated@example.com',
            '127.0.0.43',
            termsAccepted: true,
        );
        $result = $this->service->verify(
            $start['challenge_id'],
            $start['browser_secret'],
            '123456',
        );

        $this->assertSame('registration_unavailable', $result['status']);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_consumed_challenge_cannot_be_replayed(): void
    {
        $user = $this->createUser('replay@example.com', true, 'old-password');
        $start = $this->service->begin(
            'replay@example.com',
            '127.0.0.5',
            termsAccepted: true,
        );
        $this->service->verify(
            $start['challenge_id'],
            $start['browser_secret'],
            '123456',
        );

        try {
            $this->service->verify(
                $start['challenge_id'],
                $start['browser_secret'],
                '123456',
            );
            $this->fail('Consumed email challenge was replayed.');
        } catch (EmailLoginException $error) {
            $this->assertSame('email_auth_expired', $error->errorCode);
        }

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame($user->id, DB::table('users')->value('id'));
    }

    public function test_legacy_signup_endpoint_is_closed_while_verified_email_auth_is_enabled(): void
    {
        config()->set('mail.status', false);
        $response = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Bypass User',
            'email' => 'bypass@example.com',
            'phone' => '+37499000111',
            'password' => 'password123',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'email_verification_required');
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_email_routes_and_telegram_routes_use_isolated_named_limiters(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $expected = [
            'api/v1/auth/email/start' => 'throttle:nezha_email_start',
            'api/v1/auth/email/verify' => 'throttle:nezha_email_verify',
            'api/v1/auth/telegram/start' => 'throttle:nezha_tg_start',
            'api/v1/auth/telegram/callback' => 'throttle:nezha_tg_callback',
            'api/v1/auth/telegram/exchange' => 'throttle:nezha_tg_exchange',
            'api/v1/auth/telegram/link/password' => 'throttle:nezha_tg_link_password',
            'api/v1/auth/telegram/link/google' => 'throttle:nezha_tg_link_google',
        ];

        foreach ($expected as $uri => $middleware) {
            $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);
            $this->assertNotNull($route, "Missing route {$uri}");
            $this->assertContains($middleware, $route->gatherMiddleware());
        }

        $this->assertSame(count($expected), count(array_unique($expected)));
    }

    public function test_start_endpoint_rejects_missing_implicit_agreement(): void
    {
        $this->postJson('/api/v1/auth/email/start', [
            'email' => 'terms-http@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['terms_accepted']);

        $this->assertSame(0, DB::table('users')->count());
        Mail::assertNothingSent();
    }

    public function test_verification_mail_renders_configured_ttl_and_platform_brand(): void
    {
        $mail = new CustomerEmailVerificationCode('123456', 'en', 420);
        $html = $mail->render();

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('Verify your email', $html);
        $this->assertStringContainsString('expires in 7 minutes', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('哪吒外卖', $html);
    }

    private function createUser(
        string $email,
        bool $verified,
        ?string $password,
    ): User {
        $id = DB::table('users')->insertGetId([
            'f_name' => 'Existing',
            'l_name' => 'Customer',
            'email' => $email,
            'phone' => null,
            'password' => $password === null ? null : Hash::make($password),
            'is_phone_verified' => 0,
            'is_email_verified' => $verified ? 1 : 0,
            'email_verified_at' => $verified ? now() : null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->without('storage')->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('users');

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
            $table->string('password')->nullable();
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('status')->default(true);
            $table->string('login_medium')->nullable();
            $table->string('ref_code')->nullable();
            $table->unsignedBigInteger('ref_by')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }
}
