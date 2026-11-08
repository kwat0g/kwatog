<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Events\UserDisplayPreferencesChanged;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages an exact-user settings refresh in the preference-write transaction. */
final class UserDisplayPreferencesRealtimeService
{
    public function stage(User $user): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $usesPostgresTransactionId
            ? 'txid:'.$transactionId
            : now()->format('Y-m-d\\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new UserDisplayPreferencesChanged(
            userHashId: (string) $user->hash_id,
            version: $version,
        ), "user-display-preferences-refresh:{$user->getKey()}:{$transactionId}");
    }
}
