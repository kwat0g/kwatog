<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestChanged;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable, coalesced refresh ping per leave request and transaction. */
final class LeaveRequestRealtimeService
{
    public function stage(LeaveRequest $request, string $action, ?string $transactionId = null): void
    {
        $transactionId ??= $this->transactionToken();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new LeaveRequestChanged(
                leaveRequestHashId: (string) $request->hash_id,
                action: $action,
                version: $request->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            'leave-request-refresh:'.(string) $request->getKey().':'.$transactionId,
        );
    }

    /** Shared transaction token also coalesces the badge signal for bulk writes. */
    public function transactionToken(): string
    {
        return DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
    }
}
