<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Jobs\CreateBackupJob;
use App\Common\Jobs\RestoreBackupJob;
use App\Common\Models\AuditLog;
use App\Common\Models\BackupOperation;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
    public const STATUS_ROLLBACK_REQUIRED = 'rollback_required';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    private const DATABASE_PATTERN = '/\Aogami-[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz\z/D';
    private const FILES_PATTERN = '/\Aogami-files-[A-Za-z0-9][A-Za-z0-9._-]*\.tar\.gz\z/D';

    public function queueBackup(User $actor): BackupOperation
    {
        try {
            $operation = DB::transaction(function () use ($actor): BackupOperation {
                $this->rejectIfOperationActive();

                $operation = BackupOperation::create([
                    'id' => (string) Str::uuid(),
                    'requested_by' => $actor->id,
                    'type' => self::TYPE_BACKUP,
                    'status' => self::STATUS_QUEUED,
                    'active_lock' => 'ogami-backup-recovery',
                    'metadata' => [
                        'scope' => 'database_and_private_files',
                        'manifest_committed' => false,
                        'offsite_configured' => $this->offsiteConfigured(),
                    ],
                ]);

                $this->audit($operation, $actor, 'backup.requested', [
                    'scope' => 'database_and_private_files',
                ]);

                return $operation;
            });
        } catch (Throwable $exception) {
            $this->throwAdmissionConflictOrRethrow($exception);
        }

        // Dispatch only after the row and its audit event have committed. If
        // the process dies between this line and the queue push, the reaper
        // below can reconcile the durable queued row instead of losing it.
        CreateBackupJob::dispatch($operation->id);

        return $operation;
    }

    public function queueRestore(
        User $actor,
        ?string $backupOperationId,
        ?string $databaseFilename,
        ?string $filesFilename,
        string $confirmation,
    ): BackupOperation {
        if ($backupOperationId === null || trim($backupOperationId) === '') {
            $databaseFilename = $this->validateArtifactName((string) $databaseFilename, 'database');
            $filesFilename = $filesFilename !== null && $filesFilename !== ''
                ? $this->validateArtifactName($filesFilename, 'files')
                : null;
            $expected = 'RESTORE '.$databaseFilename;
            if (! hash_equals($expected, trim($confirmation))) {
                throw ValidationException::withMessages([
                    'confirmation' => ['Type RESTORE followed by the database backup filename to continue.'],
                ]);
            }
        }

        [$manifest, $databaseArtifact, $filesArtifact] = $this->resolveRestoreManifest(
            $backupOperationId,
            $databaseFilename,
            $filesFilename,
        );
        $databaseFilename = (string) $databaseArtifact['name'];
        $filesFilename = $filesArtifact !== null ? (string) $filesArtifact['name'] : null;

        $expected = 'RESTORE '.$databaseFilename;
        if (! hash_equals($expected, trim($confirmation))) {
            throw ValidationException::withMessages([
                'confirmation' => ['Type RESTORE followed by the database backup filename to continue.'],
            ]);
        }

        $this->assertArtifactAvailable($databaseFilename, 'database', $databaseArtifact);
        if ($filesFilename !== null && $filesArtifact !== null) {
            $this->assertArtifactAvailable($filesFilename, 'files', $filesArtifact);
        }

        try {
            $operation = DB::transaction(function () use (
                $actor,
                $manifest,
                $databaseArtifact,
                $filesArtifact,
                $databaseFilename,
                $filesFilename,
            ): BackupOperation {
                $this->rejectIfOperationActive();

                $operation = BackupOperation::create([
                    'id' => (string) Str::uuid(),
                    'requested_by' => $actor->id,
                    'type' => self::TYPE_RESTORE,
                    'status' => self::STATUS_QUEUED,
                    'active_lock' => 'ogami-backup-recovery',
                    'artifacts' => [
                        'database' => $databaseArtifact,
                        'files' => $filesArtifact !== null && $filesFilename !== null ? $filesArtifact : null,
                    ],
                    'metadata' => [
                        'scope' => $filesFilename !== null ? 'database_and_private_files' : 'database_only',
                        'manifest_operation_id' => $manifest->id,
                        'manifest_committed' => true,
                        'offsite_configured' => $this->offsiteConfigured(),
                    ],
                ]);

                $this->audit($operation, $actor, 'backup.restore_requested', [
                    'manifest_operation_id' => $manifest->id,
                    'database_filename' => $databaseFilename,
                    'files_filename' => $filesFilename,
                ]);

                return $operation;
            });
        } catch (Throwable $exception) {
            $this->throwAdmissionConflictOrRethrow($exception);
        }

        RestoreBackupJob::dispatch($operation->id);

        return $operation;
    }

    /** @return array<string, mixed> */
    public function index(?string $cursor = null, int $perPage = 25): array
    {
        $perPage = max(5, min($perPage, 100));
        $page = BackupOperation::query()
            ->with('requestedBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        $operations = collect($page->items())
            ->map(fn (BackupOperation $operation): array => $this->serializeOperation($operation))
            ->values()
            ->all();

        $isFirstPage = $cursor === null || trim($cursor) === '';
        if ($isFirstPage) {
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
            // operation ledger existed, or by the cron path that does not write
            // one. They are read-only: with no committed manifest,
            // resolveRestoreManifest() refuses them, so the catalog says so
            // rather than offering a restore that would 422.
            foreach ($this->artifactNames('database') as $filename) {
                if (isset($managedNames[$filename])) {
                    continue;
                }
                $legacy = $this->decorateArtifact($this->describeLocalArtifact($filename, 'database'));
                $operations[] = [
                    'id' => null,
                    'type' => self::TYPE_BACKUP,
                    'status' => 'available',
                    'artifacts' => [
                        'database' => $legacy,
                        'files' => null,
                    ],
                    'manifest_committed' => false,
                    'restorable' => false,
                    'availability' => [
                        'database' => $legacy['availability'],
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

            // Legacy entries are not part of the cursor stream, so they are only
            // merged into the first page. Deliberately not sliced back to
            // $perPage: the cursor has already advanced past every ledger row
            // here, so dropping one would skip it on the next page too.
            usort($operations, static fn (array $a, array $b): int => strcmp(
                (string) ($b['created_at'] ?? ''),
                (string) ($a['created_at'] ?? ''),
            ));
        }

        return [
            'backups' => $operations,
            'next_cursor' => $page->nextCursor()?->encode(),
            'active_operation' => BackupOperation::query()
                ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_ROLLBACK_REQUIRED])
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
        $this->heartbeat($operationId, 'starting');

        $beforeDatabase = $this->artifactNames('database');
        $beforeFiles = $this->artifactNames('files');

        try {
            $exit = Artisan::call('db:full-backup', [
                '--dir' => $this->backupDirectory(),
                '--keep' => (string) config('backup.keep', 14),
            ]);

            if ($exit !== 0) {
                Log::error('Full backup command failed.', [
                    'operation_id' => $operationId,
                    'output' => trim(Artisan::output()),
                ]);
                throw new \RuntimeException('The full backup command failed. Check the backup log for details.');
            }

            $databaseFilename = $this->newestArtifact(array_diff($this->artifactNames('database'), $beforeDatabase));
            $filesFilename = $this->newestArtifact(array_diff($this->artifactNames('files'), $beforeFiles));
            if ($databaseFilename === null || $filesFilename === null) {
                throw new \RuntimeException('The backup command completed without publishing both artifacts.');
            }

            $artifacts = [
                'database' => $this->describeArtifact($databaseFilename, 'database'),
                'files' => $this->describeArtifact($filesFilename, 'files'),
            ];
            $metadata = array_merge((array) $operation->metadata, [
                'manifest_committed' => true,
                'manifest_committed_at' => now()->toIso8601String(),
                'manifest_version' => 1,
                'scope' => 'database_and_private_files',
            ]);

            $operation->forceFill([
                'status' => self::STATUS_COMPLETED,
                'active_lock' => null,
                'artifacts' => $artifacts,
                'metadata' => $metadata,
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'lease_expires_at' => null,
                'error_message' => null,
            ])->save();

            $this->audit($operation, null, 'backup.completed', [
                'manifest_version' => 1,
                'artifacts' => $artifacts,
            ]);
        } catch (Throwable $exception) {
            // db:full-backup publishes the database artifact before the private
            // files archive, so a second-phase failure leaves a real, usable
            // dump on disk. Deleting it would destroy a recovery point to tidy
            // the ledger, so it is kept — but recorded, so the failed operation
            // says what it left behind instead of the operator finding an
            // unexplained "legacy" archive in the catalog.
            $this->markFailed($operationId, $exception, [
                'orphan_artifacts' => [
                    'database' => array_values(array_diff($this->artifactNames('database'), $beforeDatabase)),
                    'files' => array_values(array_diff($this->artifactNames('files'), $beforeFiles)),
                ],
            ]);
            throw $exception;
        }
    }

    public function runRestore(string $operationId): void
    {
        $operation = BackupOperation::query()->findOrFail($operationId);
        $this->markRunning($operation);
        $original = [
            'id' => $operation->id,
            'requested_by' => $operation->requested_by,
            'type' => $operation->type,
            'artifacts' => $operation->artifacts,
            'metadata' => $operation->metadata,
            'started_at' => $operation->started_at,
        ];
        $this->heartbeat($operationId, 'starting');

        $temporaryFiles = [];
        $maintenance = false;
        $queuePaused = false;
        $destructiveStarted = false;
        $rollbackRequired = false;
        $metadata = (array) ($original['metadata'] ?? []);

        try {
            $databaseArtifact = is_array($original['artifacts']['database'] ?? null)
                ? $original['artifacts']['database']
                : null;
            if ($databaseArtifact === null) {
                throw new \RuntimeException('Restore operation has no database manifest.');
            }
            $filesArtifact = is_array($original['artifacts']['files'] ?? null)
                ? $original['artifacts']['files']
                : null;
            $databaseName = $this->validateArtifactName((string) ($databaseArtifact['name'] ?? ''), 'database');
            $filesName = $filesArtifact !== null
                ? $this->validateArtifactName((string) ($filesArtifact['name'] ?? ''), 'files')
                : null;

            // A database-only restore needs only a database rollback point.
            // Requiring a private-files archive in that scope can block a safe
            // DB recovery because the unrelated files volume is unavailable.
            $beforeDatabase = $this->artifactNames('database');
            $beforeFiles = $this->artifactNames('files');
            $preBackupCommand = $filesName !== null ? 'db:full-backup' : 'db:backup';
            $preBackupExit = Artisan::call($preBackupCommand, [
                '--dir' => $this->backupDirectory(),
                '--keep' => (string) config('backup.keep', 14),
            ]);
            if ($preBackupExit !== 0) {
                throw new \RuntimeException('Pre-restore backup failed; restore was not started.');
            }

            $preRestoreDatabase = $this->newestArtifact(array_diff($this->artifactNames('database'), $beforeDatabase));
            $preRestoreFiles = $filesName !== null
                ? $this->newestArtifact(array_diff($this->artifactNames('files'), $beforeFiles))
                : null;
            if ($preRestoreDatabase === null || ($filesName !== null && $preRestoreFiles === null)) {
                throw new \RuntimeException('Pre-restore backup did not publish the required rollback artifacts.');
            }

            $metadata = array_merge($metadata, [
                'restore_phase' => 'preflight_complete',
                'pre_restore_artifacts' => [
                    'database' => $this->describeArtifact($preRestoreDatabase, 'database'),
                    'files' => $preRestoreFiles !== null
                        ? $this->describeArtifact($preRestoreFiles, 'files')
                        : null,
                ],
            ]);
            $this->persistOperation($original, self::STATUS_RUNNING, null, $metadata);
            $this->heartbeat($operationId, 'preflight_complete');

            // Materialize and checksum both selected manifest members before
            // pausing consumers or entering maintenance.
            $databasePath = $this->materializeArtifact(
                $databaseName,
                'database',
                $operationId,
                $temporaryFiles,
                $databaseArtifact,
            );
            $filesPath = $filesName !== null
                ? $this->materializeArtifact(
                    $filesName,
                    'files',
                    $operationId,
                    $temporaryFiles,
                    $filesArtifact,
                )
                : null;

            $queuePaused = $this->pauseQueue();
            $downExit = Artisan::call('down', ['--render' => 'errors::503']);
            if ($downExit !== 0) {
                throw new \RuntimeException('Could not enter maintenance mode; restore was not started.');
            }
            $maintenance = true;
            if (! app()->maintenanceMode()->active()) {
                throw new \RuntimeException('The shared maintenance gate could not be observed after activation.');
            }

            $metadata['restore_phase'] = 'maintenance_active';
            $this->persistOperation($original, self::STATUS_RUNNING, null, $metadata);
            $this->heartbeat($operationId, 'maintenance_active');

            // From this point onward the database may be replaced. Any failure
            // leaves the gate and queue paused and becomes an explicit durable
            // rollback-required state for an operator.
            $destructiveStarted = true;
            $this->runDatabaseRestore($databasePath);
            $metadata['restore_phase'] = 'database_restored';
            $this->runMigrations();
            $metadata['restore_phase'] = 'migrated';
            // The restored ledger carries the snapshot's own mid-run operation
            // rows. Retire them before persisting this operation, or the
            // imported row keeps the singleton admission lock forever.
            $retired = $this->retireImportedActiveOperations($operationId);
            if ($retired !== []) {
                $metadata['retired_imported_operations'] = $retired;
            }
            $this->persistOperation($original, self::STATUS_RUNNING, null, $metadata);
            $this->heartbeat($operationId, 'migrated');

            if ($filesPath !== null) {
                $this->runFilesRestore($filesPath);
            }

            $metadata['restore_phase'] = 'completed';
            $metadata['rollback_required'] = false;
            $this->persistOperation($original, self::STATUS_COMPLETED, null, $metadata);
            $this->auditFromSnapshot($original, 'backup.restore_completed', $metadata);
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
            $rollbackRequired = $destructiveStarted;
            $failureMetadata = array_merge($metadata, [
                'restore_phase' => $rollbackRequired ? 'rollback_required' : 'failed_preflight',
                'rollback_required' => $rollbackRequired,
                'failure' => [
                    'message' => $error,
                    'at' => now()->toIso8601String(),
                ],
            ]);
            try {
                $this->persistOperation(
                    $original,
                    $rollbackRequired ? self::STATUS_ROLLBACK_REQUIRED : self::STATUS_FAILED,
                    $error,
                    $failureMetadata,
                );
            } catch (Throwable $ledgerException) {
                Log::error('Could not record failed restore operation.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($ledgerException),
                    'restore_error' => $error,
                ]);
            }
            try {
                $this->auditFromSnapshot(
                    $original,
                    $rollbackRequired ? 'backup.restore_rollback_required' : 'backup.restore_failed',
                    ['error' => $error, 'metadata' => $failureMetadata],
                );
            } catch (Throwable $auditException) {
                Log::error('Could not record failed restore audit event.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($auditException),
                    'restore_error' => $error,
                ]);
            }
            throw $exception;
        } finally {
            if ($maintenance && ! $rollbackRequired) {
                try {
                    Artisan::call('up');
                } catch (Throwable $exception) {
                    Log::critical('Could not leave maintenance mode after restore.', [
                        'operation_id' => $operationId,
                        'error' => $this->safeError($exception),
                    ]);
                }
            }
            if ($queuePaused && ! $rollbackRequired) {
                $this->resumeQueue();
            }
            if ($rollbackRequired) {
                Log::critical('Restore requires operator rollback before the application can resume.', [
                    'operation_id' => $operationId,
                ]);
            }
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    /** @param array<string, mixed> $extraMetadata */
    public function markFailed(string $operationId, Throwable $exception, array $extraMetadata = []): void
    {
        try {
            $operation = BackupOperation::query()->find($operationId);
            if ($operation === null || $operation->status === self::STATUS_ROLLBACK_REQUIRED) {
                return;
            }

            $operation->forceFill([
                'status' => self::STATUS_FAILED,
                'active_lock' => null,
                'error_message' => $this->safeError($exception),
                'metadata' => array_merge(
                    is_array($operation->metadata) ? $operation->metadata : [],
                    $extraMetadata,
                ),
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'lease_expires_at' => null,
            ])->save();
        } catch (Throwable) {
            // A database restore can replace the ledger while a job is running.
            // The original exception remains the useful failure signal in logs.
        }
    }

    public function heartbeat(string $operationId, ?string $phase = null): void
    {
        try {
            $operation = BackupOperation::query()->find($operationId);
            if ($operation === null) {
                return;
            }

            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            if ($phase !== null) {
                $metadata['heartbeat_phase'] = $phase;
                $metadata['heartbeat_at'] = now()->toIso8601String();
            }
            $operation->forceFill([
                'metadata' => $metadata,
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('backup.lease_seconds', 7200)),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Could not heartbeat backup operation.', [
                'operation_id' => $operationId,
                'error' => $this->safeError($exception),
            ]);
        }
    }

    public function reconcileStaleOperations(): int
    {
        // `lease_expires_at` is already an absolute deadline (heartbeat + lease),
        // so expiry is `lease_expires_at < now()`. Comparing it against
        // `now() - lease` instead doubled the fence to two full lease periods —
        // four hours at the default — during which a dead worker's row kept the
        // singleton `active_lock` and no operator could queue anything.
        $now = now();
        $unheardSince = $now->copy()->subSeconds((int) config('backup.lease_seconds', 7200));
        $operations = BackupOperation::query()
            ->where(function ($query) use ($now, $unheardSince): void {
                $query
                    ->where(function ($active) use ($now, $unheardSince): void {
                        $active->where('status', self::STATUS_RUNNING)
                            ->where(function ($lease) use ($now, $unheardSince): void {
                                $lease->where('lease_expires_at', '<', $now)
                                    // A row from a schema without the lease
                                    // columns still needs a grace window, or a
                                    // healthy in-flight operation is killed the
                                    // moment the sweep sees a null lease.
                                    ->orWhere(function ($legacy) use ($unheardSince): void {
                                        $legacy->whereNull('lease_expires_at')
                                            ->where('created_at', '<', $unheardSince);
                                    });
                            });
                    })
                    ->orWhere(function ($queued) use ($unheardSince): void {
                        $queued->where('status', self::STATUS_QUEUED)
                            ->where('created_at', '<', $unheardSince);
                    });
            })
            ->get();

        $reconciled = 0;
        foreach ($operations as $operation) {
            $snapshot = [
                'id' => $operation->id,
                'requested_by' => $operation->requested_by,
                'type' => $operation->type,
                'artifacts' => $operation->artifacts,
                'metadata' => $operation->metadata,
            ];
            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            $restoreMayHaveMutatedData = $operation->type === self::TYPE_RESTORE
                && ($metadata['restore_phase'] ?? null) !== 'preflight_complete'
                && ($metadata['restore_phase'] ?? null) !== 'failed_preflight';

            $restoreGateMayBeHeld = $operation->type === self::TYPE_RESTORE
                && app()->maintenanceMode()->active();
            if ($restoreMayHaveMutatedData || $restoreGateMayBeHeld) {
                $metadata['restore_phase'] = 'rollback_required';
                $metadata['rollback_required'] = true;
                $error = 'The restore worker lease expired after destructive work may have started. Operator rollback is required.';
                $operation->forceFill([
                    'status' => self::STATUS_ROLLBACK_REQUIRED,
                    'active_lock' => 'ogami-backup-recovery',
                    'error_message' => $error,
                    'metadata' => $metadata,
                    'heartbeat_at' => now(),
                    'lease_expires_at' => null,
                ])->save();
                $this->auditFromSnapshot($snapshot, 'backup.restore_rollback_required', [
                    'error' => $error,
                    'metadata' => $metadata,
                ]);
            } else {
                $error = 'The backup worker lease expired before the operation completed.';
                $operation->forceFill([
                    'status' => self::STATUS_FAILED,
                    'active_lock' => null,
                    'error_message' => $error,
                    'completed_at' => now(),
                    'heartbeat_at' => now(),
                    'lease_expires_at' => null,
                ])->save();
                $this->auditFromSnapshot($snapshot, 'backup.operation_reconciled', ['error' => $error]);
            }
            $reconciled++;
        }

        return $reconciled;
    }

    /**
     * Retire backup operations that a restore imported from its own snapshot.
     *
     * Every artifact contains the ledger row of the operation that produced it,
     * captured mid-run: `runBackup()` marks the row `running` with the
     * singleton `active_lock` held, and only completes it *after* `pg_dump` has
     * already read the table. Restoring any admin-created backup therefore
     * re-imports a row that owns the admission lock and whose worker died with
     * the previous database, so `rejectIfOperationActive()` refuses every
     * subsequent backup and restore — the backup surface bricks itself on the
     * first successful recovery. The same row also makes the next `active_lock`
     * write collide with `backup_operations_active_lock_unique`.
     *
     * `rollback_required` is deliberately left alone: it is an unresolved
     * destructive-state signal, not a stale lease, and an admin-created
     * artifact cannot contain one (a backup cannot be queued while one exists).
     *
     * @return array<int, string> retired operation ids
     */
    public function retireImportedActiveOperations(string $currentOperationId): array
    {
        $imported = BackupOperation::query()
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->whereKeyNot($currentOperationId)
            ->get();

        $retired = [];
        foreach ($imported as $operation) {
            $metadata = is_array($operation->metadata) ? $operation->metadata : [];
            $metadata['retired_by_restore'] = $currentOperationId;
            $metadata['retired_at'] = now()->toIso8601String();

            $operation->forceFill([
                'status' => self::STATUS_FAILED,
                'active_lock' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
                'metadata' => $metadata,
                'error_message' => 'This operation was captured mid-run inside the restored snapshot; its worker did not survive the restore.',
                'completed_at' => now(),
            ])->save();

            $retired[] = (string) $operation->id;
        }

        return $retired;
    }

    public function rollback(string $operationId): void
    {
        $operation = BackupOperation::query()->findOrFail($operationId);
        if ($operation->status !== self::STATUS_ROLLBACK_REQUIRED) {
            throw ValidationException::withMessages([
                'operation' => ['Only a restore marked rollback-required can be recovered.'],
            ]);
        }

        $snapshot = [
            'id' => $operation->id,
            'requested_by' => $operation->requested_by,
            'type' => $operation->type,
            'artifacts' => $operation->artifacts,
            'metadata' => $operation->metadata,
            'started_at' => $operation->started_at,
        ];
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $preRestore = is_array($metadata['pre_restore_artifacts'] ?? null)
            ? $metadata['pre_restore_artifacts']
            : [];
        $databaseArtifact = is_array($preRestore['database'] ?? null) ? $preRestore['database'] : null;
        $filesArtifact = is_array($preRestore['files'] ?? null) ? $preRestore['files'] : null;
        if ($databaseArtifact === null) {
            throw new \RuntimeException('Rollback metadata does not contain a database artifact.');
        }

        $temporaryFiles = [];
        $maintenance = false;
        $queuePaused = false;
        try {
            $databaseName = $this->validateArtifactName((string) ($databaseArtifact['name'] ?? ''), 'database');
            $databasePath = $this->materializeArtifact(
                $databaseName,
                'database',
                $operationId.'-rollback',
                $temporaryFiles,
                $databaseArtifact,
            );
            $filesPath = null;
            if ($filesArtifact !== null) {
                $filesName = $this->validateArtifactName((string) ($filesArtifact['name'] ?? ''), 'files');
                $filesPath = $this->materializeArtifact(
                    $filesName,
                    'files',
                    $operationId.'-rollback',
                    $temporaryFiles,
                    $filesArtifact,
                );
            }

            $queuePaused = $this->pauseQueue();
            if (! app()->maintenanceMode()->active()) {
                $downExit = Artisan::call('down', ['--render' => 'errors::503']);
                if ($downExit !== 0 || ! app()->maintenanceMode()->active()) {
                    throw new \RuntimeException('Could not establish the shared maintenance gate for rollback.');
                }
            }
            $maintenance = true;

            $this->runDatabaseRestore($databasePath);
            $this->runMigrations();
            $retired = $this->retireImportedActiveOperations($operationId);
            if ($retired !== []) {
                $metadata['retired_imported_operations'] = $retired;
            }
            if ($filesPath !== null) {
                $this->runFilesRestore($filesPath);
            }

            $metadata['restore_phase'] = 'rolled_back';
            $metadata['rollback_required'] = false;
            $metadata['rollback_completed_at'] = now()->toIso8601String();
            $this->persistOperation($snapshot, self::STATUS_ROLLED_BACK, null, $metadata);
            $this->auditFromSnapshot($snapshot, 'backup.restore_rolled_back', ['metadata' => $metadata]);
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
            $metadata['restore_phase'] = 'rollback_required';
            $metadata['rollback_required'] = true;
            $metadata['rollback_failure'] = [
                'message' => $error,
                'at' => now()->toIso8601String(),
            ];
            try {
                $this->persistOperation($snapshot, self::STATUS_ROLLBACK_REQUIRED, $error, $metadata);
            } catch (Throwable $ledgerException) {
                Log::error('Could not preserve rollback-required state.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($ledgerException),
                    'rollback_error' => $error,
                ]);
            }
            try {
                $this->auditFromSnapshot($snapshot, 'backup.rollback_failed', ['error' => $error]);
            } catch (Throwable $auditException) {
                Log::error('Could not record rollback failure audit event.', [
                    'operation_id' => $operationId,
                    'error' => $this->safeError($auditException),
                    'rollback_error' => $error,
                ]);
            }
            throw $exception;
        } finally {
            if ($maintenance) {
                try {
                    Artisan::call('up');
                } catch (Throwable $exception) {
                    Log::critical('Could not leave maintenance mode after rollback.', [
                        'operation_id' => $operationId,
                        'error' => $this->safeError($exception),
                    ]);
                }
            }
            if ($queuePaused) {
                $this->resumeQueue();
            }
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    private function pauseQueue(): bool
    {
        Queue::pauseFor(
            (string) config('backup.queue_connection', config('queue.default', 'redis')),
            (string) config('backup.queue_name', 'default'),
            (int) config('backup.queue_pause_seconds', 21600),
        );

        return true;
    }

    private function resumeQueue(): void
    {
        try {
            Queue::resume(
                (string) config('backup.queue_connection', config('queue.default', 'redis')),
                (string) config('backup.queue_name', 'default'),
            );
        } catch (Throwable $exception) {
            Log::critical('Could not resume the backup queue.', [
                'error' => $this->safeError($exception),
            ]);
        }
    }

    private function runMigrations(): void
    {
        DB::purge();
        $migrateExit = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
        if ($migrateExit !== 0) {
            throw new \RuntimeException('Database restore completed but migrations could not be applied.');
        }
    }

    /** @return array<string, mixed> */
    private function serializeOperation(BackupOperation $operation): array
    {
        $artifacts = is_array($operation->artifacts) ? $operation->artifacts : [];
        $databaseArtifact = $artifacts['database'] ?? null;
        $filesArtifact = $artifacts['files'] ?? null;
        $metadata = is_array($operation->metadata) ? $operation->metadata : [];
        $manifestCommitted = (bool) (($metadata['manifest_committed'] ?? false)
            && $operation->status === self::STATUS_COMPLETED);
        $database = is_array($databaseArtifact) ? $this->decorateArtifact($databaseArtifact) : null;
        $files = is_array($filesArtifact) ? $this->decorateArtifact($filesArtifact) : null;
        $databaseAvailability = $database['availability'] ?? 'missing';
        $filesAvailability = $files['availability'] ?? null;

        return [
            'id' => $operation->id,
            'type' => $operation->type,
            'status' => $operation->status,
            'artifacts' => [
                'database' => $database,
                'files' => $files,
            ],
            'manifest_committed' => $manifestCommitted,
            'restorable' => $manifestCommitted
                && is_array($databaseArtifact)
                && in_array($databaseAvailability, ['local', 'remote'], true)
                && ($filesAvailability === null || in_array($filesAvailability, ['local', 'remote'], true)),
            'availability' => [
                'database' => $databaseAvailability,
                'files' => $filesAvailability,
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
        if ($operation->status === self::STATUS_ROLLBACK_REQUIRED) {
            throw new \RuntimeException('This operation requires operator rollback before it can run again.');
        }
        if (! in_array($operation->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            throw new \RuntimeException('This backup operation is no longer runnable.');
        }

        $operation->forceFill([
            'status' => self::STATUS_RUNNING,
            'active_lock' => 'ogami-backup-recovery',
            'lease_token' => (string) Str::uuid(),
            'attempts' => ((int) $operation->attempts) + 1,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds((int) config('backup.lease_seconds', 7200)),
            'error_message' => null,
        ])->save();
    }

    private function persistOperation(array $original, string $status, ?string $error, array $metadata): void
    {
        $requestedBy = $original['requested_by'] ?? null;
        // `where('id', ...)`, not `whereKey()`: Query\Builder has no whereKey, so
        // the call fell through to __call and became a dynamic
        // `where('key', ...)` against a users.key column that does not exist.
        // Every restore therefore threw SQLSTATE[42703] at its first ledger
        // write, immediately after preflight.
        if ($requestedBy !== null && ! DB::table('users')->where('id', $requestedBy)->exists()) {
            $requestedBy = null;
        }

        $active = in_array($status, [
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_ROLLBACK_REQUIRED,
        ], true);
        $payload = [
            'requested_by' => $requestedBy,
            'type' => $original['type'],
            'status' => $status,
            'artifacts' => $original['artifacts'],
            'metadata' => $metadata,
            'error_message' => $error,
            'started_at' => $original['started_at'] ?? now(),
            'completed_at' => in_array($status, [
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                self::STATUS_ROLLED_BACK,
            ], true) ? now() : null,
        ];

        // A restore can legitimately be replaying a dump taken before the
        // latest lease migration. Apply the core ledger update first and only
        // send hardened columns when that restored schema contains them.
        if (Schema::hasColumn('backup_operations', 'active_lock')) {
            $payload['active_lock'] = $active ? 'ogami-backup-recovery' : null;
        }
        if (Schema::hasColumn('backup_operations', 'heartbeat_at')) {
            $payload['heartbeat_at'] = now();
        }
        if (Schema::hasColumn('backup_operations', 'lease_expires_at')) {
            $payload['lease_expires_at'] = $active
                ? now()->addSeconds((int) config('backup.lease_seconds', 7200))
                : null;
        }

        BackupOperation::query()->updateOrCreate(
            ['id' => $original['id']],
            $payload,
        );
    }

    private function rejectIfOperationActive(): void
    {
        $active = BackupOperation::query()
            ->whereIn('status', [
                self::STATUS_QUEUED,
                self::STATUS_RUNNING,
                self::STATUS_ROLLBACK_REQUIRED,
            ])
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'operation' => ['Another backup or restore operation is active or requires rollback.'],
            ]);
        }
    }

    private function throwAdmissionConflictOrRethrow(Throwable $exception): void
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'backup_operations_active_lock')
            || str_contains($message, 'backup_operations_active_lock_unique')) {
            throw ValidationException::withMessages([
                'operation' => ['Another backup or restore operation is already queued or running.'],
            ]);
        }

        throw $exception;
    }

    /**
     * Resolve a restore request to a completed backup operation manifest.
     *
     * Filename-only clients are retained temporarily for compatibility, but
     * they still have to match a completed ledger manifest. The service never
     * trusts a caller-supplied filename as the source of checksum or scope
     * metadata.
     *
     * @return array{0: BackupOperation, 1: array<string, mixed>, 2: ?array<string, mixed>}
     */
    private function resolveRestoreManifest(
        ?string $backupOperationId,
        ?string $databaseFilename,
        ?string $filesFilename,
    ): array {
        $manifest = null;
        if ($backupOperationId !== null && trim($backupOperationId) !== '') {
            $manifest = BackupOperation::query()
                ->whereKey(trim($backupOperationId))
                ->where('type', self::TYPE_BACKUP)
                ->where('status', self::STATUS_COMPLETED)
                ->first();
        } else {
            $databaseFilename = $databaseFilename !== null
                ? $this->validateArtifactName($databaseFilename, 'database')
                : null;
            $filesFilename = $filesFilename !== null && $filesFilename !== ''
                ? $this->validateArtifactName($filesFilename, 'files')
                : null;
            $manifest = BackupOperation::query()
                ->where('type', self::TYPE_BACKUP)
                ->where('status', self::STATUS_COMPLETED)
                ->latest()
                ->get()
                ->first(function (BackupOperation $candidate) use ($databaseFilename, $filesFilename): bool {
                    $artifacts = is_array($candidate->artifacts) ? $candidate->artifacts : [];
                    $database = is_array($artifacts['database'] ?? null) ? $artifacts['database'] : null;
                    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : null;
                    return $database !== null
                        && ($databaseFilename === null || ($database['name'] ?? null) === $databaseFilename)
                        && ($filesFilename === null || ($files['name'] ?? null) === $filesFilename);
                });
        }

        if (! $manifest) {
            throw ValidationException::withMessages([
                'backup_operation_id' => ['The selected backup manifest is not available for restore.'],
            ]);
        }

        $artifacts = is_array($manifest->artifacts) ? $manifest->artifacts : [];
        $databaseArtifact = is_array($artifacts['database'] ?? null) ? $artifacts['database'] : null;
        $filesArtifact = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : null;
        if ($databaseArtifact === null || ! is_string($databaseArtifact['name'] ?? null)) {
            throw ValidationException::withMessages([
                'backup_operation_id' => ['The selected backup manifest has no database artifact.'],
            ]);
        }

        $resolvedDatabase = $this->validateArtifactName((string) $databaseArtifact['name'], 'database');
        if ($databaseFilename !== null && $resolvedDatabase !== $this->validateArtifactName($databaseFilename, 'database')) {
            throw ValidationException::withMessages([
                'database_filename' => ['The selected database artifact does not belong to this manifest.'],
            ]);
        }

        if ($filesFilename !== null && $filesArtifact === null) {
            throw ValidationException::withMessages([
                'files_filename' => ['The selected manifest has no private-files artifact.'],
            ]);
        }
        if ($filesFilename !== null && $filesArtifact !== null
            && $filesFilename !== $this->validateArtifactName((string) ($filesArtifact['name'] ?? ''), 'files')) {
            throw ValidationException::withMessages([
                'files_filename' => ['The selected private-files artifact does not belong to this manifest.'],
            ]);
        }

        return [$manifest, $databaseArtifact, $filesFilename !== null ? $filesArtifact : null];
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

    /**
     * Describe a freshly published artifact, hashing it once for the manifest.
     *
     * Only called from the backup worker. The list path uses
     * describeLocalArtifact() instead — see decorateArtifact().
     *
     * @return array<string, mixed>
     */
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

    /**
     * Describe a local artifact that has no manifest, without hashing it.
     *
     * A legacy/cron artifact has no recorded checksum to compare against, so
     * hashing it on a list request buys nothing and costs the whole file.
     * `sha256` stays null, which the catalog renders as "not recorded".
     *
     * @return array<string, mixed>
     */
    private function describeLocalArtifact(string $filename, string $kind): array
    {
        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$filename;
        $size = is_file($path) ? filesize($path) : false;

        return [
            'name' => $filename,
            'kind' => $kind,
            'size' => $size === false ? 0 : $size,
            'sha256' => null,
            'created_at' => $this->artifactCreatedAt($filename),
            'source' => 'local',
        ];
    }

    /**
     * Decorate a manifest artifact for the recovery catalog.
     *
     * The catalog is a list endpoint that the admin page polls every 5s while an
     * operation runs. It deliberately does NOT re-hash artifacts: running
     * `hash_file('sha256')` over every retained database and private-file
     * archive is gigabytes of I/O per request, it scales with `backup.keep`, and
     * a missing local artifact additionally spawned one `aws s3api head-object`
     * subprocess — 30s timeout each — per row. Both ran on every poll.
     *
     * The recorded size against the manifest is what a list request can prove
     * cheaply, so the verdict is reported as `manifest_match`, never
     * `checksum_verified`. SHA-256 is still enforced, but on the restore path
     * (`assertArtifactAvailable()` / `materializeArtifact()`), which is the only
     * place a verdict has to be true of the bytes about to be replayed — a
     * list-time hash is already stale by the time an operator clicks Restore.
     *
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    private function decorateArtifact(array $artifact): array
    {
        $availability = $this->artifactAvailability($artifact);
        return array_merge($artifact, [
            'availability' => $availability,
            'integrity' => match ($availability) {
                'local', 'remote' => 'manifest_match',
                'mismatch' => 'mismatch',
                default => 'not_verified',
            },
        ]);
    }

    /** @param array<string, mixed> $artifact */
    private function artifactAvailability(array $artifact): string
    {
        $filename = is_string($artifact['name'] ?? null) ? $artifact['name'] : '';
        $kind = ($artifact['kind'] ?? '') === 'files' ? 'files' : 'database';
        try {
            $filename = $this->validateArtifactName($filename, $kind);
        } catch (Throwable) {
            return 'missing';
        }

        $local = $this->backupDirectory().DIRECTORY_SEPARATOR.$filename;
        if (is_file($local)) {
            return $this->sizeMatchesManifest(filesize($local), $artifact) ? 'local' : 'mismatch';
        }

        if (! $this->offsiteConfigured()) {
            return 'missing';
        }

        $remote = $this->remoteArtifactMetadata($filename, cached: true);
        if ($remote === null) {
            return 'missing';
        }

        return $this->sizeMatchesManifest($remote['size'] ?? null, $artifact) ? 'remote' : 'mismatch';
    }

    /** @param array<string, mixed> $artifact */
    private function sizeMatchesManifest(int|false|null $actual, array $artifact): bool
    {
        $expected = $artifact['size'] ?? null;
        if (! is_numeric($expected)) {
            // A legacy artifact has no recorded size. Presence is all the
            // catalog can claim; the restore path still refuses it because it
            // has no committed manifest.
            return true;
        }

        return $actual !== false && $actual !== null && (int) $expected === (int) $actual;
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

    /** @param array<string, mixed>|null $expected */
    private function assertArtifactIntegrity(string $path, string $kind, ?array $expected): void
    {
        if (! is_file($path)) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact could not be verified.'],
            ]);
        }

        if ($expected === null) {
            return;
        }

        $expectedSize = $expected['size'] ?? null;
        $actualSize = filesize($path);
        if (is_numeric($expectedSize) && ($actualSize === false || (int) $expectedSize !== (int) $actualSize)) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact size does not match its manifest.'],
            ]);
        }

        $expectedChecksum = $expected['sha256'] ?? null;
        if (is_string($expectedChecksum) && $expectedChecksum !== '') {
            $actualChecksum = hash_file('sha256', $path);
            if (! is_string($actualChecksum) || ! hash_equals(strtolower($expectedChecksum), strtolower($actualChecksum))) {
                throw ValidationException::withMessages([
                    $kind.'_filename' => ['The selected backup artifact checksum does not match its manifest.'],
                ]);
            }
        }
    }

    /** @param array<string, mixed>|null $remote @param array<string, mixed> $expected */
    private function assertRemoteArtifactIntegrity(?array $remote, string $kind, array $expected): void
    {
        if ($remote === null) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected off-site backup artifact does not exist.'],
            ]);
        }
        $expectedSize = $expected['size'] ?? null;
        if (is_numeric($expectedSize) && (int) $expectedSize !== (int) ($remote['size'] ?? -1)) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The off-site backup artifact size does not match its manifest.'],
            ]);
        }
        $expectedChecksum = $expected['sha256'] ?? null;
        $remoteChecksum = $remote['sha256'] ?? null;
        if (is_string($expectedChecksum) && $expectedChecksum !== ''
            && (! is_string($remoteChecksum) || $remoteChecksum === ''
                || ! hash_equals(strtolower($expectedChecksum), strtolower($remoteChecksum)))) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The off-site backup artifact checksum metadata does not match its manifest.'],
            ]);
        }
    }

    /** @param array<string, mixed>|null $expected */
    private function assertArtifactAvailable(string $filename, string $kind, ?array $expected = null): void
    {
        if (is_file($this->backupDirectory().DIRECTORY_SEPARATOR.$filename)) {
            $path = $this->safeArtifactPath($filename, $kind);
            $this->assertArtifactIntegrity($path, $kind, $expected);
            return;
        }

        if (! $this->offsiteConfigured()) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact is not available locally or in off-site storage.'],
            ]);
        }

        $remote = $this->remoteArtifactMetadata($filename);
        if ($remote === null) {
            throw ValidationException::withMessages([
                $kind.'_filename' => ['The selected backup artifact is not available locally or in off-site storage.'],
            ]);
        }
        if ($expected !== null) {
            $this->assertRemoteArtifactIntegrity($remote, $kind, $expected);
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

    /** @param array<int, string> $temporaryFiles @param array<string, mixed>|null $expected */
    private function materializeArtifact(
        string $filename,
        string $kind,
        string $operationId,
        array &$temporaryFiles,
        ?array $expected = null,
    ): string
    {
        if (is_file($this->backupDirectory().DIRECTORY_SEPARATOR.$filename)) {
            $path = $this->safeArtifactPath($filename, $kind);
            $this->assertArtifactIntegrity($path, $kind, $expected);
            return $path;
        }

        $remote = $this->s3Uri($filename);
        if ($remote === null) {
            throw new \RuntimeException('Selected backup artifact is unavailable.');
        }
        $remoteMetadata = $this->remoteArtifactMetadata($filename);
        if ($remoteMetadata === null) {
            throw new \RuntimeException('Selected off-site backup artifact does not exist.');
        }
        if ($expected !== null) {
            $this->assertRemoteArtifactIntegrity($remoteMetadata, $kind, $expected);
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
        $this->assertArtifactIntegrity($temporary, $kind, $expected);
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
        $location = $this->s3Location($filename);
        if ($location === null) {
            return null;
        }

        return 's3://'.$location['bucket'].'/'.$location['key'];
    }

    /** @return array{bucket: string, key: string}|null */
    private function s3Location(string $filename): ?array
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

        return ['bucket' => $bucket, 'key' => $this->s3Key($filename)];
    }

    /**
     * Head an off-site artifact.
     *
     * `$cached` is for the catalog only. Without it a single list request with
     * 14 retained snapshots spawns up to 28 AWS subprocesses, each with a 30s
     * timeout, every 5s while an operation is running. The restore path always
     * reads through (`$cached = false`) because a stale head-object verdict must
     * never stand in for the object it is about to download.
     *
     * @return array<string, mixed>|null
     */
    private function remoteArtifactMetadata(string $filename, bool $cached = false): ?array
    {
        $location = $this->s3Location($filename);
        if ($location === null) {
            return null;
        }

        if (! $cached) {
            return $this->headRemoteArtifact($location);
        }

        // Cache::remember treats a null hit as a miss, so a missing object would
        // re-shell on every poll. Wrap the verdict instead of the payload.
        $probe = Cache::remember(
            'backup:remote-artifact:'.sha1($location['bucket'].'/'.$location['key']),
            now()->addSeconds((int) config('backup.remote_probe_cache_seconds', 120)),
            fn (): array => ['metadata' => $this->headRemoteArtifact($location)],
        );

        return is_array($probe['metadata'] ?? null) ? $probe['metadata'] : null;
    }

    /** @param array{bucket: string, key: string} $location @return array<string, mixed>|null */
    private function headRemoteArtifact(array $location): ?array
    {
        $process = new Process([
            'aws',
            's3api',
            'head-object',
            '--bucket',
            $location['bucket'],
            '--key',
            $location['key'],
            '--query',
            '{size:ContentLength,sha256:Metadata.sha256}',
            '--output',
            'json',
        ], null, $this->s3Environment(), null, 30);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }

        $metadata = json_decode(trim($process->getOutput()), true);
        return is_array($metadata) ? [
            'size' => isset($metadata['size']) ? (int) $metadata['size'] : null,
            'sha256' => isset($metadata['sha256']) && is_string($metadata['sha256'])
                ? $metadata['sha256']
                : null,
        ] : null;
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
        // See persistOperation(): Query\Builder has no whereKey().
        if ($userId !== null && ! DB::table('users')->where('id', $userId)->exists()) {
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
