<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Create the two artifacts required to restore application state:
 * PostgreSQL data and private uploaded files.
 */
class RunFullBackup extends Command
{
    protected $signature = 'db:full-backup
        {--dir= : Override the backup directory}
        {--keep= : Override database and file retention count}';

    protected $description = 'Create validated database and private-file backup artifacts';

    public function handle(): int
    {
        $backupDir = $this->option('dir') ?: config('backup.directory') ?: storage_path('app/backups');
        $databaseKeep = (string) ($this->option('keep') ?: config('backup.keep', 14));
        $filesKeep = (string) ($this->option('keep') ?: config('backup.files_keep', $databaseKeep));

        $databaseExit = Artisan::call('db:backup', [
            '--dir' => $backupDir,
            '--keep' => $databaseKeep,
        ]);
        $this->output->write(Artisan::output());

        if ($databaseExit !== self::SUCCESS) {
            return self::FAILURE;
        }

        $configuredScript = config('backup.files_script');
        $script = is_string($configuredScript) && $configuredScript !== ''
            ? $configuredScript
            : collect([
                base_path('scripts/files-backup.sh'),
                base_path('../scripts/files-backup.sh'),
            ])->first(static fn (string $candidate): bool => is_file($candidate));

        if (! is_string($script) || ! is_file($script)) {
            $this->error('db:full-backup — private files backup script not found.');
            return self::FAILURE;
        }

        $filesDirectory = (string) (config('backup.files_directory') ?: storage_path('app/private'));
        $env = [
            'BACKUP_DIR' => (string) $backupDir,
            'BACKUP_KEEP' => $filesKeep,
            'FILES_SOURCE_DIR' => $filesDirectory,
        ];

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
        @mkdir($filesDirectory, 0775, true);

        $process = new Process(['bash', $script], null, $env, null, 3600);
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('db:full-backup — private files backup exited non-zero.');
            return self::FAILURE;
        }

        $this->info('db:full-backup — database and private files backup completed.');
        return self::SUCCESS;
    }
}
