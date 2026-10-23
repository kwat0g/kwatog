<?php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // retry_after is the RESERVATION LEASE and it is per CONNECTION, not
            // per queue: taking a job off `bulk` reserves it under this same
            // value. It must therefore exceed the longest timeout any worker
            // can run with — 3600s on the bulk lane — or Redis re-reserves a
            // job whose first copy is still working (two restores, two
            // payrolls).
            //
            // The old 2400 was BELOW the 3600s bulk timeout, so a restore
            // running past 40 minutes was redelivered while still executing.
            // Horizon now sets a per-supervisor lease (config/horizon.php) and
            // overrides this per lane; this value is the floor for anything
            // running a plain `queue:work`, and 3900 keeps it correct there.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3900),

            // Block on the pop instead of polling. Workers used to sleep 3s
            // between empty polls, so a job pushed just after a poll waited up
            // to 3s for a worker that was otherwise idle — measured at 2.5s
            // before this change. With BLPOP the worker is parked ON the queue
            // and wakes on push. Must stay well below retry_after (3900).
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5),
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
