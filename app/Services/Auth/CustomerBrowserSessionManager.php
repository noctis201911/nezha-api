<?php

namespace App\Services\Auth;

use App\Models\CustomerBrowserSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;
use Laravel\Passport\TokenRepository;
use Throwable;

class CustomerBrowserSessionManager
{
    public const REQUEST_SESSION = 'nezha.customer_browser_session';

    public const REQUEST_CSRF = 'nezha.customer_browser_csrf';

    public const REQUEST_SOURCE = 'nezha.customer_auth_source';

    public function __construct(private readonly TokenRepository $tokens) {}

    public function enabled(): bool
    {
        return (bool) config('nezha_customer_browser_auth.enabled', false);
    }

    public function requestCanReceiveCookie(Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $origin = rtrim((string) $request->header('Origin'), '/');
        $cookieCapable = hash_equals(
            '1',
            (string) $request->header('X-Nezha-Customer-Cookie')
        );

        return $cookieCapable
            && $origin !== ''
            && in_array(
                $origin,
                config('nezha_customer_browser_auth.allowed_origins', []),
                true
            );
    }

    /**
     * @return array{session: CustomerBrowserSession, csrf_token: string}
     */
    public function issueForLogin(
        User $user,
        ?string $issuedAccessTokenId = null,
    ): array {
        return $this->issue(
            $user,
            null,
            $issuedAccessTokenId,
        );
    }

    /**
     * The migrated Cookie session can never outlive either the legacy token
     * that authenticated the migration or the approved 90-day absolute cap.
     *
     * @return array{session: CustomerBrowserSession, csrf_token: string}
     */
    public function issueFromLegacyToken(User $user, Token $legacyToken): array
    {
        $legacyExpiry = $legacyToken->expires_at instanceof Carbon
            ? $legacyToken->expires_at->copy()
            : Carbon::parse($legacyToken->expires_at);
        $absoluteCap = now()->addDays(
            (int) config('nezha_customer_browser_auth.absolute_days', 90)
        );

        return $this->issue(
            $user,
            $legacyExpiry->lessThan($absoluteCap) ? $legacyExpiry : $absoluteCap,
            (string) $legacyToken->id,
        );
    }

    public function authenticate(
        Request $request,
        bool $requireTrustedBrowser = true,
    ): ?CustomerBrowserSession {
        if (! $this->enabled()) {
            return null;
        }

        $rawToken = $request->cookie($this->cookieName());
        if (! is_string($rawToken) || $rawToken === '') {
            return null;
        }
        if ($requireTrustedBrowser && ! $this->requestCanReceiveCookie($request)) {
            return null;
        }

        $session = CustomerBrowserSession::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        $now = now();
        if (
            ! $session
            || $session->revoked_at
            || ! $session->user
            || ! (bool) $session->user->status
            || $session->idle_expires_at->lessThanOrEqualTo($now)
            || $session->absolute_expires_at->lessThanOrEqualTo($now)
        ) {
            if ($session && ! $session->revoked_at) {
                $session->forceFill([
                    'revoked_at' => $now,
                    'legacy_access_token_id' => null,
                ])->save();
            }
            $this->forgetCookie();

            return null;
        }

        try {
            $csrfToken = Crypt::decryptString($session->csrf_token_encrypted);
        } catch (Throwable) {
            $session->forceFill([
                'revoked_at' => $now,
                'legacy_access_token_id' => null,
            ])->save();
            $this->forgetCookie();

            return null;
        }

        $request->attributes->set(self::REQUEST_SESSION, $session);
        $request->attributes->set(self::REQUEST_CSRF, $csrfToken);

        $touchBefore = $now->copy()->subMinutes(
            (int) config(
                'nezha_customer_browser_auth.touch_interval_minutes',
                5
            )
        );
        if ($session->last_seen_at->lessThanOrEqualTo($touchBefore)) {
            $idleExpiry = $now->copy()->addDays(
                (int) config('nezha_customer_browser_auth.idle_days', 30)
            );
            if ($idleExpiry->greaterThan($session->absolute_expires_at)) {
                $idleExpiry = $session->absolute_expires_at->copy();
            }

            $session->forceFill([
                'last_seen_at' => $now,
                'idle_expires_at' => $idleExpiry,
            ])->save();
            $this->queueCookie($rawToken, $idleExpiry);
        }

        return $session;
    }

    public function csrfToken(Request $request): ?string
    {
        $csrf = $request->attributes->get(self::REQUEST_CSRF);

        return is_string($csrf) && $csrf !== '' ? $csrf : null;
    }

    public function csrfIsValid(Request $request): bool
    {
        $expected = $this->csrfToken($request);
        $provided = $request->header('X-CSRF-Token');

        return is_string($expected)
            && is_string($provided)
            && $provided !== ''
            && hash_equals($expected, $provided);
    }

    public function current(Request $request): ?CustomerBrowserSession
    {
        $session = $request->attributes->get(self::REQUEST_SESSION);

        return $session instanceof CustomerBrowserSession ? $session : null;
    }

    public function revokeCurrent(Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        // A transitional request can carry both a legacy Bearer and a Cookie.
        // Bearer precedence means the auth middleware has not resolved the
        // Cookie yet, but an explicit browser logout must revoke both.
        $source = $request->attributes->get(self::REQUEST_SOURCE);
        $session = $this->current($request) ?? $this->authenticate(
            $request,
            $source !== 'bearer',
        );
        if ($session && ! $session->revoked_at) {
            $session->forceFill([
                'revoked_at' => now(),
                'legacy_access_token_id' => null,
            ])->save();
        }

        $this->forgetCookie();
    }

