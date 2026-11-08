<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\OutboxService;
use App\Modules\Admin\Events\ApprovalDelegationChanged;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one list refresh for delegation changes in each owning transaction. */
final class ApprovalDelegationRealtimeService
{
    public function stage(ApprovalDelegation $delegation, string $action): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0
            && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $recipientIds = User::query()
            ->whereKey(array_values(array_unique([
                (int) $delegation->delegator_user_id,
                (int) $delegation->delegate_user_id,
                (int) $delegation->getRawOriginal('delegator_user_id'),
                (int) $delegation->getRawOriginal('delegate_user_id'),
            ])))
            ->where('is_active', true)
            ->get()
            ->map(fn (User $user): string => (string) $user->hash_id)
            ->filter(fn (string $hashId): bool => $hashId !== '')
            ->unique()
            ->values()
            ->all();
        $actor = auth()->user();
        $version = $delegation->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $delegation->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new ApprovalDelegationChanged(
            recipientUserHashIds: $recipientIds,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "approval-delegation-refresh:{$delegation->getKey()}:{$transactionId}");
    }
}
