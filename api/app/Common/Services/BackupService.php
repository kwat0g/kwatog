<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Jobs\CreateBackupJob;
use App\Common\Jobs\RestoreBackupJob;
use App\Common\Models\AuditLog;
use App\Common\Models\BackupOperation;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Coordinates the operator-facing backup workflow.
 *
 * The service deliberately accepts artifact names, never arbitrary filesystem
 * paths. Both backup scripts are server-owned and are executed only by queue
 * workers, so an HTTP request cannot turn this surface into a shell or path
 * traversal primitive.
 */
class BackupService
{
    public const TYPE_BACKUP = 'backup';
    public const TYPE_RESTORE = 'restore';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private const DATABASE_PATTERN = '/\Aogami-[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz\z/D';
    private const FILES_PATTERN = '/\Aogami-files-[A-Za-z0-9][A-Za-z0-9._-]*\.tar\.gz\z/D';

    public function queueBackup(User $actor): BackupOperation
    {
        $this->rejectIfOperationActive();

        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $actor->id,
            'type' => self::TYPE_BACKUP,
            'status' => self::STATUS_QUEUED,
            'metadata' => [
                'scope' => 'database_and_private_files',
                'offsite_configured' => $this->offsiteConfigured(),
            ],
        ]);

        $this->audit($operation, $actor, 'backup.requested', [
            'scope' => 'database_and_private_files',
        ]);

        CreateBackupJob::dispatch($operation->id);

        return $operation;
    }

    public function queueRestore(
        User $actor,
        string $databaseFilename,
        ?string $filesFilename,
        string $confirmation,
    ): BackupOperation {
        $databaseFilename = $this->validateArtifactName($databaseFilename, 'database');
        $filesFilename = $filesFilename !== null && $filesFilename !== ''
            ? $this->validateArtifactName($filesFilename, 'files')
            : null;

        $expected = 'RESTORE '.$databaseFilename;
        if (! hash_equals($expected, trim($confirmation))) {
            throw ValidationException::withMessages([
                'confirmation' => ['Type RESTORE followed by the database backup filename to continue.'],
            ]);
        }

        $this->assertArtifactAvailable($databaseFilename, 'database');
        if ($filesFilename !== null) {
            $this->assertArtifactAvailable($filesFilename, 'files');
        }
        $this->rejectIfOperationActive();

        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $actor->id,
            'type' => self::TYPE_RESTORE,
            'status' => self::STATUS_QUEUED,
            'artifacts' => [
                'database' => ['name' => $databaseFilename],
                'files' => $filesFilename !== null ? ['name' => $filesFilename] : null,
            ],
            'metadata' => [
                'scope' => $filesFilename !== null ? 'database_and_private_files' : 'database_only',
                'offsite_configured' => $this->offsiteConfigured(),
            ],
        ]);

        $this->audit($operation, $actor, 'backup.restore_requested', [
            'database_filename' => $databaseFilename,
            'files_filename' => $filesFilename,
        ]);

        RestoreBackupJob::dispatch($operation->id);

        return $operation;
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        $operations = BackupOperation::query()
            ->with('requestedBy:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (BackupOperation $operation): array => $this->serializeOperation($operation))
            ->values()
            ->all();

        $managedNames = [];
        foreach ($operations as $operation) {
            foreach (['database', 'files'] as $kind) {
                $name = $operation['artifacts'][$kind]['name'] ?? null;
                if (is_string($name)) {
                    $managedNames[$name] = true;
                }
            }
        }

        // Show older local artifacts even if they were created before the
        // operation ledger existed. They are deliberately read-only entries;
        // new restores are tracked through the same guarded endpoint.
        foreach ($this->artifactNames('database') as $filename) {
            if (isset($managedNames[$filename])) {
                continue;
            }
            $operations[] = [
                'id' => null,
                'type' => self::TYPE_BACKUP,
                'status' => 'available',
                'artifacts' => [
                    'database' => $this->describeArtifact($filename, 'database'),
                    'files' => null,
                ],
                'error_message' => null,
                'requested_by' => null,
                'requested_by_name' => 'System / legacy backup',
                'created_at' => $this->artifactCreatedAt($filename),
                'started_at' => null,
                'completed_at' => $this->artifactCreatedAt($filename),
            ];
        }

        usort($operations, static fn (array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? ''),
        ));

        return [
            'backups' => array_slice($operations, 0, 50),
            'active_operation' => BackupOperation::query()
                ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
                ->latest()
                ->first()
                ?->only(['id', 'type', 'status', 'created_at', 'started_at']),
            'configuration' => [
                'local_directory_configured' => true,
                'offsite_configured' => $this->offsiteConfigured(),
                'scope' => 'database and private uploaded files',
                'restore_requires_maintenance' => true,
            ],
        ];
    }

    public function runBackup(string $operationId): void
    {
        $operation = BackupOperation::query()->findOrFail($operationId);
        $this->markRunning($operation);

        $beforeDatabase = $this->artifactNames('database');
        $beforeFiles = $this->artifactNames('files');

        try {
            $exit = Artisan::call('db:full-backup', [
                '--dir' => $this->backupDirectory(),
                '--keep' => (string) config('backup.keep', 14),
            ]);

            if ($exit !== 0) {
                throw new \RuntimeException('The full backup command failed. Check the backup log for details.');
            }

            $databaseFilename = $this->newestArtifact(array_diff($this->artifactNames('database'), $beforeDatabase));
            $filesFilename = $this->newestArtifact(array_diff($this->artifactNames('files'), $beforeFiles));
            if ($databaseFilename === null || $filesFilename === null) {
                throw new \RuntimeException('The backup command completed without publishing both artifacts.');
            }

            $operation->forceFill([
                'status' => self::STATUS_COMPLETED,
                'artifacts' => [
                    'database' => $this->describeArtifact($databaseFilename, 'database'),
                    'files' => $this->describeArtifact($filesFilename, 'files'),
                ],
                'completed_at' => now(),
                'error_message' => null,
            ])->save();

            $this->audit($operation, null, 'backup.completed', $operation->artifacts);
        } catch (Throwable $exception) {
            $this->markFailed($operationId, $exception);
            throw $exception;
        }
    }

    public function runRestore(string $operationId): void
    {
        $operation = BackupOperation::query()->findOrFail($operationId);
        $original = [
            'id' => $operation->id,
            'requested_by' => $operation->requested_by,
            'type' => $operation->type,
            'artifacts' => $operation->artifacts,
            'metadata' => $operation->metadata,
        ];
        $this->markRunning($operation);

        $temporaryFiles = [];
        $maintenance = false;

        try {
            // Always create a fresh rollback point before touching production.
            $beforeDatabase = $this->artifactNames('database');
            $beforeFiles = $this->artifactNames('files');
            $preBackupExit = Artisan::call('db:full-backup', [
                '--dir' => $this->backupDirectory(),
                '--keep' => (string) config('backup.keep', 14),
            ]);
            if ($preBackupExit !== 0) {
                throw new \RuntimeException('Pre-restore backup failed; restore was not started.');
            }

            $preRestoreDatabase = $this->newestArtifact(array_diff($this->artifactNames('database'), $beforeDatabase));
            $preRestoreFiles = $this->newestArtifact(array_diff($this->artifactNames('files'), $beforeFiles));
            if ($preRestoreDatabase === null || $preRestoreFiles === null) {
                throw new \RuntimeException('Pre-restore backup did not publish both rollback artifacts.');
            }

            $metadata = array_merge((array) ($original['metadata'] ?? []), [
                'pre_restore_artifacts' => [
                    'database' => $this->describeArtifact($preRestoreDatabase, 'database'),
                    'files' => $this->describeArtifact($preRestoreFiles, 'files'),
                ],
            ]);
            $this->persistOperation($original, self::STATUS_RUNNING, null, $metadata);

            $databaseName = (string) ($original['artifacts']['database']['name'] ?? '');
            $filesName = $original['artifacts']['files']['name'] ?? null;
            $databasePath = $this->materializeArtifact($databaseName, 'database', $operationId, $temporaryFiles);
            $filesPath = $filesName !== null
                ? $this->materializeArtifact((string) $filesName, 'files', $operationId, $temporaryFiles)
                : null;

            $downExit = Artisan::call('down', ['--render' => 'errors::503']);
            if ($downExit !== 0) {
                throw new \RuntimeException('Could not enter maintenance mode; restore was not started.');
            }
            $maintenance = true;

            $this->runDatabaseRestore($databasePath);

            // A historical dump may predate the backup ledger migration. Bring
            // the restored schema forward before recording completion.
            DB::purge();
            $migrateExit = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
            if ($migrateExit !== 0) {
                throw new \RuntimeException('Database restore completed but migrations could not be applied.');
            }

            if ($filesPath !== null) {
                $this->runFilesRestore($filesPath);
            }

            $this->persistOperation($original, self::STATUS_COMPLETED, null, $metadata);
            $this->auditFromSnapshot($original, 'backup.restore_completed', $metadata);
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
            try {
                $this->persistOperation($original, self::STATUS_FAILED, $error, (array) ($original['metadata'] ?? []));
            } catch (Throwable $ledgerException) {
                Log::error('Could not record failed restore operation.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($ledgerException),
                    'restore_error' => $error,
                ]);
            }
            try {
                $this->auditFromSnapshot($original, 'backup.restore_failed', ['error' => $error]);
            } catch (Throwable $auditException) {
                Log::error('Could not record failed restore audit event.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($auditException),
                    'restore_error' => $error,
                ]);
            }
            throw $exception;
        } finally {
            if ($maintenance) {
                try {
                    Artisan::call('up');
                } catch (Throwable $exception) {
                    Log::critical('Could not leave maintenance mode after restore.', [
                        'operation_id' => $operationId,
                        'error' => $this->safeError($exception),
                    ]);
                }
            }
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    public function markFailed(string $operationId, Throwable $exception): void
    {
        try {
            BackupOperation::query()->whereKey($operationId)->update([
                'status' => self::STATUS_FAILED,
                'error_message' => $this->safeError($exception),
                'completed_at' => now(),
            ]);
        } catch (Throwable) {
            // A database restore can replace the ledger while a job is running.
            // The original exception remains the useful failure signal in logs.
        }
    }

    /** @return array<string, mixed> */
    private function serializeOperation(BackupOperation $operation): array
    {
        $artifacts = is_array($operation->artifacts) ? $operation->artifacts : [];
        $databaseArtifact = $artifacts['database'] ?? null;
        $filesArtifact = $artifacts['files'] ?? null;

        return [
            'id' => $operation->id,
            'type' => $operation->type,
            'status' => $operation->status,
            'artifacts' => [
                'database' => is_array($databaseArtifact) ? $databaseArtifact : null,
                'files' => is_array($filesArtifact) ? $filesArtifact : null,
            ],
            'error_message' => $operation->error_message,
            'requested_by' => $operation->requested_by,
            'requested_by_name' => $operation->requestedBy?->name,
            'created_at' => $operation->created_at?->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    private function markRunning(BackupOperation $operation): void
    {
        $operation->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function persistOperation(array $original, string $status, ?string $error, array $metadata): void
    {
        $requestedBy = $original['requested_by'] ?? null;
        if ($requestedBy !== null && ! DB::table('users')->whereKey($requestedBy)->exists()) {
            $requestedBy = null;
        }

        BackupOperation::query()->updateOrCreate(
            ['id' => $original['id']],
            [
                'requested_by' => $requestedBy,
                'type' => $original['type'],
                'status' => $status,
                'artifacts' => $original['artifacts'],
                'metadata' => $metadata,
                'error_message' => $error,
                'started_at' => now(),
                'completed_at' => in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true) ? now() : null,
            ],
        );
    }

    private function rejectIfOperationActive(): void
    {
        $active = BackupOperation::query()
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'operation' => ['Another backup or restore operation is already queued or running.'],
            ]);
        }
    }

    /** @return array<int, string> */
    private function artifactNames(string $kind): array
    {
        $pattern = $kind === 'files' ? 'ogami-files-*.tar.gz' : 'ogami-*.sql.gz';
        $names = glob($this->backupDirectory().DIRECTORY_SEPARATOR.$pattern) ?: [];

        return array_values(array_filter(array_map('basename', $names), function (string $name) use ($kind): bool {
            return preg_match($kind === 'files' ? self::FILES_PATTERN : self::DATABASE_PATTERN, $name) === 1;
        }));
    }

    private function newestArtifact(array $names): ?string
    {
        usort($names, fn (string $a, string $b): int => filemtime($this->backupDirectory().DIRECTORY_SEPARATOR.$b) <=> filemtime($this->backupDirectory().DIRECTORY_SEPARATOR.$a));
        return $names[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function describeArtifact(string $filename, string $kind): array
    {
        $path = $this->safeArtifactPath($filename, $kind);
        $checksum = hash_file('sha256', $path);
        return [
            'name' => $filename,
            'kind' => $kind,
            'size' => filesize($path) ?: 0,
            'sha256' => is_string($checksum) ? $checksum : null,
            'created_at' => $this->artifactCreatedAt($filename),
            'source' => 'local',
        ];
    }

    private function artifactCreatedAt(string $filename): ?string
    {
        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$filename;
        $mtime = is_file($path) ? filemtime($path) : false;
        return $mtime === false ? null : now()->setTimestamp($mtime)->toIso8601String();
    }

    private function validateArtifactName(string $filename, string $kind): string
    {
        $filename = trim($filename);
        $pattern = $kind === 'files' ? self::FILES_PATTERN : self::DATABASE_PATTERN;
        if ($filename === '' || strlen($filename) > 180 || basename($filename) !== $filename || preg_match($pattern, $filename) !== 1) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact is invalid.'],
            ]);
        }
        return $filename;
    }

    private function assertArtifactAvailable(string $filename, string $kind): void
    {
        if (is_file($this->backupDirectory().DIRECTORY_SEPARATOR.$filename)) {
            $this->safeArtifactPath($filename, $kind);
            return;
        }

        if (! $this->offsiteConfigured()) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact is not available locally and off-site storage is not configured.'],
            ]);
        }
    }

    private function safeArtifactPath(string $filename, string $kind): string
    {
        $this->validateArtifactName($filename, $kind);
        $directory = realpath($this->backupDirectory());
        $path = realpath($this->backupDirectory().DIRECTORY_SEPARATOR.$filename);
        if ($directory === false || $path === false || ! is_file($path) || dirname($path) !== rtrim($directory, DIRECTORY_SEPARATOR)) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact could not be verified.'],
            ]);
        }
        return $path;
    }

    /** @param array<int, string> $temporaryFiles */
    private function materializeArtifact(string $filename, string $kind, string $operationId, array &$temporaryFiles): string
    {
        if (is_file($this->backupDirectory().DIRECTORY_SEPARATOR.$filename)) {
            return $this->safeArtifactPath($filename, $kind);
        }

        $remote = $this->s3Uri($filename);
        if ($remote === null) {
            throw new \RuntimeException('Selected backup artifact is unavailable.');
        }

        $temporary = $this->backupDirectory().DIRECTORY_SEPARATOR.'.restore-'.$operationId.'-'.$filename;
        $process = new Process(
            ['aws', 's3', 'cp', $remote, $temporary, '--only-show-errors'],
            null,
            $this->s3Environment(),
            null,
            600,
        );
        $process->run();
        if (! $process->isSuccessful() || ! is_file($temporary)) {
            throw new \RuntimeException('Selected off-site backup artifact could not be downloaded.');
        }
        $temporaryFiles[] = $temporary;
        if ($kind === 'database') {
            $this->validateArtifactName($filename, 'database');
        } else {
            $this->validateArtifactName($filename, 'files');
        }
        return $temporary;
    }

    private function runDatabaseRestore(string $path): void
    {
        $script = $this->scriptPath('db-restore.sh');
        $process = new Process(['bash', $script, '--yes', $path], null, $this->databaseEnvironment(), null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Database restore failed. The pre-restore backup is available.');
        }
    }

    private function runFilesRestore(string $path): void
    {
        $script = $this->scriptPath('files-restore.sh');
        $env = ['FILES_SOURCE_DIR' => (string) (config('backup.files_directory') ?: storage_path('app/private'))];
        $process = new Process(['bash', $script, $path], null, $env, null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Private files restore failed. The database restore may already be complete.');
        }
    }

    /** @return array<string, string> */
    private function databaseEnvironment(): array
    {
        return [
            'DB_HOST' => (string) config('database.connections.pgsql.host', 'db'),
            'DB_PORT' => (string) config('database.connections.pgsql.port', '5432'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username', ''),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password', ''),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database', ''),
        ];
    }

    private function scriptPath(string $name): string
    {
        foreach ([base_path('scripts/'.$name), base_path('../scripts/'.$name)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Backup helper script is missing.');
    }

    private function backupDirectory(): string
    {
        $configured = config('backup.directory');
        $directory = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : storage_path('app/backups');
        if ($directory === '/' || trim($directory) === '') {
            throw new \RuntimeException('Unsafe backup directory configuration.');
        }
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private function offsiteConfigured(): bool
    {
        return $this->s3Uri('ogami-placeholder.sql.gz') !== null;
    }

    private function s3Uri(string $filename): ?string
    {
        $configured = trim((string) config('backup.s3_bucket', ''));
        if ($configured === '') {
            return null;
        }

        $bucket = str_starts_with($configured, 's3://')
            ? (string) parse_url($configured, PHP_URL_HOST)
            : trim($configured, '/');
        if ($bucket === '' || preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i', $bucket) !== 1) {
            return null;
        }

        return 's3://'.$bucket.'/'.$this->s3Key($filename);
    }

    private function s3Key(string $filename): string
    {
        $prefix = trim((string) config('backup.s3_prefix', ''), '/');
        return $prefix === '' ? $filename : $prefix.'/'.$filename;
    }

    /** @return array<string, string> */
    private function s3Environment(): array
    {
        $environment = [];
        foreach ([
            'AWS_ACCESS_KEY_ID' => 'backup.aws_access_key_id',
            'AWS_SECRET_ACCESS_KEY' => 'backup.aws_secret_access_key',
            'AWS_DEFAULT_REGION' => 'backup.aws_default_region',
        ] as $environmentKey => $configKey) {
            $value = config($configKey);
            if ($value !== null && $value !== '') {
                $environment[$environmentKey] = (string) $value;
            }
        }
        return $environment;
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(trim($exception->getMessage()) ?: 'Backup operation failed.', 0, 2000);
    }

    /** @param array<string, mixed> $values */
    private function audit(BackupOperation $operation, ?User $actor, string $action, array $values): void
    {
        AuditLog::create([
            'user_id' => $actor?->id ?? $operation->requested_by,
            'actor_type' => 'user',
            'action' => $action,
            'model_type' => 'backup_operation',
            'model_id' => null,
            'old_values' => null,
            'new_values' => array_merge(['operation_id' => $operation->id], $values),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => 'admin.backups',
            'correlation_id' => $operation->id,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $snapshot @param array<string, mixed> $values */
    private function auditFromSnapshot(array $snapshot, string $action, array $values): void
    {
        $userId = $snapshot['requested_by'] ?? null;
        if ($userId !== null && ! DB::table('users')->whereKey($userId)->exists()) {
            $userId = null;
        }
        AuditLog::create([
            'user_id' => $userId,
            'actor_type' => $userId === null ? 'system' : 'user',
            'action' => $action,
            'model_type' => 'backup_operation',
            'model_id' => null,
            'old_values' => null,
            'new_values' => array_merge(['operation_id' => $snapshot['id']], $values),
            'source_command' => 'admin.backups',
            'correlation_id' => $snapshot['id'],
            'created_at' => now(),
        ]);
    }
}