    public function confirmLegacyMigration(Request $request): bool
    {
        $session = $this->current($request);
        $legacyTokenId = $session?->legacy_access_token_id;

        if (! $session || ! $legacyTokenId) {
            return false;
        }

        DB::transaction(function () use ($session, $legacyTokenId): void {
            $locked = CustomerBrowserSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->legacy_access_token_id) {
                return;
            }

            $this->tokens->revokeAccessToken((string) $legacyTokenId);
            $locked->forceFill(['legacy_access_token_id' => null])->save();
        });

        return true;
    }

    /**
     * @return array{session: CustomerBrowserSession, csrf_token: string}
     */
    private function issue(
        User $user,
        ?Carbon $absoluteExpiry = null,
        ?string $legacyAccessTokenId = null,
    ): array {
        if (! $this->enabled()) {
            throw new \LogicException(
                'Customer browser sessions are not enabled.'
            );
        }

        $now = now();
        $absoluteExpiry ??= $now->copy()->addDays(
            (int) config('nezha_customer_browser_auth.absolute_days', 90)
        );
        if ($absoluteExpiry->lessThanOrEqualTo($now)) {
            throw new \LogicException(
                'Cannot migrate an expired customer access token.'
            );
        }

        $idleExpiry = $now->copy()->addDays(
            (int) config('nezha_customer_browser_auth.idle_days', 30)
        );
        if ($idleExpiry->greaterThan($absoluteExpiry)) {
            $idleExpiry = $absoluteExpiry->copy();
        }

        $rawToken = $this->randomToken();
        $csrfToken = $this->randomToken();
        $maxSessions = (int) config(
            'nezha_customer_browser_auth.max_sessions_per_user',
            5
        );

        $session = DB::transaction(function () use (
            $user,
            $rawToken,
            $csrfToken,
            $legacyAccessTokenId,
            $now,
            $idleExpiry,
            $absoluteExpiry,
            $maxSessions,
        ): CustomerBrowserSession {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($legacyAccessTokenId !== null) {
                CustomerBrowserSession::query()
                    ->where('legacy_access_token_id', $legacyAccessTokenId)
                    ->where(function ($query) use ($now): void {
                        $query->whereNotNull('revoked_at')
                            ->orWhere('idle_expires_at', '<=', $now)
                            ->orWhere('absolute_expires_at', '<=', $now);
                    })
                    ->update([
                        'legacy_access_token_id' => null,
                        'updated_at' => $now,
                    ]);

                $migrationInProgress = CustomerBrowserSession::query()
                    ->where('legacy_access_token_id', $legacyAccessTokenId)
                    ->whereNull('revoked_at')
                    ->where('idle_expires_at', '>', $now)
                    ->where('absolute_expires_at', '>', $now)
                    ->exists();

                if ($migrationInProgress) {
                    throw new LegacyCustomerTokenMigrationConflict(
                        'This legacy token already has an active migration.'
                    );
                }
            }

            $activeIds = CustomerBrowserSession::query()
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->where('idle_expires_at', '>', $now)
                ->where('absolute_expires_at', '>', $now)
                ->orderByDesc('last_seen_at')
                ->pluck('id');

            $idsToRevoke = $activeIds->slice(max(0, $maxSessions - 1));
            if ($idsToRevoke->isNotEmpty()) {
                CustomerBrowserSession::query()
                    ->whereIn('id', $idsToRevoke)
                    ->update([
                        'revoked_at' => $now,
                        'legacy_access_token_id' => null,
                        'updated_at' => $now,
                    ]);
            }

            return CustomerBrowserSession::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $rawToken),
                'csrf_token_encrypted' => Crypt::encryptString($csrfToken),
                'legacy_access_token_id' => $legacyAccessTokenId,
                'last_seen_at' => $now,
                'idle_expires_at' => $idleExpiry,
                'absolute_expires_at' => $absoluteExpiry,
            ]);
        });

        request()->attributes->set(self::REQUEST_SESSION, $session);
        request()->attributes->set(self::REQUEST_CSRF, $csrfToken);
        $this->queueCookie($rawToken, $idleExpiry);

        return ['session' => $session, 'csrf_token' => $csrfToken];
    }

    private function queueCookie(string $value, Carbon $expiresAt): void
    {
        $minutes = max(1, now()->diffInMinutes($expiresAt));

        Cookie::queue(Cookie::make(
            $this->cookieName(),
            $value,
            $minutes,
            '/',
            null,
            true,
            true,
            false,
            (string) config(
                'nezha_customer_browser_auth.cookie.same_site',
                'strict'
            ),
        ));
    }

    private function forgetCookie(): void
    {
        // A __Host- cookie must include Secure even on the expiring Set-Cookie
        // line; Cookie::forget() omits it and browsers may reject the deletion.
        Cookie::queue(Cookie::make(
            $this->cookieName(),
            '',
            -2628000,
            '/',
            null,
            true,
            true,
            false,
            (string) config(
                'nezha_customer_browser_auth.cookie.same_site',
                'strict'
            ),
        ));
    }

    private function cookieName(): string
    {
        return (string) config(
            'nezha_customer_browser_auth.cookie.name',
            '__Host-nezha_customer_session'
        );
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
