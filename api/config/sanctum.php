<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    /*
     * Sanctum SPA cookie mode (NOT API token mode).
     */
    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf(
            '%s%s',
            'localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort()
        )
    )),

    'guard' => ['web'],

    'expiration' => (int) env('SANCTUM_EXPIRATION', 20160),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
