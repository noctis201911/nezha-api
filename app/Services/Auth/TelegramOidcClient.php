<?php

namespace App\Services\Auth;

use App\Exceptions\TelegramLoginException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramOidcClient
{
    public function isConfigured(): bool
    {
        return (bool) config('telegram_login.enabled')
            && $this->hasValue(config('telegram_login.client_id'))
            && $this->hasValue(config('telegram_login.client_secret'))
            && $this->hasValue(config('telegram_login.redirect_uri'))
            && $this->hasValue(config('telegram_login.frontend_uri'));
    }

    public function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new TelegramLoginException(
                'telegram_not_available',
                'Telegram login is not available.',
                503,
            );
        }
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $this->assertConfigured();

        return rtrim((string) config('telegram_login.authorization_endpoint'), '?').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('telegram_login.client_id'),
            'redirect_uri' => (string) config('telegram_login.redirect_uri'),
            'scope' => 'openid profile phone',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $expectedNonce): array
    {
        $this->assertConfigured();

        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('telegram_login.client_id'),
                    (string) config('telegram_login.client_secret'),
                )
                ->connectTimeout(5)
                ->timeout(8)
                ->post((string) config('telegram_login.token_endpoint'), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => (string) config('telegram_login.redirect_uri'),
                    'client_id' => (string) config('telegram_login.client_id'),
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (Throwable) {
            throw new TelegramLoginException(
                'telegram_exchange_failed',
                'Telegram authorization could not be completed.',
                502,
            );
        }

        $idToken = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            throw new TelegramLoginException(
                'telegram_exchange_failed',
                'Telegram authorization could not be completed.',
                403,
            );
        }

        return $this->verifyIdToken($idToken, $expectedNonce);
    }

    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $claims = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $keys = JWK::parseKeySet($this->jwks($attempt === 1));
                $previousLeeway = JWT::$leeway;
                JWT::$leeway = 30;
                try {
                    $claims = (array) JWT::decode($idToken, $keys);
                } finally {
                    JWT::$leeway = $previousLeeway;
                }
                break;
            } catch (Throwable) {
                // Refresh JWKS once to handle provider key rotation.
            }
        }

        if (! is_array($claims)) {
            throw new TelegramLoginException(
                'telegram_invalid_token',
                'Telegram identity token is invalid.',
                403,
            );
        }

        $issuer = rtrim((string) ($claims['iss'] ?? ''), '/');
        $expectedIssuer = rtrim((string) config('telegram_login.issuer'), '/');
        if ($issuer === '' || ! hash_equals($expectedIssuer, $issuer)) {
            throw new TelegramLoginException('telegram_invalid_token', 'Telegram token issuer mismatch.', 403);
        }

        $audiences = is_array($claims['aud'] ?? null)
            ? $claims['aud']
            : [($claims['aud'] ?? null)];
        $audiences = array_map('strval', array_filter($audiences, fn ($value) => $value !== null));
        if (! in_array((string) config('telegram_login.client_id'), $audiences, true)) {
            throw new TelegramLoginException('telegram_invalid_token', 'Telegram token audience mismatch.', 403);
        }

        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce) || ! hash_equals($expectedNonce, $nonce)) {
            throw new TelegramLoginException('telegram_invalid_token', 'Telegram token nonce mismatch.', 403);
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 191) {
            throw new TelegramLoginException('telegram_invalid_token', 'Telegram token subject is invalid.', 403);
        }

        return $claims;
    }

    private function jwks(bool $forceRefresh): array
    {
        $cacheKey = 'telegram_oidc_jwks_v1';
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        try {
            $jwks = Cache::remember(
                $cacheKey,
                max(60, (int) config('telegram_login.jwks_ttl_seconds', 21600)),
                function () {
                    $response = Http::acceptJson()
                        ->connectTimeout(5)
                        ->timeout(8)
                        ->get((string) config('telegram_login.jwks_uri'));

                    if (! $response->successful() || ! is_array($response->json('keys'))) {
                        throw new TelegramLoginException(
                            'telegram_jwks_unavailable',
                            'Telegram signing keys are unavailable.',
                            502,
                        );
                    }

                    return $response->json();
                }
            );
        } catch (TelegramLoginException $error) {
            throw $error;
        } catch (Throwable) {
            throw new TelegramLoginException(
                'telegram_jwks_unavailable',
                'Telegram signing keys are unavailable.',
                502,
            );
        }

        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new TelegramLoginException(
                'telegram_jwks_unavailable',
                'Telegram signing keys are unavailable.',
                502,
            );
        }

        return $jwks;
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
