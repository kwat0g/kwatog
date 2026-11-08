<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Events\UserInviteChanged;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one latest invite-directory refresh per database transaction. */
final class UserInviteRealtimeService
{
    public function stage(string $action, ?User $actor = null): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $usesPostgresTransactionId
            ? 'txid:'.$transactionId
            : now()->format('Y-m-d\\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new UserInviteChanged(
            action: $action,
            version: $version,
            actorHashId: $actor?->hash_id === null ? null : (string) $actor->hash_id,
        ), "user-invite-directory-refresh:{$transactionId}");
    }
}
