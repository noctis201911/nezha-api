<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\CentralLogics\NezhaTotp;
use App\Http\Controllers\MerchantTwoFactorController;
use App\Http\Middleware\VendorMiddleware;
use App\Http\Middleware\VendorTokenIsValid;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaMerchantTwoFactorAuthFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }

        if (! Schema::hasColumn('vendors', 'status')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->boolean('status')->default(true);
            });
        }
        if (! Schema::hasColumn('vendors', 'auth_token')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->string('auth_token', 191)->nullable();
            });
        }
        if (! Schema::hasColumn('vendor_employees', 'auth_token')) {
            Schema::table('vendor_employees', function (Blueprint $table): void {
                $table->string('auth_token', 191)->nullable();
            });
        }
        if (! Schema::hasTable('data_settings')) {
            Schema::create('data_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key');
                $table->string('type')->nullable();
                $table->string('value');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('employee_roles')) {
            Schema::create('employee_roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->text('modules')->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        DB::table('data_settings')->updateOrInsert(
            ['key' => 'restaurant_login_url'],
            ['value' => 'vendor-login', 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('vendors')->where('id', 1)->update([
            'email' => 'merchant-2fa@example.test',
            'password' => Hash::make('Correct-Horse-9!'),
            'status' => true,
            'auth_token' => null,
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_recovery_codes' => null,
            'two_factor_required_at' => now(),
            'two_factor_enrolled_at' => null,
            'two_factor_last_counter' => null,
            'auth_generation' => 0,
            'two_factor_grace_pending' => false,
        ]);
        DB::table('employee_roles')->updateOrInsert(['id' => 1], [
            'name' => 'Fixture merchant role',
            'modules' => '["food"]',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'merchant-app-login:ip:'.\App\CentralLogics\NezhaMerchantTwoFactor::requestHash('127.0.0.1'),
            'merchant-app-login:account:'.hash('sha256', 'owner:merchant-2fa@example.test'),
            'merchant-app-2fa-start:ip:'.\App\CentralLogics\NezhaMerchantTwoFactor::requestHash('127.0.0.1'),
            'merchant-app-2fa-start:account:'.hash('sha256', 'owner:1'),
        ] as $rateKey) {
            RateLimiter::clear($rateKey);
        }
    }

    public function test_web_password_success_signs_in_and_two_factor_setup_is_voluntary(): void
    {
        $response = $this->withSession([
            'six_captcha' => 'ABCDE',
            'six_captcha_list' => ['ABCDE'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
            'custome_recaptcha' => 'ABCDE',
            'set_default_captcha' => 1,
        ]);

        $response->assertRedirect(route('vendor.dashboard'));
        $this->assertTrue(Auth::guard('vendor')->check());
        $response->assertSessionMissing(MerchantTwoFactorController::PENDING_ID);
        $response->assertSessionHas(MerchantTwoFactorController::SESSION_GENERATION, 0);

        $setup = $this->get(route('merchant.2fa.setup'));
        $setup->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('开启两步验证（可选）');
        $encryptedSecret = session('merchant_2fa.setup_secret');
        $secret = Crypt::decryptString($encryptedSecret);
        $this->assertNotEmpty($secret);
        $this->assertStringNotContainsString($secret, $encryptedSecret);

        $cancelled = $this->post(route('merchant.2fa.cancel'));
        $cancelled->assertRedirect(route('vendor.profile.view'));
        $this->assertTrue(Auth::guard('vendor')->check());
        $this->assertFalse(Vendor::findOrFail(1)->two_factor_enabled);

        $this->get(route('merchant.2fa.setup'))->assertOk();
        $secret = Crypt::decryptString(session('merchant_2fa.setup_secret'));
        $enabled = $this->post(route('merchant.2fa.enable'), [
            'code' => NezhaTotp::codeAt($secret, (int) floor(time() / 30)),
        ]);
        $enabled->assertRedirect(route('merchant.2fa.setup'));
        $this->assertTrue(Auth::guard('vendor')->check());
        $enabled->assertSessionHas(MerchantTwoFactorController::SESSION_GENERATION, 1);
        $enabled->assertSessionHas(MerchantTwoFactorController::SESSION_PASSED_GENERATION, 1);
        $this->assertTrue(Vendor::findOrFail(1)->two_factor_enabled);
    }

    public function test_app_password_success_for_disabled_owner_returns_token_without_challenge(): void
    {
        $login = $this->postJson('/api/v1/auth/vendor/login', [
            'vendor_type' => 'owner',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
        ]);

        $login->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['token'])
            ->assertJsonMissing(['challenge_token', 'setup', 'recovery_codes']);
        $this->assertNotNull(DB::table('vendors')->where('id', 1)->value('auth_token'));
    }

    public function test_enabled_owner_web_login_still_requires_two_factor(): void
    {
        [$secret, $counter] = $this->enableVendorTwoFactor();

        $login = $this->withSession([
            'six_captcha' => 'ABCDE',
            'six_captcha_list' => ['ABCDE'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
            'custome_recaptcha' => 'ABCDE',
            'set_default_captcha' => 1,
        ]);

        $login->assertRedirect(route('merchant.2fa.challenge'));
        $this->assertFalse(Auth::guard('vendor')->check());
        $login->assertSessionHas(MerchantTwoFactorController::PENDING_ID, 1);

        $verified = $this->post(route('merchant.2fa.verify'), [
            'code' => NezhaTotp::codeAt($secret, $counter + 1),
        ]);
        $verified->assertRedirect(route('vendor.dashboard'));
        $this->assertTrue(Auth::guard('vendor')->check());
        $verified->assertSessionHas(MerchantTwoFactorController::SESSION_PASSED_GENERATION, 1);
    }

    public function test_web_login_has_no_recovery_code_downgrade_path(): void
    {
        $this->enableVendorTwoFactor();

        $this->withSession([
            'six_captcha' => 'ABCDE',
            'six_captcha_list' => ['ABCDE'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
            'custome_recaptcha' => 'ABCDE',
            'set_default_captcha' => 1,
        ])->assertRedirect(route('merchant.2fa.challenge'));

        $rejected = $this->post(route('merchant.2fa.verify'), ['code' => 'A3F91-7C2E0']);
        $rejected->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('vendor')->check(), 'A recovery-shaped code must not sign anyone in.');

        $vendor = Vendor::findOrFail(1);
        $this->assertTrue($vendor->two_factor_enabled, 'A rejected code must never disable two-factor.');
        $this->assertNotNull($vendor->two_factor_secret);
        $this->assertSame(1, $vendor->auth_generation, 'A rejected code must not rotate the generation.');
    }

    public function test_authenticated_merchant_can_disable_two_factor_with_password_and_code(): void
    {
        // Enrol one step back so the challenge and the later disable each get a
        // strictly increasing counter that still sits inside the +/-1 TOTP window.
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $counter = (int) floor(time() / 30) - 1;
        NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $counter),
            0
        );

        $this->withSession([
            'six_captcha' => 'ABCDE',
            'six_captcha_list' => ['ABCDE'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
            'custome_recaptcha' => 'ABCDE',
            'set_default_captcha' => 1,
        ])->assertRedirect(route('merchant.2fa.challenge'));
        $this->post(route('merchant.2fa.verify'), [
            'code' => NezhaTotp::codeAt($secret, $counter + 1),
        ])->assertRedirect(route('vendor.dashboard'));

        // The management page lists both actions collapsed.
        $this->get(route('merchant.2fa.setup'))
            ->assertOk()
            ->assertSee('两步验证已开启')
            ->assertSee('关闭两步验证')
            ->assertSee('更换验证器');

        // Wrong password is refused, leaves two-factor on, and re-opens the panel
        // it came from so the error is not shown next to a collapsed form.
        $this->post(route('merchant.2fa.disable'), [
            'current_password' => 'Wrong-Password-1!',
            'code' => NezhaTotp::codeAt($secret, $counter + 2),
        ])->assertSessionHasErrors('code')
            ->assertSessionHas('merchant_2fa.open_panel', 'disable');
        $this->assertTrue(Vendor::findOrFail(1)->two_factor_enabled);

        $disabled = $this->post(route('merchant.2fa.disable'), [
            'current_password' => 'Correct-Horse-9!',
            'code' => NezhaTotp::codeAt($secret, $counter + 2),
        ]);
        $disabled->assertRedirect(route('merchant.2fa.setup'));

        $vendor = Vendor::findOrFail(1);
        $this->assertFalse($vendor->two_factor_enabled);
        $this->assertNull($vendor->two_factor_secret);
        $this->assertSame(
            NezhaMerchantTwoFactor::STATE_OPTIONAL,
            NezhaMerchantTwoFactor::state($vendor)
        );
        // The browser session survives its own revocation so the merchant is not bounced.
        $this->assertTrue(Auth::guard('vendor')->check());
        $disabled->assertSessionHas(MerchantTwoFactorController::SESSION_GENERATION, (int) $vendor->auth_generation);
    }

    public function test_disabled_employee_app_password_success_returns_token_without_challenge(): void
    {
        DB::table('vendor_employees')->insert([
            'id' => 71,
            'f_name' => 'Employee',
            'email' => 'merchant-employee-2fa@example.test',
            'phone' => 'merchant-employee-2fa',
            'employee_role_id' => 1,
            'vendor_id' => 1,
            'restaurant_id' => 6,
            'password' => Hash::make('Employee-Horse-9!'),
            'status' => true,
            'two_factor_enabled' => false,
            'two_factor_required_at' => now(),
            'auth_generation' => 0,
            'two_factor_grace_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/v1/auth/vendor/login', [
            'vendor_type' => 'employee',
            'email' => 'merchant-employee-2fa@example.test',
            'password' => 'Employee-Horse-9!',
        ]);

        $login->assertOk()
            ->assertJsonStructure(['token', 'role'])
            ->assertJsonMissing(['challenge_token', 'setup', 'recovery_codes']);
        $this->assertNotNull(VendorEmployee::findOrFail(71)->auth_token);
        $this->assertNull(Vendor::findOrFail(1)->auth_token);
    }

    public function test_app_plan_write_requires_post_2fa_owner_token_and_restaurant_ownership(): void
    {
        [$secret, $counter] = $this->enableVendorTwoFactor();
        DB::table('vendors')->where('id', 1)->update(['status' => false]);
        DB::table('restaurants')->where('id', 6)->update([
            'status' => false,
            'restaurant_model' => 'none',
        ]);
        DB::table('restaurants')->insert([
            'id' => 72,
            'name' => 'Other merchant restaurant',
            'vendor_id' => 999,
            'status' => false,
            'restaurant_model' => 'none',
            'delivery_time' => '10-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/vendor/business_plan', [
            'restaurant_id' => 6,
            'business_plan' => 'commission',
        ])->assertUnauthorized();

        $login = $this->postJson('/api/v1/auth/vendor/login', [
            'vendor_type' => 'owner',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
        ]);
        $login->assertStatus(202)->assertJsonPath('purpose', 'challenge')->assertJsonMissing(['token', 'setup.secret']);
        $verified = $this->postJson('/api/v1/auth/vendor/two-factor/verify', [
            'challenge_token' => $login->json('challenge_token'),
            'code' => NezhaTotp::codeAt(
                $secret,
                $counter + 1
            ),
        ]);
        $verified->assertOk();
        $token = $verified->json('subscribed.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->withHeader('vendorType', 'owner')
            ->postJson('/api/v1/vendor/business_plan', [
                'restaurant_id' => 72,
                'business_plan' => 'commission',
            ])->assertForbidden();
        $this->assertSame('none', DB::table('restaurants')->where('id', 72)->value('restaurant_model'));

        $this->withToken($token)->withHeader('vendorType', 'owner')
            ->postJson('/api/v1/vendor/business_plan', [
                'restaurant_id' => 6,
                'business_plan' => 'commission',
            ])->assertOk()
            ->assertJsonPath('restaurant_model', 'commission');
        $this->assertSame('commission', DB::table('restaurants')->where('id', 6)->value('restaurant_model'));
    }

    public function test_app_challenge_account_limit_trips_before_the_ip_limit(): void
    {
        $this->enableVendorTwoFactor();
        $login = $this->postJson('/api/v1/auth/vendor/login', [
            'vendor_type' => 'owner',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
        ]);
        $token = $login->json('challenge_token');
        $accountKey = \App\CentralLogics\NezhaMerchantTwoFactor::challengeAccountRateKey($token);
        $ipKey = 'merchant-app-2fa:ip:'.\App\CentralLogics\NezhaMerchantTwoFactor::requestHash('127.0.0.1');
        $this->assertNotNull($accountKey);
        RateLimiter::clear($accountKey);
        RateLimiter::clear($ipKey);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/vendor/two-factor/verify', [
                'challenge_token' => $token,
                'code' => '000000',
            ])->assertUnauthorized()->assertJsonMissing(['token']);
        }

        $this->assertSame(5, RateLimiter::attempts($accountKey));
        $this->assertSame(5, RateLimiter::attempts($ipKey));
        $this->postJson('/api/v1/auth/vendor/two-factor/verify', [
            'challenge_token' => $token,
            'code' => '111111',
        ])->assertUnauthorized()->assertJsonMissing(['token']);
    }

    public function test_app_challenge_restart_is_bounded_and_rate_limited_per_actor_and_ip(): void
    {
        $this->enableVendorTwoFactor();
        $firstToken = null;
        foreach (range(1, 5) as $attempt) {
            $response = $this->postJson('/api/v1/auth/vendor/login', [
                'vendor_type' => 'owner',
                'email' => 'merchant-2fa@example.test',
                'password' => 'Correct-Horse-9!',
            ])->assertStatus(202)->assertJsonMissing(['token']);
            $firstToken ??= $response->json('challenge_token');
        }

        $this->assertSame(
            1,
            DB::table('merchant_two_factor_challenges')->whereNull('consumed_at')->count()
        );
        $this->assertLessThanOrEqual(2, DB::table('merchant_two_factor_challenges')->count());

        $this->postJson('/api/v1/auth/vendor/login', [
            'vendor_type' => 'owner',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
        ])->assertUnauthorized()->assertJsonMissing(['challenge_token', 'token']);

        $this->postJson('/api/v1/auth/vendor/two-factor/verify', [
            'challenge_token' => $firstToken,
            'code' => '000000',
        ])->assertUnauthorized()->assertJsonMissing(['token']);
    }

    public function test_interrupted_onboarding_requires_password_but_does_not_force_two_factor(): void
    {
        DB::table('vendors')->where('id', 1)->update(['status' => false]);
        DB::table('restaurants')->where('id', 6)->update([
            'status' => false,
            'restaurant_model' => 'none',
        ]);

        $this->post('/restaurant/business-plan', [
            'restaurant_id' => 6,
            'business_plan' => 'commission-base',
        ])->assertForbidden();
        $this->withSession([
            MerchantTwoFactorController::ONBOARDING_RESTAURANT_ID => 6,
            MerchantTwoFactorController::ONBOARDING_AUTHORIZED => false,
        ])->post('/restaurant/business-plan', [
            'restaurant_id' => 6,
            'business_plan' => 'commission-base',
        ])->assertForbidden();
        session()->flush();

        $badPassword = $this->withSession([
            'six_captcha' => 'ABCDE',
            'six_captcha_list' => ['ABCDE'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Wrong-Horse-9!',
            'custome_recaptcha' => 'ABCDE',
            'set_default_captcha' => 1,
        ]);
        $badPassword->assertRedirect();
        $badPassword->assertSessionMissing(MerchantTwoFactorController::PENDING_ID);
        $badPassword->assertSessionHas(MerchantTwoFactorController::ONBOARDING_AUTHORIZED, false);
        $this->assertSame('none', DB::table('restaurants')->where('id', 6)->value('restaurant_model'));

        $login = $this->withSession([
            'six_captcha' => 'FGHIJ',
            'six_captcha_list' => ['FGHIJ'],
        ])->post('/login_submit', [
            'role' => 'vendor',
            'email' => 'merchant-2fa@example.test',
            'password' => 'Correct-Horse-9!',
            'custome_recaptcha' => 'FGHIJ',
            'set_default_captcha' => 1,
        ]);
        $login->assertRedirect(route('restaurant.business_plan'));
        $login->assertSessionHas(MerchantTwoFactorController::ONBOARDING_RESTAURANT_ID, 6);
        $login->assertSessionHas(MerchantTwoFactorController::ONBOARDING_AUTHORIZED, true);
        $login->assertSessionMissing(MerchantTwoFactorController::PENDING_ID);

        $planRequest = Request::create('/restaurant/business-plan', 'POST', [
            'restaurant_id' => 6,
            'business_plan' => 'commission-base',
        ]);
        $planRequest->setLaravelSession(app('session.store'));
        $view = (new \App\Http\Controllers\VendorController)->business_plan($planRequest);
        $this->assertSame('vendor-views.auth.register-complete', $view->name());
        $this->assertFalse(session()->has(MerchantTwoFactorController::ONBOARDING_RESTAURANT_ID));
        $this->assertFalse(session()->has(MerchantTwoFactorController::ONBOARDING_AUTHORIZED));
        $this->assertSame('commission', DB::table('restaurants')->where('id', 6)->value('restaurant_model'));
    }

    public function test_legacy_schedule_does_not_reject_tokens_but_stale_web_generation_does(): void
    {
        $vendor = Vendor::findOrFail(1);
        $vendor->forceFill([
            'two_factor_grace_pending' => true,
            'two_factor_required_at' => null,
            'auth_token' => 'legacy-grace-token',
        ])->save();

        $apiRequest = Request::create('/api/v1/vendor/profile', 'GET');
        $apiRequest->headers->set('Authorization', 'Bearer legacy-grace-token');
        $apiRequest->headers->set('vendorType', 'owner');
        $reached = false;
        $response = (new VendorTokenIsValid)->handle($apiRequest, function () use (&$reached) {
            $reached = true;

            return response()->noContent();
        });
        $this->assertTrue($reached);
        $this->assertSame(204, $response->getStatusCode());

        $vendor->forceFill([
            'two_factor_grace_pending' => false,
            'two_factor_required_at' => now(),
        ])->save();
        $reached = false;
        $response = (new VendorTokenIsValid)->handle($apiRequest, function () use (&$reached) {
            $reached = true;

            return response()->noContent();
        });
        $this->assertTrue($reached);
        $this->assertSame(204, $response->getStatusCode());

        $this->actingAs($vendor->fresh(), 'vendor');
        $webRequest = Request::create('/vendor', 'GET');
        $webRequest->setLaravelSession(app('session')->driver());
        $webRequest->session()->put(MerchantTwoFactorController::SESSION_GENERATION, 99);
        $response = (new VendorMiddleware)->handle($webRequest, fn () => response()->noContent());
        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(Auth::guard('vendor')->check());
    }

    public function test_two_factor_management_rejects_stale_generation_and_inactive_actor(): void
    {
        $vendor = Vendor::findOrFail(1);
        $this->actingAs($vendor, 'vendor');

        $this->withSession([
            MerchantTwoFactorController::SESSION_GENERATION => 99,
        ])->get(route('merchant.2fa.setup'))
            ->assertRedirect(route('login', ['tab' => 'vendor']));

        $vendor->forceFill(['status' => false])->save();
        $this->actingAs($vendor->fresh(), 'vendor');
        $this->withSession([
            MerchantTwoFactorController::SESSION_GENERATION => 0,
        ])->get(route('merchant.2fa.setup'))
            ->assertRedirect(route('login', ['tab' => 'vendor']));
    }

    public function test_web_logout_clears_onboarding_authority_from_the_browser_session(): void
    {
        $this->actingAs(Vendor::findOrFail(1), 'vendor');

        $response = $this->withSession([
            MerchantTwoFactorController::SESSION_GENERATION => 0,
            MerchantTwoFactorController::ONBOARDING_RESTAURANT_ID => 6,
            MerchantTwoFactorController::ONBOARDING_AUTHORIZED => true,
        ])->get('/logout');

        $response->assertRedirect();
        $response->assertSessionMissing(MerchantTwoFactorController::SESSION_GENERATION);
        $response->assertSessionMissing(MerchantTwoFactorController::ONBOARDING_RESTAURANT_ID);
        $response->assertSessionMissing(MerchantTwoFactorController::ONBOARDING_AUTHORIZED);
        $this->assertFalse(Auth::guard('vendor')->check());

        $this->post('/restaurant/business-plan', [
            'restaurant_id' => 6,
            'business_plan' => 'commission-base',
        ])->assertForbidden();
    }

    public function test_merchant_login_template_has_no_persistent_authentication_control(): void
    {
        $source = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertStringContainsString('@if ($isVendor)', $source);
        $this->assertStringContainsString('merchant login is not kept across browser sessions', $source);
        $this->assertStringContainsString('name="remember"', $source);
        $this->assertStringNotContainsString('value="{{ $password', $source);
    }

    private function enableVendorTwoFactor(): array
    {
        $vendor = Vendor::findOrFail(1);
        $secret = NezhaTotp::generateSecret();
        $counter = (int) floor(time() / 30);
        NezhaMerchantTwoFactor::completeEnrollment(
            $vendor,
            $secret,
            NezhaTotp::codeAt($secret, $counter),
            0
        );

        return [$secret, $counter];
    }
}
