<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Services\OutboxService;
use App\Modules\ReturnManagement\Events\ReturnCaseChanged;
use App\Modules\ReturnManagement\Models\ReturnCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, coalesced refresh publisher for case and child-record writes. */
final class ReturnCaseRealtimeService
{
    public function stage(ReturnCase $case, string $action, ?bool $publicChange = null, ?string $version = null): void
    {
        $case->loadMissing(['customer:id', 'vendor:id']);
        $isCustomer = $case->type?->value === 'customer';
        $hasPublicTimeline = $case->events()->where('is_public', true)->exists();
        $publicChange ??= $isCustomer || $hasPublicTimeline;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ReturnCaseChanged(
            hashId: (string) $case->hash_id,
            action: $action,
            version: $version ?? $case->updated_at?->toISOString() ?? now()->toISOString(),
            listScopes: $isCustomer ? ['customer', 'all'] : ['supplier', 'all'],
            customerHashId: $publicChange && $isCustomer ? $case->customer?->hash_id : null,
            supplierVendorHashId: $publicChange && ! $isCustomer && $hasPublicTimeline ? $case->vendor?->hash_id : null,
            actorId: auth()->id() ? (int) auth()->id() : null,
            publicChange: $publicChange,
        ), "return-case-refresh:{$case->getKey()}:{$transactionId}");
    }
}
