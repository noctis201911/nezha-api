<?php

namespace Tests\Unit;

use App\Exceptions\TelegramLoginException;
use App\Services\Auth\TelegramOidcClient;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramOidcClientTest extends TestCase
{
    private string $privateKey;

    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'telegram_login.enabled' => true,
            'telegram_login.client_id' => 'telegram-client',
            'telegram_login.client_secret' => 'telegram-secret',
            'telegram_login.redirect_uri' => 'https://api.example.test/api/v1/auth/telegram/callback',
            'telegram_login.frontend_uri' => 'https://example.test/auth/telegram',
            'telegram_login.issuer' => 'https://oauth.telegram.org',
            'telegram_login.token_endpoint' => 'https://oauth.telegram.test/token',
            'telegram_login.jwks_uri' => 'https://oauth.telegram.test/jwks',
            'telegram_login.jwks_ttl_seconds' => 60,
        ]);
        Cache::forget('telegram_oidc_jwks_v1');

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        $this->jwk = [
            'kty' => 'RSA',
            'kid' => 'telegram-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
    }

    public function test_authorization_code_flow_validates_signature_and_oidc_claims(): void
    {
        $idToken = $this->token();
        Http::fake([
            'https://oauth.telegram.test/token' => Http::response(['id_token' => $idToken]),
            'https://oauth.telegram.test/jwks' => Http::response(['keys' => [$this->jwk]]),
        ]);

        $claims = (new TelegramOidcClient)->exchangeAuthorizationCode(
            'authorization-code',
            'pkce-verifier',
            'expected-nonce',
        );

        $this->assertSame('telegram-subject', $claims['sub']);
        $this->assertSame('+37499000111', $claims['phone_number']);
        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://oauth.telegram.test/token') {
                return false;
            }

            return $request['code'] === 'authorization-code'
                && $request['client_id'] === 'telegram-client'
                && $request['code_verifier'] === 'pkce-verifier'
                && $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_invalid_issuer_audience_nonce_and_signature_are_rejected(): void
    {
        foreach ([
            ['iss' => 'https://attacker.example'],
            ['aud' => 'another-client'],
            ['nonce' => 'wrong-nonce'],
        ] as $override) {
            Cache::forget('telegram_oidc_jwks_v1');
            Http::fake([
                'https://oauth.telegram.test/jwks' => Http::response(['keys' => [$this->jwk]]),
            ]);

            try {
                (new TelegramOidcClient)->verifyIdToken(
                    $this->token($override),
                    'expected-nonce',
                );
                $this->fail('Invalid OIDC claim was accepted: '.json_encode($override));
            } catch (TelegramLoginException $error) {
                $this->assertSame('telegram_invalid_token', $error->errorCode);
            }
        }

        $otherKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($otherKey, $otherPrivateKey);
        Cache::forget('telegram_oidc_jwks_v1');
        Http::fake([
            'https://oauth.telegram.test/jwks' => Http::response(['keys' => [$this->jwk]]),
        ]);

        try {
            (new TelegramOidcClient)->verifyIdToken(
                JWT::encode($this->claims(), $otherPrivateKey, 'RS256', 'telegram-test-key'),
                'expected-nonce',
            );
            $this->fail('Invalid Telegram token signature was accepted.');
        } catch (TelegramLoginException $error) {
            $this->assertSame('telegram_invalid_token', $error->errorCode);
        }
    }

    private function token(array $override = []): string
    {
        return JWT::encode(
            array_merge($this->claims(), $override),
            $this->privateKey,
            'RS256',
            'telegram-test-key',
        );
    }

    private function claims(): array
    {
        return [
            'iss' => 'https://oauth.telegram.org',
            'aud' => 'telegram-client',
            'sub' => 'telegram-subject',
            'nonce' => 'expected-nonce',
            'iat' => time(),
            'exp' => time() + 300,
            'phone_number' => '+37499000111',
            'phone_number_verified' => true,
        ];
    }
}
