<?php

return [
    // Secure fail-closed switch: when enabled, the legacy sign-up endpoint can
    // no longer create an email account before ownership is proven.
    'enabled' => (bool) env('CUSTOMER_EMAIL_AUTH_ENABLED', true),
    'allow_new_accounts' => (bool) env('CUSTOMER_EMAIL_AUTH_ALLOW_NEW_ACCOUNTS', true),

    'challenge_ttl_seconds' => (int) env('CUSTOMER_EMAIL_AUTH_TTL_SECONDS', 600),
    'max_code_attempts' => (int) env('CUSTOMER_EMAIL_AUTH_MAX_CODE_ATTEMPTS', 5),

    'start_email_limit' => (int) env('CUSTOMER_EMAIL_AUTH_EMAIL_LIMIT', 5),
    'start_ip_limit' => (int) env('CUSTOMER_EMAIL_AUTH_IP_LIMIT', 10),
    'start_decay_seconds' => (int) env('CUSTOMER_EMAIL_AUTH_DECAY_SECONDS', 600),
    'resend_after_seconds' => (int) env('CUSTOMER_EMAIL_AUTH_RESEND_SECONDS', 60),
];
