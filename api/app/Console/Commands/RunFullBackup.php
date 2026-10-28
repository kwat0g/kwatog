<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\BackupManifestStore;
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
        {--keep= : Override database and file retention count}
        {--manifest-id= : Reuse the admin operation ID for the external manifest}
        {--manifest-source=scheduled : Manifest source label}';

    protected $description = 'Create validated database and private-file backup artifacts';

    public function handle(): int
    {
        $backupDir = $this->option('dir') ?: config('backup.directory') ?: storage_path('app/backups');
        $databaseKeep = (string) ($this->option('keep') ?: config('backup.keep', 14));
        $filesKeep = (string) ($this->option('keep') ?: config('backup.files_keep', $databaseKeep));
        $beforeDatabase = $this->artifactNames($backupDir, 'ogami-*.sql.gz');
        $beforeFiles = $this->artifactNames($backupDir, 'ogami-files-*.tar.gz');

        $databaseExit = Artisan::call('db:backup', [
            '--dir' => $backupDir,
            '--keep' => $databaseKeep,
        ]);
        $databaseOutput = Artisan::output();
        $this->output->write($databaseOutput);

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

        $databaseFilename = $this->newArtifact(
            $backupDir,
            'ogami-*.sql.gz',
            $beforeDatabase,
            $databaseOutput,
            '/^backup written: (.+?) \([^\n]*\)$/m',
        );
        $filesFilename = $this->newArtifact(
            $backupDir,
            'ogami-files-*.tar.gz',
            $beforeFiles,
            $process->getOutput(),
            '/^files backup written: (.+?) \([^\n]*\)$/m',
        );
        if ($databaseFilename === null || $filesFilename === null) {
            $this->error('db:full-backup — could not identify the new database and private-files archives.');
            return self::FAILURE;
        }

        try {
            app(BackupManifestStore::class)->publish(
                $databaseFilename,
                $filesFilename,
                is_string($this->option('manifest-id')) ? $this->option('manifest-id') : null,
                (string) $this->option('manifest-source'),
            );
            app(BackupManifestStore::class)->pruneMissingArtifacts();
        } catch (\Throwable $exception) {
            $this->error('db:full-backup — artifacts completed but their recovery manifest could not be published.');
            report($exception);
            return self::FAILURE;
        }

        $this->info('db:full-backup — database and private files backup completed.');
        return self::SUCCESS;
    }

    /** @param array<int, string> $before */
    private function newArtifact(string $directory, string $pattern, array $before, string $output, string $linePattern): ?string
    {
        if (preg_match($linePattern, $output, $match) === 1) {
            $filename = basename(trim($match[1]));
            if (is_file($directory.DIRECTORY_SEPARATOR.$filename)) {
                return $filename;
            }
        }

        $created = array_values(array_diff($this->artifactNames($directory, $pattern), $before));

        return count($created) === 1 ? $created[0] : null;
    }

    /** @return array<int, string> */
    private function artifactNames(string $directory, string $pattern): array
    {
        return array_values(array_map('basename', glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pattern) ?: []));
    }
}
