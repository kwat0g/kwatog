<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Events\SupplierCatalogChanged;
use Illuminate\Support\Str;

/** Single durable staging seam for supplier catalog refreshes. */
final class SupplierCatalogRealtimeService
{
    public function __construct(private readonly OutboxService $outbox) {}

    public function changed(string $action, ?int $vendorId = null): void
    {
        $event = new SupplierCatalogChanged(
            action: $action,
            version: now()->toISOString(),
            vendorHashId: $vendorId === null ? null : Vendor::query()->find($vendorId)?->hash_id,
            eventId: (string) Str::uuid(),
        );

        $this->outbox->record($event, 'supplier-catalog-refresh:'.$event->eventId);
    }

    /** @param list<int> $vendorIds */
    public function changedForVendors(string $action, array $vendorIds): void
    {
        $vendorHashIds = Vendor::query()
            ->whereIn('id', array_values(array_unique($vendorIds)))
            ->get()
            ->map(fn (Vendor $vendor): string => (string) $vendor->hash_id)
            ->all();

        $event = new SupplierCatalogChanged(
            action: $action,
            version: now()->toISOString(),
            vendorHashId: $vendorHashIds,
            eventId: (string) Str::uuid(),
        );

        $this->outbox->record($event, 'supplier-catalog-refresh:'.$event->eventId);
    }
}
