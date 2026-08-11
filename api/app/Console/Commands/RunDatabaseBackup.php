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
 * The production image carries both this script and the PostgreSQL client, so
 * the scheduler does not depend on `docker exec` or a mutable container. A
 * configured DB_BACKUP_SCRIPT can still point at an operator-managed wrapper.
 * A missing script or missing pg_dump is reported as command FAILURE (surfaced
 * by the scheduler) rather than throwing.
 */
class RunDatabaseBackup extends Command
{
    protected $signature = 'db:backup
        {--dir= : Override BACKUP_DIR (default: storage/app/backups)}
        {--keep= : Override BACKUP_KEEP retention count}';

    protected $description = 'Dump the database to a timestamped gzip file via scripts/db-backup.sh';

    public function handle(): int
    {
        $configuredScript = config('backup.script');
        $script = is_string($configuredScript) && $configuredScript !== ''
            ? $configuredScript
            : collect([
                base_path('scripts/db-backup.sh'),
                base_path('../scripts/db-backup.sh'),
            ])->first(static fn (string $candidate): bool => is_file($candidate));

        if (! is_string($script) || ! is_file($script)) {
            $this->error("db:backup — backup script not found at {$script}. Set DB_BACKUP_SCRIPT or run `make backup` from the host.");
            return self::FAILURE;
        }

        $backupDir = $this->option('dir') ?: config('backup.directory') ?: storage_path('app/backups');

        $env = [
            'DB_HOST' => (string) config('database.connections.pgsql.host', 'db'),
            'DB_PORT' => (string) config('database.connections.pgsql.port', '5432'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username', ''),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password', ''),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database', ''),
            'BACKUP_DIR' => (string) $backupDir,
        ];

        $env['BACKUP_KEEP'] = (string) ($this->option('keep') ?: config('backup.keep', 14));

        foreach ([
            'BACKUP_S3_BUCKET' => 'backup.s3_bucket',
            'BACKUP_S3_PREFIX' => 'backup.s3_prefix',
            'AWS_ACCESS_KEY_ID' => 'backup.aws_access_key_id',
            'AWS_SECRET_ACCESS_KEY' => 'backup.aws_secret_access_key',
            'AWS_DEFAULT_REGION' => 'backup.aws_default_region',
        ] as $environmentKey => $configKey) {
            $value = config($configKey);
            if ($value !== null && $value !== '') {
                $env[$environmentKey] = (string) $value;
            }
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
