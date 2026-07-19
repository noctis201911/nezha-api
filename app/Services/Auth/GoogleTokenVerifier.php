<?php

namespace App\Services\Auth;

use App\Exceptions\TelegramLoginException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoogleTokenVerifier
{
    public function verify(string $credential): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(8)
                ->get('https://www.googleapis.com/oauth2/v3/tokeninfo', [
                    'id_token' => $credential,
                ]);
        } catch (Throwable) {
            throw new TelegramLoginException(
                'google_reauthentication_failed',
                'Google verification could not be completed.',
                502,
            );
        }

        $data = $response->successful() ? $response->json() : null;
        $emailVerified = is_array($data)
            && filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (
            ! is_array($data)
            || ! is_string($data['sub'] ?? null)
            || ! is_string($data['email'] ?? null)
            || ! $emailVerified
            || ! hash_equals(
                (string) config('telegram_login.google_client_id'),
                (string) ($data['aud'] ?? ''),
            )
        ) {
            throw new TelegramLoginException(
                'google_reauthentication_failed',
                'Google verification did not match the existing account.',
                403,
            );
        }

        return $data;
    }
}
