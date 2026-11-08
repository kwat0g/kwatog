<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'db'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ogami'),
            'username' => env('DB_USERNAME', 'ogami'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'ogami'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_CACHE_URL'),
            'host' => env('REDIS_CACHE_HOST', 'redis-cache'),
            'username' => env('REDIS_CACHE_USERNAME'),
            'password' => env('REDIS_CACHE_PASSWORD'),
            'port' => env('REDIS_CACHE_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_CACHE_DB', '0'),
        ],
        // Broadcast jobs use a separate logical DB on the non-evicting queue
        // Redis by default. Keep its host/credentials aligned unless an
        // explicitly separate broadcast Redis instance is configured.
        'broadcasts' => [
            'url' => env('REDIS_BROADCAST_URL'),
            'host' => env('REDIS_BROADCAST_HOST', env('REDIS_HOST', 'redis')),
            'username' => env('REDIS_BROADCAST_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_BROADCAST_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_BROADCAST_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_BROADCAST_DB', '1'),
        ],
        // Cache-generation metadata cannot share the bounded LRU payload
        // cache: eviction of a marker can otherwise strand stale namespaces.
        'generations' => [
            'url' => env('REDIS_GENERATION_URL'),
            'host' => env('REDIS_GENERATION_HOST', env('REDIS_HOST', 'redis')),
            'username' => env('REDIS_GENERATION_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_GENERATION_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_GENERATION_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_GENERATION_DB', '4'),
        ],
    ],
];
