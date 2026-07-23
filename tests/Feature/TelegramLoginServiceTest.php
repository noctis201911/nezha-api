<?php

namespace Tests\Feature;

use App\Exceptions\TelegramLoginException;
use App\Models\User;
use App\Services\Auth\CustomerAccessTokenIssuer;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\TelegramLoginService;
use App\Services\Auth\TelegramOidcClient;
use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TelegramLoginServiceTest extends TestCase
{
    private TelegramOidcClient $oidc;

    private GoogleTokenVerifier $google;

    private CustomerAccessTokenIssuer $tokenIssuer;

    private TelegramLoginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();

        config()->set([
            'telegram_login.enabled' => true,
            'telegram_login.allow_new_accounts' => true,
            'telegram_login.attempt_ttl_seconds' => 600,
        ]);

        $this->oidc = Mockery::mock(TelegramOidcClient::class);
        $this->google = Mockery::mock(GoogleTokenVerifier::class);
        $this->tokenIssuer = Mockery::mock(CustomerAccessTokenIssuer::class);
        $this->oidc->shouldReceive('assertConfigured')->zeroOrMoreTimes();
        $this->oidc->shouldReceive('authorizationUrl')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn ($state) => 'https://oauth.telegram.test/auth?state='.urlencode($state));
        $this->tokenIssuer->shouldReceive('issue')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (User $user) => 'token-for-user-'.$user->id);

        $this->service = new TelegramLoginService(
            $this->oidc,
            $this->google,
            $this->tokenIssuer,
        );
    }

    public function test_bound_subject_logs_in_its_owner_even_if_phone_now_points_elsewhere(): void
    {
        $owner = $this->createUser('+37499000001', 'owner@example.com');
        $other = $this->createUser('+37499000002', 'other@example.com');
        DB::table('user_external_identities')->insert([
            'user_id' => $owner->id,
            'provider' => 'telegram',
            'provider_subject' => 'tg-existing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-existing',
            $other->getRawOriginal('phone'),
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');
        $result = $this->service->exchange($code, $start['browser_secret']);

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('token-for-user-'.$owner->id, $result['token']);
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(1, DB::table('user_external_identities')->count());
    }

    public function test_phone_collision_requires_password_proof_then_binds_same_user(): void
    {
        $user = $this->createUser('+37499000003', 'customer@example.com', 'correct-password');
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-new',
            ltrim($user->getRawOriginal('phone'), '+'),
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');

        $pending = $this->service->exchange($code, $start['browser_secret']);
        $this->assertSame('link_required', $pending['status']);
        $this->assertTrue($pending['can_use_email']);
        $this->assertDatabaseCount('user_external_identities', 0);

        try {
            $this->service->linkWithPassword(
                $code,
                $start['browser_secret'],
                $user->getRawOriginal('email'),
                'wrong-password',
            );
            $this->fail('Wrong password unexpectedly linked Telegram.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_reauthentication_failed', $error->errorCode);
        }

        $result = $this->service->linkWithPassword(
            $code,
            $start['browser_secret'],
            $user->getRawOriginal('email'),
            'correct-password',
        );

        $this->assertSame('token-for-user-'.$user->id, $result['token']);
        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_subject' => 'tg-new',
        ]);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_google_link_must_match_the_exact_collision_target(): void
    {
        $user = $this->createUser('+37499000004', 'right@example.com');
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-google',
            $user->getRawOriginal('phone'),
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');
        $this->service->exchange($code, $start['browser_secret']);

        $this->google->shouldReceive('verify')->once()->with('google-wrong')->andReturn([
            'sub' => 'google-sub',
            'email' => 'wrong@example.com',
        ]);
        try {
            $this->service->linkWithGoogle($code, $start['browser_secret'], 'google-wrong');
            $this->fail('Wrong Google account unexpectedly linked Telegram.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_reauthentication_failed', $error->errorCode);
        }

        $this->google->shouldReceive('verify')->once()->with('google-right')->andReturn([
            'sub' => 'google-sub',
            'email' => 'right@example.com',
        ]);
        $result = $this->service->linkWithGoogle(
            $code,
            $start['browser_secret'],
            'google-right',
        );

        $this->assertSame('token-for-user-'.$user->id, $result['token']);
        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'tg-google',
        ]);
    }

    public function test_unmatched_verified_phone_creates_one_telegram_user_atomically(): void
    {
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-first-user',
            '37499000005',
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');
        $result = $this->service->exchange($code, $start['browser_secret']);

        $this->assertSame('authenticated', $result['status']);
        $this->assertDatabaseHas('users', [
            'phone' => '+37499000005',
            'is_phone_verified' => 1,
            'login_medium' => 'telegram',
        ]);
        $this->assertDatabaseHas('user_external_identities', [
            'provider' => 'telegram',
            'provider_subject' => 'tg-first-user',
        ]);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_registration_gate_can_keep_the_provider_bind_only(): void
    {
        config()->set('telegram_login.allow_new_accounts', false);
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-gated',
            '+37499000006',
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');
        $result = $this->service->exchange($code, $start['browser_secret']);

        $this->assertSame('registration_unavailable', $result['status']);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('user_external_identities')->count());
    }

    public function test_phone_claim_without_optional_verified_flag_is_accepted(): void
    {
        $claims = $this->claims('tg-standard-phone-claim', '37499000007');
        unset(
            $claims['phone_number_verified'],
            $claims['given_name'],
            $claims['family_name'],
        );
        [$start, $state] = $this->beginWithClaims($claims);

        $code = $this->service->completeCallback($state, 'telegram-code');
        $result = $this->service->exchange($code, $start['browser_secret']);

        $this->assertSame('authenticated', $result['status']);
        $this->assertDatabaseHas('users', [
            'phone' => '+37499000007',
            'f_name' => 'Telegram 用户',
            'login_medium' => 'telegram',
        ]);
    }

    public function test_explicitly_unverified_phone_is_rejected_without_creating_data(): void
    {
        $claims = $this->claims('tg-explicitly-unverified', '+37499000070');
        $claims['phone_number_verified'] = false;
        [$start, $state] = $this->beginWithClaims($claims);

        try {
            $this->service->completeCallback($state, 'telegram-code');
            $this->fail('Unverified phone unexpectedly completed Telegram callback.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_phone_required', $error->errorCode);
        }

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('user_external_identities')->count());
        $this->assertNotEmpty($start['browser_secret']);
    }

    public function test_missing_phone_claim_is_rejected_without_creating_data(): void
    {
        $claims = $this->claims('tg-missing-phone', '+37499000071');
        unset($claims['phone_number']);
        [$start, $state] = $this->beginWithClaims($claims);

        try {
            $this->service->completeCallback($state, 'telegram-code');
            $this->fail('Missing Telegram phone unexpectedly completed callback.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_phone_required', $error->errorCode);
        }

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('user_external_identities')->count());
        $this->assertNotEmpty($start['browser_secret']);
    }

    public function test_blocked_phone_owner_cannot_be_recreated_through_telegram(): void
    {
        $blocked = $this->createUser('+37499000008', 'blocked@example.com');
        DB::table('users')->where('id', $blocked->id)->update(['status' => 0]);
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-blocked',
            $blocked->getRawOriginal('phone'),
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');

        try {
            $this->service->exchange($code, $start['browser_secret']);
            $this->fail('Blocked account unexpectedly passed Telegram collision flow.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_account_blocked', $error->errorCode);
        }

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('user_external_identities')->count());
    }

    public function test_consumed_exchange_code_cannot_be_replayed(): void
    {
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-replay',
            '+37499000009',
        ));
        $code = $this->service->completeCallback($state, 'telegram-code');
        $this->service->exchange($code, $start['browser_secret']);

        try {
            $this->service->exchange($code, $start['browser_secret']);
            $this->fail('Consumed Telegram exchange code was replayed.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_login_expired', $error->errorCode);
        }
    }

    public function test_account_deletion_challenge_and_exchange_consumption_commit_together(): void
    {
        (require database_path('migrations/2026_07_22_090000_create_customer_account_deletion_lifecycle.php'))->up();

        $user = $this->createUser('+37499000010', 'deleting@example.com');
        $requestId = (string) Str::uuid();
        DB::table('customer_account_deletion_states')->insert([
            'user_id' => $user->id,
            'request_id' => $requestId,
            'source' => 'checkout',
            'status' => 'countdown',
            'purge_matrix_version' => 'v4-local',
            'copy_version' => 'v4-local',
            'copy_locale' => 'zh-CN',
            'requested_at' => now(),
            'blocker_mask' => 0,
            'obligation_epoch' => 1,
            'state_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_external_identities')->insert([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_subject' => 'tg-deleting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new TelegramLoginService(
            $this->oidc,
            $this->google,
            new CustomerAccessTokenIssuer(app(CustomerAccountDeletionService::class)),
        );
        [$start, $state] = $this->beginWithClaims($this->claims(
            'tg-deleting',
            $user->getRawOriginal('phone'),
        ));
        $code = $service->completeCallback($state, 'telegram-code');
        $result = $service->exchange($code, $start['browser_secret']);

        $this->assertSame(409, $result['_http_status']);
        $this->assertSame('ACCOUNT_DELETION_ACTIVE', $result['errors'][0]['code']);
        $this->assertNotEmpty(DB::table('customer_account_deletion_states')->where('request_id', $requestId)->value('challenge_hash'));
        $this->assertSame('consumed', DB::table('external_identity_login_attempts')->value('status'));
        $this->assertNotNull(DB::table('external_identity_login_attempts')->value('consumed_at'));
    }

    private function beginWithClaims(array $claims): array
    {
        $this->oidc->shouldReceive('exchangeAuthorizationCode')
            ->once()
            ->with('telegram-code', Mockery::type('string'), Mockery::type('string'))
            ->andReturn($claims);

        $start = $this->service->begin();
        parse_str((string) parse_url($start['authorization_url'], PHP_URL_QUERY), $query);

        return [$start, $query['state']];
    }

    private function claims(string $subject, string $phone): array
    {
        return [
            'iss' => 'https://oauth.telegram.org',
            'aud' => 'client-id',
            'sub' => $subject,
            'phone_number' => $phone,
            'phone_number_verified' => true,
            'given_name' => 'Telegram',
            'family_name' => 'Customer',
        ];
    }

    private function createUser(
        string $phone,
        ?string $email = null,
        ?string $password = 'password',
    ): User {
        $id = DB::table('users')->insertGetId([
            'f_name' => 'Existing',
            'l_name' => 'Customer',
            'phone' => $phone,
            'email' => $email,
            'password' => $password === null ? null : Hash::make($password),
            'is_phone_verified' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->without('storage')->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('external_identity_login_attempts');
        Schema::dropIfExists('user_external_identities');
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
            $table->string('phone')->nullable()->unique();
            $table->string('email', 191)->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('status')->default(true);
            $table->string('login_medium')->nullable();
            $table->string('ref_code')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('provider', 32);
            $table->string('provider_subject', 191);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('external_identity_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('state_hash', 64)->unique();
            $table->char('exchange_code_hash', 64)->nullable()->unique();
            $table->char('browser_secret_hash', 64);
            $table->string('oidc_nonce', 191)->nullable();
            $table->text('code_verifier')->nullable();
            $table->string('provider_subject', 191)->nullable();
            $table->text('provider_payload')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('status', 32)->default('initiated');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }
}
