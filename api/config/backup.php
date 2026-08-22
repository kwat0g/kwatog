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
    's3_bucket' => env('BACKUP_S3_BUCKET'),
    's3_prefix' => env('BACKUP_S3_PREFIX'),
    'aws_access_key_id' => env('AWS_ACCESS_KEY_ID'),
    'aws_secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
    'aws_default_region' => env('AWS_DEFAULT_REGION'),
];
