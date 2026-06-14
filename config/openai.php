<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),
    'base_uri' => env('OPENAI_BASE_URI', 'https://generativelanguage.googleapis.com/v1beta/openai/'),
    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 60),
];
