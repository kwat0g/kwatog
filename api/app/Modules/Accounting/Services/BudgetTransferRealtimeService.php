<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\BudgetTransferChanged;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for budget-transfer changes. */
final class BudgetTransferRealtimeService
{
    public function stage(BudgetTransfer $transfer, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorId = $actor instanceof User ? (int) $actor->getKey() : null;
        $actorId ??= $transfer->rejected_by ?? $transfer->approved_by ?? $transfer->requested_by;
        $actorHashId = $actorId !== null
            ? User::query()->find($actorId)?->hash_id
            : null;

        app(OutboxService::class)->recordCoalesced(new BudgetTransferChanged(
            transferHashId: (string) $transfer->hash_id,
            action: $action,
            version: $transfer->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
        ), "budget-transfer-refresh:{$transfer->getKey()}:{$transactionId}");
    }
}
