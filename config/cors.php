<?php

/*
 * Browser clients must opt in explicitly through CORS_ALLOWED_ORIGINS. Keeping
 * the default empty prevents a bearer-token API from being callable by every
 * website that a user happens to visit.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 0,

    'supports_credentials' => false,
];
