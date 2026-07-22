<?php

namespace Tests\Feature;

use App\Exceptions\CustomerLoginException;
use App\Models\User;
use App\Services\Auth\CustomerAuthFeatureFlags;
use App\Services\Auth\CustomerLoginFinalizer;
use App\Services\Auth\EmailCanonicalizer;
use App\Services\Auth\GoogleLoginService;
use App\Services\Auth\GoogleTokenVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class GoogleLoginServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_canonical')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_method')->nullable();
            $table->boolean('is_email_verified')->default(false);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('status')->default(true);
            $table->string('login_medium')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->string('provider_subject');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function test_verified_canonical_owner_is_bound_by_subject_and_logged_in(): void
    {
        $user = $this->createUser('Owner@Example.com', 'owner@example.com', true);
        $service = $this->service([
            'sub' => 'google-subject-1',
            'email' => 'OWNER@example.com',
            'email_verified' => 'true',
            'given_name' => 'Owner',
        ]);

        $result = $service->authenticateCredential('credential');

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('token-for-'.$user->id, $result['token']);
        $this->assertDatabaseHas('user_external_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'google-subject-1',
        ]);
    }

    public function test_unverified_legacy_email_is_never_taken_over_by_google(): void
    {
        $this->createUser('legacy@example.com', null, false);
        $service = $this->service([
            'sub' => 'google-subject-2',
            'email' => 'legacy@example.com',
            'email_verified' => true,
        ], expectedTokenCalls: 0);

        $this->expectExceptionObject(new CustomerLoginException(
            'legacy_link_required',
            'Use the existing password or contact support to recover this account.',
            409,
        ));

        $service->authenticateCredential('credential');
    }

    public function test_unknown_google_identity_cannot_create_an_account_while_registration_is_off(): void
    {
        $service = $this->service([
            'sub' => 'google-subject-3',
            'email' => 'new@example.com',
            'email_verified' => true,
        ], expectedTokenCalls: 0);

        try {
            $service->authenticateCredential('credential');
            $this->fail('Expected registration to remain closed.');
        } catch (CustomerLoginException $error) {
            $this->assertSame('registration_unavailable', $error->errorCode);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_external_identities', 0);
    }

    private function service(array $profile, int $expectedTokenCalls = 1): GoogleLoginService
    {
        $verifier = Mockery::mock(GoogleTokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->with('credential')->andReturn($profile);
        $finalizer = Mockery::mock(CustomerLoginFinalizer::class);
        $expectation = $finalizer->shouldReceive('issue');
        if ($expectedTokenCalls === 0) {
            $expectation->never();
        } else {
            $expectation->once()->andReturnUsing(fn (User $user) => 'token-for-'.$user->id);
        }

        return new GoogleLoginService(
            $verifier,
            app(EmailCanonicalizer::class),
            app(CustomerAuthFeatureFlags::class),
            $finalizer,
        );
    }

    private function createUser(string $email, ?string $canonical, bool $verified): User
    {
        $id = DB::table('users')->insertGetId([
            'f_name' => 'Test',
            'email' => $email,
            'email_canonical' => $canonical,
            'email_verified_at' => $verified ? now() : null,
            'email_verification_method' => $verified ? 'test' : null,
            'is_email_verified' => $verified ? 1 : 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->without('storage')->findOrFail($id);
    }
}
