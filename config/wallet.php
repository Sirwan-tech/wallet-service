<?php

return [
    // Allowed 3-letter currency codes for accounts
    'allowed_currencies' => ['USD', 'IQD', 'EUR', 'GBP'],

    // Transaction history pagination
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],
];
