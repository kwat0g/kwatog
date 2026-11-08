<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\AdminBackupsChanged;
use App\Common\Models\BackupOperation;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Routes backup-ledger changes through the outbox and file-only changes to the same signal. */
final class AdminBackupsRealtimeService
{
    public function stageOperation(BackupOperation $operation, string $action): void
    {
        $this->stage(
            'backup_operation',
            (string) $operation->getKey(),
            $action,
            $operation->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
        );
    }

    public function signalManifest(string $manifestId, string $action, ?string $version = null): void
    {
        $signal = new AdminBackupsChanged(
            entityType: 'backup_manifest',
            entityHashId: hash_hmac('sha256', $manifestId, (string) config('app.key')),
            action: $action,
            version: $version ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $this->actorHashId(),
        );

        try {
            event($signal);
        } catch (Throwable $exception) {
            // Filesystem-side backups must remain usable when broadcast transport is down.
            Log::critical('Backup manifest changed but its realtime signal could not be queued.', [
                'event_id' => $signal->eventId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function stage(string $entityType, string $entityId, string $action, string $version): void
    {
        $signal = $this->makeSignal($entityType, $entityId, $action, $version);
        if (! Schema::hasTable('event_outbox')) {
            Log::warning('Backup realtime outbox is unavailable; using queued broadcast fallback.', [
                'event_id' => $signal->eventId,
            ]);
            $this->dispatchBestEffort($signal);

            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        try {
            app(OutboxService::class)->recordCoalesced(
                $signal,
                "admin-backup-refresh:{$entityType}:{$signal->entityHashId}:{$transactionId}",
            );
        } catch (Throwable $exception) {
            // Keep backup/restore state writes working across restored schemas
            // that predate the outbox table; the queued signal remains after-commit.
            Log::critical('Backup operation changed but its durable realtime outbox write failed.', [
                'event_id' => $signal->eventId,
                'error' => $exception->getMessage(),
            ]);
            $this->dispatchBestEffort($signal);
        }
    }

    private function actorHashId(): ?string
    {
        $actor = auth()->user();

        return $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
    }

    private function makeSignal(string $entityType, string $entityId, string $action, string $version): AdminBackupsChanged
    {
        return new AdminBackupsChanged(
            entityType: $entityType,
            entityHashId: hash_hmac('sha256', $entityId, (string) config('app.key')),
            action: $action,
            version: $version,
            actorHashId: $this->actorHashId(),
        );
    }

    private function dispatchBestEffort(AdminBackupsChanged $signal): void
    {
        try {
            event($signal);
        } catch (Throwable $exception) {
            Log::critical('Backup realtime fallback could not be queued.', [
                'event_id' => $signal->eventId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
