<?php

declare(strict_types=1);

/**
 * Series C — Task C4. Laravel 11 broadcasting config.
 *
 * Without this file, events that implement ShouldBroadcast / ShouldBroadcastNow
 * are silently dropped because the framework falls back to the `null` driver.
 * That manifests as: WebSocket is connected, channel subscription succeeds,
 * but no events ever arrive in the browser. (Real bug, fixed 2026-05-07.)
 *
 * The default connection is `reverb`. Set BROADCAST_CONNECTION=log in .env
 * locally to debug: events will be written to storage/logs/laravel.log
 * instead of being broadcast.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. This connection is used
    | when another connection is not specified when broadcasting an event.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */
    'connections' => [

        'reverb' => [
            'driver'     => 'reverb',
            'key'        => env('REVERB_APP_KEY'),
            'secret'     => env('REVERB_APP_SECRET'),
            'app_id'     => env('REVERB_APP_ID'),
            'options'    => [
                // Server-side push target. In prod this is the Reverb container
                // on the internal Docker network (REVERB_SERVER_*), NOT the public
                // domain — pushing via the public host would hairpin-NAT to the
                // VM's own external IP, which most clouds drop, and nginx only
                // proxies the browser WebSocket path anyway. Falls back to the
                // public REVERB_* vars so dev (single host) is unchanged.
                'host'   => env('REVERB_SERVER_HOST', env('REVERB_HOST', 'reverb')),
                'port'   => (int) env('REVERB_SERVER_PORT', (int) env('REVERB_PORT', 8080)),
                'scheme' => env('REVERB_SERVER_SCHEME', env('REVERB_SCHEME', 'http')),
                'useTLS' => env('REVERB_SERVER_SCHEME', env('REVERB_SCHEME', 'http')) === 'https',
            ],
            'client_options' => [
                // Guzzle options for the server-side broadcaster HTTP client.
                // verify => false would be needed for self-signed certs;
                // we don't enable that here.
            ],
        ],

        'pusher' => [
            'driver'     => 'pusher',
            'key'        => env('PUSHER_APP_KEY'),
            'secret'     => env('PUSHER_APP_SECRET'),
            'app_id'     => env('PUSHER_APP_ID'),
            'options'    => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host'    => env('PUSHER_HOST', 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com'),
                'port'    => env('PUSHER_PORT', 443),
                'scheme'  => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS'  => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
