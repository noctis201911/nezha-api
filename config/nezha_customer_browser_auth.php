<?php

$enabled = filter_var(
    env('NEZHA_CUSTOMER_BROWSER_SESSION_ENABLED', false),
    FILTER_VALIDATE_BOOL
);

$allowedOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env(
        'NEZHA_CUSTOMER_BROWSER_ALLOWED_ORIGINS',
        'https://nezha.am,https://www.nezha.am'
    ))
))));
$legacyTtl = env('NEZHA_CUSTOMER_LEGACY_TOKEN_TTL_DAYS');
$legacyTtlDays = $legacyTtl === null || trim((string) $legacyTtl) === ''
    ? null
    : max(1, (int) $legacyTtl);

return [
    /*
    |--------------------------------------------------------------------------
    | Customer browser session rollout
    |--------------------------------------------------------------------------
    |
    | This switch is deliberately off by default. The API may be deployed
    | before the database migration and H5 cutover without changing the
    | existing Passport bearer-token contract.
    |
    */
    'enabled' => $enabled,

    'allowed_origins' => $allowedOrigins,

    'cookie' => [
        // __Host- requires Secure, Path=/ and no Domain attribute.
        'name' => env(
            'NEZHA_CUSTOMER_BROWSER_COOKIE',
            '__Host-nezha_customer_session'
        ),
        'same_site' => 'lax',
    ],

    'idle_days' => max(1, (int) env(
        'NEZHA_CUSTOMER_BROWSER_IDLE_DAYS',
        30
    )),
    'absolute_days' => max(1, (int) env(
        'NEZHA_CUSTOMER_BROWSER_ABSOLUTE_DAYS',
        90
    )),
    'max_sessions_per_user' => max(1, (int) env(
        'NEZHA_CUSTOMER_BROWSER_MAX_SESSIONS',
        5
    )),
    'touch_interval_minutes' => max(1, (int) env(
        'NEZHA_CUSTOMER_BROWSER_TOUCH_MINUTES',
        5
    )),

    /*
    | Passport only applies this value when issuing a new personal token.
    | Keep 365 during the Cookie observation window, then set 7 after the
    | owner accepts the real-path rollout. Existing JWT exp values do not
    | change when this setting changes or rolls back.
    */
    // Null preserves Passport's current P1Y default exactly, including its
    // calendar-year leap-day behavior. Set an integer only at the approved
    // post-observation TTL cutover.
    'legacy_access_token_ttl_days' => $legacyTtlDays,
];
