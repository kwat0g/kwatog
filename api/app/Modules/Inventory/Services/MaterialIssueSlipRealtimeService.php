<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\MaterialIssueSlipChanged;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces one slip's writes into a durable after-commit refresh ping. */
final class MaterialIssueSlipRealtimeService
{
    public function stage(MaterialIssueSlip $slip, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        $outbox = app(OutboxService::class);
        $outbox->recordCoalesced(new MaterialIssueSlipChanged(
            entityHashId: (string) $slip->hash_id,
            action: $action,
            version: $slip->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "material-issue-slip-refresh:{$slip->getKey()}:{$transactionId}");

    }
}
