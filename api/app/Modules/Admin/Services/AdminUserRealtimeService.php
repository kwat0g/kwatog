<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Events\AdminUserChanged;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one latest Admin User refresh per user and database transaction. */
final class AdminUserRealtimeService
{
    public function stage(User $user, string $action, ?string $versionOverride = null): void
    {
        $version = $versionOverride ?? $user->getAttribute('updated_at')?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new AdminUserChanged(
            userHashId: (string) $user->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "admin-user-refresh:{$user->getKey()}:{$transactionId}");
    }

    /** Refresh Admin User readers after a linked source record changes in bulk. */
    public function stageDirectoryRefresh(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new AdminUserChanged(
            userHashId: null,
            action: 'updated',
            version: now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "admin-user-directory-refresh:{$transactionId}");
    }
}
