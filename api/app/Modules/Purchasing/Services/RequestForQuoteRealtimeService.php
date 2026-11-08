<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\RequestForQuoteChanged;
use App\Modules\Purchasing\Events\SupplierRfqChanged;
use App\Modules\Purchasing\Models\RequestForQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages internal RFQ updates and a vendor-scoped projection for invited suppliers. */
final class RequestForQuoteRealtimeService
{
    private const MAX_VENDOR_CHANNELS_PER_EVENT = 50;

    public function __construct(private readonly OutboxService $outbox) {}

    public function changed(RequestForQuote $rfq, string $action, ?User $actor = null): void
    {
        $event = new RequestForQuoteChanged(
            rfqHashId: (string) $rfq->hash_id,
            action: $action,
            version: $rfq->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: $actor?->id,
            eventId: (string) Str::uuid(),
            actorHashId: $actor?->hash_id === null ? null : (string) $actor->hash_id,
        );
        $this->outbox->record($event, 'rfq-refresh:'.$event->eventId);

        if ($rfq->issued_at === null) {
            return;
        }

        $rfq->loadMissing('invitations:id,request_for_quote_id,vendor_id');
        $supplierVendorHashIds = Vendor::query()
            ->where('is_active', true)
            ->whereIn('id', $rfq->invitations->pluck('vendor_id')->filter()->unique()->values())
            ->orderBy('id')
            ->get(['id'])
            ->map(static fn (Vendor $vendor): string => (string) $vendor->hash_id)
            ->all();
        $audiences = array_chunk($supplierVendorHashIds, self::MAX_VENDOR_CHANNELS_PER_EVENT);
        if ($audiences === []) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        foreach ($audiences as $audience) {
            $supplierEvent = new SupplierRfqChanged(
                rfqHashId: (string) $rfq->hash_id,
                action: $action,
                version: $rfq->updated_at?->toISOString() ?? now()->toISOString(),
                supplierVendorHashIds: $audience,
            );

            $this->outbox->recordCoalesced(
                $supplierEvent,
                'supplier-rfq-refresh:'.$rfq->getKey().':'.$transactionId.':'.hash('sha256', implode(',', $audience)),
            );
        }
    }
}
