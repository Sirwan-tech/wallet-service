<?php

return [
    // Allowed 3-letter currency codes for accounts
    'allowed_currencies' => ['USD', 'IQD', 'EUR', 'GBP'],

    // The application and MySQL both use signed 64-bit integers. Keep the
    // business maximum well inside that range so a valid request can never
    // overflow while balances or ledger rows are updated.
    'max_amount_minor' => (int) env('WALLET_MAX_AMOUNT_MINOR', 1_000_000_000_000),

    // Transaction history pagination
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    // Sensitive POST endpoints deliberately use separate buckets. Tune these
    // environment-backed values for real traffic rather than sharing one
    // generic limit across login, registration and money movement.
    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),
        'login_ip_per_minute' => (int) env('RATE_LIMIT_LOGIN_IP_PER_MINUTE', 30),
        'registration_per_minute' => (int) env('RATE_LIMIT_REGISTRATION_PER_MINUTE', 10),
        'registration_per_hour' => (int) env('RATE_LIMIT_REGISTRATION_PER_HOUR', 60),
        'money_per_minute' => (int) env('RATE_LIMIT_MONEY_PER_MINUTE', 10),
        'money_ip_per_minute' => (int) env('RATE_LIMIT_MONEY_IP_PER_MINUTE', 60),
        'authenticated_post_per_minute' => (int) env('RATE_LIMIT_AUTH_POST_PER_MINUTE', 30),
    ],
];
