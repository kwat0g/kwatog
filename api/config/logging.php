<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],
    // Phase 5b — slow-query telemetry settings. Read by the LogSlowQueries
    // middleware via config() so values survive `config:cache` (env() returns
    // null after caching). 'threshold_ms' = 0 is a sentinel meaning "use the
    // per-environment default" (100ms local, 250ms otherwise). 'enabled'
    // defaults to on everywhere except testing.
    'slow_query' => [
        'enabled'      => env('LOG_SLOW_QUERIES', ! ((string) env('APP_ENV', 'production') === 'testing')),
        'threshold_ms' => (int) env('LOG_SLOW_QUERIES_MS', 0),
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],
        'auth' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auth.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],
        // Phase 5b — dedicated slow-query channel. LogSlowQueries middleware
        // writes here in production; LOG_SLOW_QUERIES_MS env var sets the
        // threshold (default 250ms). Daily rotation, 30-day retention.
        'slow' => [
            'driver' => 'daily',
            'path' => storage_path('logs/slow-queries.log'),
            'level' => 'warning',
            'days' => 30,
            'replace_placeholders' => true,
        ],
        'financial' => [
            'driver' => 'daily',
            'path' => storage_path('logs/financial.log'),
            'level' => 'info',
            'days' => 365,
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => ['stream' => 'php://stderr'],
            'processors' => [PsrLogMessageProcessor::class],
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];
