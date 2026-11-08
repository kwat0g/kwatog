<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\VendorChanged;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for vendor master-data changes. */
final class VendorRealtimeService
{
    public function stage(Vendor $vendor, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorHashId = $actor instanceof User ? $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new VendorChanged(
            hashId: (string) $vendor->hash_id,
            action: $action,
            version: $vendor->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
        ), "vendor-refresh:{$vendor->getKey()}:{$transactionId}");
    }
}
