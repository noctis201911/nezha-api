<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureCustomerCors;
use App\Http\Middleware\IssueCustomerBrowserSession;
use App\Http\Middleware\RequireTrustedCustomerLoginOrigin;
use App\Models\CustomerBrowserSession;
use App\Models\User;
use App\Services\Auth\CustomerAccessTokenIssuer;
use App\Services\Auth\CustomerBrowserSessionManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Token;
use Laravel\Passport\TokenRepository;
use Mockery;
use Tests\TestCase;

class CustomerBrowserSessionTest extends TestCase
{
    private TokenRepository $tokens;

    private CustomerBrowserSessionManager $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->createIsolatedSchema();
        config()->set('nezha_customer_browser_auth.enabled', true);
        config()->set('nezha_customer_browser_auth.idle_days', 30);
        config()->set('nezha_customer_browser_auth.absolute_days', 90);
        config()->set('nezha_customer_browser_auth.max_sessions_per_user', 5);
        config()->set('nezha_customer_browser_auth.touch_interval_minutes', 5);

        $this->tokens = Mockery::mock(TokenRepository::class);
        $this->sessions = new CustomerBrowserSessionManager($this->tokens);
        Cookie::getFacadeRoot()->flushQueuedCookies();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cookie::getFacadeRoot()->flushQueuedCookies();
        parent::tearDown();
    }

    public function test_cookie_is_host_only_http_only_secure_and_csrf_is_stable_across_tabs(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $user = $this->user();
        $this->bindRequest(Request::create('/api/v1/auth/login', 'POST'));

        $issued = $this->sessions->issueForLogin($user, 'access-token-1');
        $cookie = collect(Cookie::getQueuedCookies())->last();

        $this->assertSame('__Host-nezha_customer_session', $cookie->getName());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertNotSame(
            $cookie->getValue(),
            $issued['session']->token_hash
        );
        $this->assertSame(
            hash('sha256', $cookie->getValue()),
            $issued['session']->token_hash
        );

        Cookie::getFacadeRoot()->flushQueuedCookies();
        $tabOne = Request::create(
            '/api/v1/customer/info',
            'GET',
            [],
            [$cookie->getName() => $cookie->getValue()]
        );
        $this->bindRequest($tabOne);
        $this->assertNotNull($this->sessions->authenticate($tabOne));
        $csrfOne = $this->sessions->csrfToken($tabOne);

        $tabTwo = Request::create(
            '/api/v1/customer/info',
            'GET',
            [],
            [$cookie->getName() => $cookie->getValue()]
        );
        $this->bindRequest($tabTwo);
        $this->assertNotNull($this->sessions->authenticate($tabTwo));

        $this->assertSame($csrfOne, $this->sessions->csrfToken($tabTwo));
        $this->assertSame($issued['csrf_token'], $csrfOne);
    }

    public function test_sixth_login_revokes_only_the_oldest_active_session(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $user = $this->user();

        for ($index = 0; $index < 6; $index++) {
            Carbon::setTestNow(now()->addMinute());
            $this->bindRequest(Request::create('/api/v1/auth/login', 'POST'));
            $this->sessions->issueForLogin(
                $user,
                'access-token-'.$index
            );
        }

        $this->assertSame(
            5,
            CustomerBrowserSession::query()->whereNull('revoked_at')->count()
        );
        $this->assertNotNull(
            CustomerBrowserSession::query()->oldest('id')->first()->revoked_at
        );
        $this->assertNull(
            CustomerBrowserSession::query()->latest('id')->first()->revoked_at
        );
    }

    public function test_legacy_migration_never_outlives_legacy_token_and_revokes_on_confirmation(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $user = $this->user();
        $legacyToken = (new Token)->forceFill([
            'id' => 'legacy-token-1',
            'expires_at' => now()->addDays(12),
        ]);
        $request = Request::create(
            '/api/v1/auth/session/migrate',
            'POST'
        );
        $this->bindRequest($request);

        $issued = $this->sessions->issueFromLegacyToken($user, $legacyToken);

        $this->assertSame(
            now()->addDays(12)->toDateTimeString(),
            $issued['session']->absolute_expires_at->toDateTimeString()
        );
        $this->assertSame(
            'legacy-token-1',
            $issued['session']->legacy_access_token_id
        );

        $this->tokens
            ->shouldReceive('revokeAccessToken')
            ->once()
            ->with('legacy-token-1');
        $this->assertTrue($this->sessions->confirmLegacyMigration($request));
        $this->assertNull($issued['session']->fresh()->legacy_access_token_id);
    }

    public function test_expired_cookie_is_rejected_and_server_session_is_revoked(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $user = $this->user();
        $this->bindRequest(Request::create('/api/v1/auth/login', 'POST'));
        $issued = $this->sessions->issueForLogin($user);
        $cookie = collect(Cookie::getQueuedCookies())->last();

        $issued['session']->forceFill([
            'idle_expires_at' => now()->subSecond(),
        ])->save();
        Cookie::getFacadeRoot()->flushQueuedCookies();

        $request = Request::create(
            '/api/v1/customer/info',
            'GET',
            [],
            [$cookie->getName() => $cookie->getValue()]
        );
        $this->bindRequest($request);

        $this->assertNull($this->sessions->authenticate($request));
        $this->assertNotNull($issued['session']->fresh()->revoked_at);
        $this->assertNotEmpty(Cookie::getQueuedCookies());
        $expiredCookie = collect(Cookie::getQueuedCookies())->last();
        $this->assertTrue($expiredCookie->isSecure());
        $this->assertTrue($expiredCookie->isHttpOnly());
        $this->assertTrue($expiredCookie->isCleared());
    }

    public function test_logout_resolves_and_revokes_a_presented_cookie_after_bearer_precedence(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');
        $user = $this->user();
        $this->bindRequest(Request::create('/api/v1/auth/login', 'POST'));
        $issued = $this->sessions->issueForLogin($user, 'legacy-token-1');
        $cookie = collect(Cookie::getQueuedCookies())->last();
        Cookie::getFacadeRoot()->flushQueuedCookies();

        // Simulate a request whose auth middleware chose Bearer first, leaving
        // the simultaneously presented Cookie unresolved.
        $logout = Request::create(
            '/api/v1/customer/logout',
            'POST',
            [],
            [$cookie->getName() => $cookie->getValue()]
        );
        $this->bindRequest($logout);
        $this->sessions->revokeCurrent($logout);

        $this->assertNotNull($issued['session']->fresh()->revoked_at);
        $this->assertTrue(
            collect(Cookie::getQueuedCookies())->last()->isCleared()
        );
    }

    public function test_login_origin_rejects_cross_site_browsers_but_keeps_native_clients(): void
    {
        config()->set(
            'nezha_customer_browser_auth.allowed_origins',
            ['https://nezha.am']
        );
        $middleware = new RequireTrustedCustomerLoginOrigin;
        $next = static fn (): \Illuminate\Http\Response => response('ok');

        $trusted = Request::create('/api/v1/auth/login', 'POST');
        $trusted->headers->set('Origin', 'https://nezha.am');
        $trusted->headers->set('X-Nezha-Customer-Cookie', '1');
        $this->assertSame(200, $middleware->handle($trusted, $next)->getStatusCode());
        $this->assertTrue($this->sessions->requestCanReceiveCookie($trusted));

        $oldH5 = Request::create('/api/v1/auth/login', 'POST');
        $oldH5->headers->set('Origin', 'https://nezha.am');
        $this->assertFalse($this->sessions->requestCanReceiveCookie($oldH5));

        $untrusted = Request::create('/api/v1/auth/login', 'POST');
        $untrusted->headers->set('Origin', 'https://attacker.example');
        $this->assertSame(403, $middleware->handle($untrusted, $next)->getStatusCode());

        $crossSiteWithoutOrigin = Request::create('/api/v1/auth/login', 'POST');
        $crossSiteWithoutOrigin->headers->set('Sec-Fetch-Site', 'cross-site');
        $this->assertSame(
            403,
            $middleware->handle($crossSiteWithoutOrigin, $next)->getStatusCode()
        );

        $native = Request::create('/api/v1/auth/login', 'POST');
        $this->assertSame(200, $middleware->handle($native, $next)->getStatusCode());
        $this->assertFalse($this->sessions->requestCanReceiveCookie($native));
    }

    public function test_credentialed_cors_is_scoped_to_customer_h5_origins(): void
    {
        config()->set(
            'nezha_customer_browser_auth.allowed_origins',
            ['https://nezha.am']
        );
        $middleware = new ConfigureCustomerCors;
        $next = static fn (): \Illuminate\Http\Response => response('ok');

        $customer = Request::create('/api/v1/customer/info', 'GET');
        $customer->headers->set('Origin', 'https://nezha.am');
        $middleware->handle($customer, $next);
        $this->assertSame(
            ['https://nezha.am'],
            config('cors.allowed_origins')
        );
        $this->assertTrue(config('cors.supports_credentials'));

        $vendor = Request::create('/api/v1/vendor/profile', 'GET');
        $vendor->headers->set('Origin', 'https://vendor.example');
        $middleware->handle($vendor, $next);
        $this->assertSame(['*'], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_login_response_issues_cookie_only_to_capable_customer_h5(): void
    {
        $user = $this->user();
        $token = 'login-token';
        $middleware = new IssueCustomerBrowserSession($this->sessions);
        $next = static fn (): \Illuminate\Http\JsonResponse => response()->json([
            'token' => $token,
        ]);

        $capableH5 = Request::create('/api/v1/auth/login', 'POST');
        $capableH5->headers->set('Origin', 'https://nezha.am');
        $capableH5->headers->set('X-Nezha-Customer-Cookie', '1');
        $capableH5->attributes->set(
            CustomerAccessTokenIssuer::REQUEST_TOKEN_HASH,
            hash('sha256', $token)
        );
        $capableH5->attributes->set(
            CustomerAccessTokenIssuer::REQUEST_USER_ID,
            $user->getKey()
        );
        $capableH5->attributes->set(
            CustomerAccessTokenIssuer::REQUEST_ACCESS_TOKEN_ID,
            'login-access-token-id'
        );
        $this->bindRequest($capableH5);
        $middleware->handle($capableH5, $next);

        $this->assertSame(1, CustomerBrowserSession::query()->count());
        $this->assertNotEmpty(Cookie::getQueuedCookies());

        CustomerBrowserSession::query()->delete();
        Cookie::getFacadeRoot()->flushQueuedCookies();
        $oldH5 = clone $capableH5;
        $oldH5->headers->remove('X-Nezha-Customer-Cookie');
        $this->bindRequest($oldH5);
        $middleware->handle($oldH5, $next);

        $this->assertSame(0, CustomerBrowserSession::query()->count());
        $this->assertEmpty(Cookie::getQueuedCookies());
    }

    private function bindRequest(Request $request): void
    {
        $this->app->instance('request', $request);
    }

    private function user(): User
    {
        DB::table('users')->insert([
            'id' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail(1);
    }

    private function createIsolatedSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->boolean('status')->default(true);
                $table->string('image')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('status')->default(true);
            });
        }
        if (! Schema::hasColumn('users', 'image')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('image')->nullable();
            });
        }
        if (! Schema::hasTable('storages')) {
            Schema::create('storages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('data_type');
                $table->unsignedBigInteger('data_id');
                $table->string('key');
                $table->string('value')->nullable();
                $table->timestamps();
            });
        }
        Schema::dropIfExists('customer_browser_sessions');
        Schema::create('customer_browser_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->text('csrf_token_encrypted');
            $table->string('legacy_access_token_id', 100)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('idle_expires_at');
            $table->timestamp('absolute_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::table('storages')->delete();
        DB::table('users')->delete();
    }
}
