<?php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // The longest current durable listener timeout is 1800 seconds
            // (payroll computation).
            // Keep the Redis lease longer than that timeout so a slow listener
            // is not redelivered while its original worker is still running.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 2400),
            'block_for' => null,
            'after_commit' => false,
        ],
    ],
    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];
