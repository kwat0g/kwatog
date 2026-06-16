<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * db:backup — wraps scripts/db-backup.sh so the scheduler can run database
 * backups through the normal artisan task pipeline (withoutOverlapping /
 * onOneServer / failure reporting all apply uniformly).
 *
 * The shell script (repo: scripts/db-backup.sh) does the actual pg_dump +
 * gzip + retention + optional S3 upload. We invoke it with the DB_* env vars
 * already present in the API container's environment (config/database.php
 * reads the same ones), pointing BACKUP_DIR at a persistent volume.
 *
 * Container note: pg_dump must be reachable from wherever this runs. In the
 * docker-compose dev/prod setup the db container owns pg_dump; if the api
 * container lacks the postgres client, set DB_BACKUP_SCRIPT to a wrapper that
 * `docker exec`s into the db service, or run `make backup` from the host
 * instead. The schedule entry is intentionally tolerant: a missing script or
 * missing pg_dump is reported as a command FAILURE (surfaced by the scheduler)
 * rather than throwing.
 */
class RunDatabaseBackup extends Command
{
    protected $signature = 'db:backup
        {--dir= : Override BACKUP_DIR (default: storage/app/backups)}
        {--keep= : Override BACKUP_KEEP retention count}';

    protected $description = 'Dump the database to a timestamped gzip file via scripts/db-backup.sh';

    public function handle(): int
    {
        $script = env('DB_BACKUP_SCRIPT', base_path('../scripts/db-backup.sh'));

        if (! is_file($script)) {
            $this->error("db:backup — backup script not found at {$script}. Set DB_BACKUP_SCRIPT or run `make backup` from the host.");
            return self::FAILURE;
        }

        $backupDir = $this->option('dir') ?: env('BACKUP_DIR', storage_path('app/backups'));

        $env = [
            'DB_HOST' => (string) config('database.connections.pgsql.host', env('DB_HOST', 'db')),
            'DB_PORT' => (string) config('database.connections.pgsql.port', env('DB_PORT', '5432')),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username', env('DB_USERNAME', '')),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password', env('DB_PASSWORD', '')),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database', env('DB_DATABASE', '')),
            'BACKUP_DIR' => (string) $backupDir,
        ];

        if ($keep = $this->option('keep')) {
            $env['BACKUP_KEEP'] = (string) $keep;
        }
        if ($bucket = env('BACKUP_S3_BUCKET')) {
            $env['BACKUP_S3_BUCKET'] = (string) $bucket;
        }

        @mkdir($backupDir, 0775, true);

        $process = new Process(['bash', $script], null, $env, null, 1800);
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('db:backup — backup script exited non-zero.');
            return self::FAILURE;
        }

        $this->info('db:backup — database backup completed.');
        return self::SUCCESS;
    }
}
