<?php

declare(strict_types=1);

return [
    // Read these through config so scheduled commands continue to see the
    // production environment after `config:cache` has been built.
    'script' => env('DB_BACKUP_SCRIPT'),
    'directory' => env('BACKUP_DIR'),
    'keep' => env('BACKUP_KEEP', 14),
    'files_directory' => env('BACKUP_FILES_DIRECTORY', storage_path('app/private')),
    'files_keep' => env('BACKUP_FILES_KEEP', env('BACKUP_KEEP', 14)),
    'files_script' => env('BACKUP_FILES_SCRIPT'),
    // Restore runs inside the queue worker. Pause the same shared queue before
    // entering maintenance so already-enqueued writers cannot mutate the
    // database or private files while the restore is in progress. The TTL is a
    // crash fence: a dead worker cannot leave the queue paused forever.
    'queue_connection' => env('BACKUP_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
    'queue_name' => env('BACKUP_QUEUE', env('REDIS_QUEUE', 'default')),
    'queue_pause_seconds' => (int) env('BACKUP_QUEUE_PAUSE_SECONDS', 21600),
    'lease_seconds' => (int) env('BACKUP_OPERATION_LEASE_SECONDS', 7200),
    // The recovery catalog probes off-site objects with `aws s3api head-object`.
    // Cache that verdict briefly so the admin page's 5s poll cannot spawn one
    // subprocess per retained artifact per request. Restore preflight always
    // reads through.
    'remote_probe_cache_seconds' => (int) env('BACKUP_REMOTE_PROBE_CACHE_SECONDS', 120),
    's3_bucket' => env('BACKUP_S3_BUCKET'),
    's3_prefix' => env('BACKUP_S3_PREFIX'),
    'aws_access_key_id' => env('AWS_ACCESS_KEY_ID'),
    'aws_secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
    'aws_default_region' => env('AWS_DEFAULT_REGION'),
];
