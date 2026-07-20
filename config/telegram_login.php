<?php

return [
    'enabled' => (bool) env('TELEGRAM_OIDC_ENABLED', false),
    'allow_new_accounts' => (bool) env('TELEGRAM_OIDC_ALLOW_NEW_ACCOUNTS', false),

    'client_id' => env('TELEGRAM_OIDC_CLIENT_ID'),
    'client_secret' => env('TELEGRAM_OIDC_CLIENT_SECRET'),
    'redirect_uri' => env('TELEGRAM_OIDC_REDIRECT_URI'),
    'frontend_uri' => env('TELEGRAM_OIDC_FRONTEND_URI', 'https://nezha.am/auth/telegram'),

    'issuer' => env('TELEGRAM_OIDC_ISSUER', 'https://oauth.telegram.org'),
    'authorization_endpoint' => env('TELEGRAM_OIDC_AUTHORIZATION_ENDPOINT', 'https://oauth.telegram.org/auth'),
    'token_endpoint' => env('TELEGRAM_OIDC_TOKEN_ENDPOINT', 'https://oauth.telegram.org/token'),
    'jwks_uri' => env('TELEGRAM_OIDC_JWKS_URI', 'https://oauth.telegram.org/.well-known/jwks.json'),

    'attempt_ttl_seconds' => (int) env('TELEGRAM_OIDC_ATTEMPT_TTL_SECONDS', 600),
    'jwks_ttl_seconds' => (int) env('TELEGRAM_OIDC_JWKS_TTL_SECONDS', 21600),

    // Public Google OAuth client id. Used only to re-authenticate the exact
    // customer account locked by a Telegram link attempt; normal Google login
    // keeps its existing controller path unchanged.
    'google_client_id' => env(
        'GOOGLE_OIDC_CLIENT_ID',
        '786035188808-o9imoj11p6kvhf2ujgd9uunqub1s3d2l.apps.googleusercontent.com'
    ),
];
