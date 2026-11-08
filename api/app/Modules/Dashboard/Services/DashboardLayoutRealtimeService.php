<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Events\DashboardLayoutChanged;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one self-only dashboard layout refresh per user and transaction. */
final class DashboardLayoutRealtimeService
{
    public function stage(User $user, string $action): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $eventVersion = $usesPostgresTransactionId
            ? 'txid:'.$transactionId
            : now()->format('Y-m-d\\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new DashboardLayoutChanged(
            userHashId: (string) $user->hash_id,
            action: $action,
            version: $eventVersion,
            eventId: (string) Str::uuid(),
            timestamp: now()->toISOString(),
        ), "dashboard-layout-refresh:{$user->getKey()}:{$transactionId}");
    }
}
