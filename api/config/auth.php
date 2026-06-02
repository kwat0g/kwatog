<?php

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // ADV10 — B2B Portal auth guards (Sanctum API token-based)
        'supplier_portal' => [
            'driver' => 'sanctum',
            'provider' => 'supplier_portal_users',
        ],
        'customer_portal' => [
            'driver' => 'sanctum',
            'provider' => 'customer_portal_users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Modules\Auth\Models\User::class),
        ],
        // ADV10 — B2B Portal providers
        'supplier_portal_users' => [
            'driver' => 'eloquent',
            'model' => App\Modules\B2B\Models\SupplierPortalUser::class,
        ],
        'customer_portal_users' => [
            'driver' => 'eloquent',
            'model' => App\Modules\B2B\Models\CustomerPortalUser::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),
];
