<?php

return [
    // The StackFood-era endpoint creates a user before proving ownership of
    // either contact field. It remains fail-closed while the unified flow is
    // rolled out behind independent login/registration database switches.
    'legacy_signup_enabled' => (bool) env('CUSTOMER_LEGACY_SIGNUP_ENABLED', false),

    // Account creation remains disabled unless both server-authoritative
    // document versions are configured and the database registration switch
    // is explicitly enabled.
    'terms_version' => env('CUSTOMER_AUTH_TERMS_VERSION'),
    'privacy_version' => env('CUSTOMER_AUTH_PRIVACY_VERSION'),

    'challenge_ttl_seconds' => (int) env('CUSTOMER_EMAIL_AUTH_TTL_SECONDS', 600),
    'challenge_resend_seconds' => (int) env('CUSTOMER_EMAIL_AUTH_RESEND_SECONDS', 60),
    'challenge_max_attempts' => (int) env('CUSTOMER_EMAIL_AUTH_MAX_ATTEMPTS', 5),
];
