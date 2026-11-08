<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Services\OutboxService;
use App\Modules\ReturnManagement\Events\ReturnRequestChanged;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable ping publisher for Return Management aggregate writes. */
final class ReturnRequestRealtimeService
{
    public function stage(ReturnRequest $request, string $action): void
    {
        $customerHashId = null;
        if ($request->type?->value === 'customer_return' && $request->customer_id !== null) {
            $request->loadMissing('customer:id');
            $customerHashId = $request->customer?->hash_id;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ReturnRequestChanged(
            hashId: (string) $request->hash_id,
            action: $action,
            version: $request->updated_at?->toISOString() ?? now()->toISOString(),
            listScopes: $request->type?->value === 'supplier_return' ? ['all'] : ['customer', 'all'],
            actorId: auth()->id() ? (int) auth()->id() : null,
            customerHashId: $customerHashId,
        ), "return-request-refresh:{$request->getKey()}:{$transactionId}");

        // ReturnCaseResource embeds the current RMA status. Its own authorized
        // channel must refresh even when the case row itself did not change.
        $request->loadMissing('returnCase');
        if ($request->returnCase !== null) {
            app(ReturnCaseRealtimeService::class)->stage(
                $request->returnCase,
                'related_updated',
                version: $request->updated_at?->toISOString() ?? now()->toISOString(),
            );
        }
    }
}
