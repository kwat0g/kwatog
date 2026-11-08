<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
        // Generation markers control whether disposable cache payloads may be
        // reused. Keep them on the persistent, non-evicting Redis instance.
        'generations' => [
            'driver' => env('CACHE_GENERATION_DRIVER', 'redis'),
            'connection' => env('REDIS_GENERATION_CONNECTION', 'generations'),
            'lock_connection' => env('REDIS_GENERATION_LOCK_CONNECTION', 'default'),
        ],
    ],
    'prefix' => env(
        'CACHE_PREFIX',
        Str::slug(env('APP_NAME', 'ogami'), '_').'_'.Str::slug(env('APP_ENV', 'production'), '_').'_cache_',
    ),
];
