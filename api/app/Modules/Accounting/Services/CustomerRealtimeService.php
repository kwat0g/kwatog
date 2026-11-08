<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\CustomerChanged;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages refresh signals, coalescing only Customer creation batches from imports. */
final class CustomerRealtimeService
{
    public function stage(
        Customer $customer,
        string $action,
        ?string $version = null,
        bool $includeCustomerProfile = true,
    ): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $customerHashId = (string) $customer->hash_id;
        // The atomic master-data importer creates many customers in one PG
        // transaction. These are new rows with no existing detail or portal
        // viewers, so one list-channel ping safely refreshes that import batch.
        // Keep updates/deletes/restores record-scoped so customer portals and
        // open detail pages still receive their own authorized signal.
        $dedupeKey = $action === 'created'
            ? "customer-create-refresh:{$transactionId}"
            : "customer-refresh:{$transactionId}:{$customerHashId}";
        $existingPayload = DB::table('event_outbox')
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->value('payload');
        $isBatch = false;
        $previousProfileAudience = null;

        if (is_string($existingPayload) && $existingPayload !== '') {
            $previous = json_decode($existingPayload, true);
            $previousProfileAudience = is_array($previous)
                ? (bool) ($previous['includeCustomerProfile'] ?? true)
                : true;
            $previousHashId = is_array($previous) ? ($previous['customerHashId'] ?? null) : null;
            $isBatch = is_array($previous)
                && (($previous['isBatch'] ?? false) === true
                    || ($previousHashId !== null && $previousHashId !== $customerHashId));
        }

        // A coalesced event must retain every authorized audience required by
        // any write in the transaction. Internal child changes can suppress a
        // portal signal, but cannot undo a profile-channel signal already
        // staged by an actual Customer model change.
        $includeCustomerProfile = $includeCustomerProfile || $previousProfileAudience === true;

        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new CustomerChanged(
                customerHashId: $isBatch ? null : $customerHashId,
                action: $action,
                version: $version ?? $customer->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
                isBatch: $isBatch,
                includeCustomerProfile: $includeCustomerProfile,
            ),
            $dedupeKey,
        );
    }
}
