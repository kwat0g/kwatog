<?php

declare(strict_types=1);

/**
 * Series C — Task C4. Laravel Reverb daemon config.
 *
 * Without this file, Reverb starts with framework defaults that do NOT
 * match REVERB_APP_KEY=ogami_reverb, so:
 *  - the SPA Pusher.js client tries to subscribe with key `ogami_reverb`
 *    and gets rejected silently
 *  - the API broadcaster POSTs events using `ogami_reverb` and Reverb
 *    drops them without logging
 *
 * Both ends look connected (101 Switching Protocols visible in DevTools)
 * but no event ever reaches the browser. Real bug, fixed 2026-05-07.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting messages to other apps.
    | Currently, Reverb only ships with one server: `reverb`.
    |
    */
    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the servers available with
    | your application. A default configuration has been added for each
    | of the servers shipped with Reverb. You are free to add more.
    |
    */
    'servers' => [

        'reverb' => [
            'host'        => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port'        => (int) env('REVERB_SERVER_PORT', 8080),
            'hostname'    => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling'          => [
                'enabled' => (bool) env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server'  => [
                    'url'      => env('REDIS_URL'),
                    'host'     => env('REDIS_HOST', '127.0.0.1'),
                    'port'     => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                ],
            ],
            'pulse_ingest_interval' => (int) env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => (int) env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */
    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key'                  => env('REVERB_APP_KEY'),
                'secret'               => env('REVERB_APP_SECRET'),
                'app_id'               => env('REVERB_APP_ID'),
                'options'              => [
                    'host'   => env('REVERB_HOST'),
                    'port'   => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins'      => ['*'],
                'ping_interval'        => (int) env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout'     => (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size'     => (int) env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];
