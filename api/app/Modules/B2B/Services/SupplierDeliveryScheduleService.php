<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Supplier-submitted delivery schedules (monthly delivery plans per PO).
 * Purchasing reviews them through DeliveryScheduleReviewService.
 */
class SupplierDeliveryScheduleService
{
    private const PO_WITH_LINES = [
        'purchaseOrder:id,po_number',
        'purchaseOrder.items:id,purchase_order_id,description',
    ];

    public function __construct(
        private readonly SupplierPortalAuditRecorder $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function deliverySchedules(int $vendorId, array $filters = []): LengthAwarePaginator
    {
        $query = DeliverySchedule::where('vendor_id', $vendorId)->with(self::PO_WITH_LINES);

        if (! empty($filters['status'])) {
            $status = DeliveryScheduleStatus::tryFrom((string) $filters['status']);
            $status === null ? $query->whereRaw('1 = 0') : $query->where('status', $status->value);
        }
        if (! empty($filters['purchase_order_id'])) {
            $poId = HashIdFilter::decode((string) $filters['purchase_order_id'], PurchaseOrder::class);
            $poId === null ? $query->whereRaw('1 = 0') : $query->where('purchase_order_id', $poId);
        }

        return $query->orderByDesc('month')
            ->orderByDesc('created_at')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)));
    }

    /**
     * The supplier's POs that can take a new schedule, with per-line
     * schedulable quantities. Replaces the SPA's capped PO list + per-PO fetch.
     *
     * @return list<array<string, mixed>>
     */
    public function schedulablePurchaseOrders(int $vendorId): array
    {
        return PurchaseOrder::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('status', array_map(static fn ($s): string => $s->value, SupplierPoCapabilities::FULFILMENT_STATUSES))
            ->whereHas('items', static fn ($items) => $items->whereColumn('quantity', '>', 'quantity_received'))
            ->with('items.item:id,code,name')
            ->orderBy('po_number')
            ->get()
            ->map(static function (PurchaseOrder $po): array {
                $open = SupplierPoCapabilities::openScheduledQuantities($po);
                $schedulable = SupplierPoCapabilities::schedulableQuantities($po);

                return [
                    'id' => $po->hash_id,
                    'po_number' => $po->po_number,
                    'items' => $po->items->map(static fn (PurchaseOrderItem $item): array => [
                        'id' => $item->hash_id,
                        'part_number' => $item->item?->code ?? '—',
                        'name' => $item->item?->name ?? $item->description,
                        'quantity_ordered' => (string) $item->quantity,
                        'quantity_received' => (string) $item->quantity_received,
                        'quantity_scheduled_open' => Money::round2($open[(int) $item->id] ?? '0'),
                        'quantity_schedulable' => $schedulable[(int) $item->id] ?? '0.00',
                    ])->values()->all(),
                ];
            })
            ->filter(static fn (array $po): bool => collect($po['items'])->contains(
                static fn (array $item): bool => Money::gt($item['quantity_schedulable'], '0'),
            ))
            ->values()
            ->all();
    }

    public function storeDeliverySchedule(int $vendorId, int $portalUserId, array $data): DeliverySchedule
    {
        $decodedPoId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class);

        return DB::transaction(function () use ($vendorId, $portalUserId, $decodedPoId, $data): DeliverySchedule {
            // The PO row lock serializes schedules for one PO, so two tabs
            // cannot both claim the same remaining quantity.
            $po = PurchaseOrder::query()
                ->whereKey($decodedPoId)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->firstOrFail();
            SupplierPoCapabilities::assert($po, 'schedule');

            $normalizedLines = $this->normalizeScheduleLines($po, $data['lines']);

            // A double-submit of the same plan returns the first row instead of
            // claiming the quantity twice — checked before the quantity cap,
            // which the first submission has already consumed. Anything else is
            // a new plan: one PO may carry several schedules in a month (item A
            // now, item B later); the old one-row-per-month rule blocked that.
            $duplicate = DeliverySchedule::query()
                ->where('vendor_id', $vendorId)
                ->where('purchase_order_id', $po->id)
                ->where('month', $data['month'])
                ->where('status', DeliveryScheduleStatus::Submitted->value)
                ->get()
                ->first(static fn (DeliverySchedule $existing): bool => $existing->lines === $normalizedLines);
            if ($duplicate) {
                return $duplicate->load(self::PO_WITH_LINES);
            }
            $this->assertWithinSchedulable($po, $normalizedLines);

            $schedule = DeliverySchedule::create([
                'vendor_id' => $vendorId,
                'purchase_order_id' => $po->id,
                'month' => $data['month'],
                'status' => DeliveryScheduleStatus::Submitted->value,
                'lines' => $normalizedLines,
            ]);
            $this->audit->record('supplier_sched.sub', $schedule, $portalUserId, $vendorId);

            return $schedule->load(self::PO_WITH_LINES);
        });
    }

    public function cancel(DeliverySchedule $schedule, int $vendorId, int $portalUserId, string $reason): DeliverySchedule
    {
        [$cancelled, $wasAcknowledged] = DB::transaction(function () use ($schedule, $vendorId, $portalUserId, $reason): array {
            // Another vendor's schedule is a 404, not a 403: do not confirm it exists.
            $locked = DeliverySchedule::query()
                ->whereKey($schedule->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, SupplierPoCapabilities::OPEN_SCHEDULE_STATUSES, true)) {
                throw new BusinessRuleException('Only submitted or acknowledged schedules can be cancelled.');
            }
            $wasAcknowledged = $locked->status === DeliveryScheduleStatus::Acknowledged;

            $locked->forceFill([
                'status' => DeliveryScheduleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by_portal_user_id' => $portalUserId,
            ])->save();
            $this->audit->record('supplier_sched.cancel', $locked, $portalUserId, $vendorId);

            return [$locked->load(self::PO_WITH_LINES), $wasAcknowledged];
        });

        // Purchasing planned against an acknowledged schedule; a submitted one
        // was never relied on, so withdrawing it needs no alert.
        if ($wasAcknowledged) {
            $this->notifyPurchasingOfCancellation($cancelled);
        }

        return $cancelled;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function normalizeScheduleLines(PurchaseOrder $purchaseOrder, array $lines): array
    {
        $items = $purchaseOrder->items()->with('item:id,name')->get()->keyBy('id');
        $seen = [];
        $normalized = [];

        foreach ($lines as $line) {
            $itemId = HashIdFilter::decode((string) ($line['purchase_order_item_id'] ?? ''), PurchaseOrderItem::class);
            $item = $itemId === null ? null : $items->get($itemId);
            if (! $item || isset($seen[$item->id])) {
                throw new BusinessRuleException('Each delivery schedule line must identify a unique item on the purchase order.');
            }

            $quantity = (string) $line['quantity'];
            if (Money::lte($quantity, '0')) {
                throw new BusinessRuleException("Scheduled quantity for {$item->description} must be greater than zero.");
            }

            $seen[$item->id] = true;
            $normalized[] = [
                'purchase_order_item_id' => $item->hash_id,
                'product_name' => $item->item?->name ?? $item->description,
                'quantity' => Money::round2($quantity),
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $normalized;
    }

    /** @param list<array{purchase_order_item_id: string, product_name: string, quantity: string}> $lines */
    private function assertWithinSchedulable(PurchaseOrder $po, array $lines): void
    {
        $schedulable = SupplierPoCapabilities::schedulableQuantities($po);
        foreach ($lines as $line) {
            $itemId = HashIdFilter::decode($line['purchase_order_item_id'], PurchaseOrderItem::class);
            $available = $schedulable[(int) $itemId] ?? '0.00';
            if (Money::gt($line['quantity'], $available)) {
                throw new BusinessRuleException("Scheduled quantity for {$line['product_name']} exceeds what is still schedulable ({$available}).");
            }
        }
    }

    private function notifyPurchasingOfCancellation(DeliverySchedule $schedule): void
    {
        // Same audience as a supplier PO response (SupplierResponseService).
        $audience = User::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', static fn ($q) => $q->where('slug', 'purchasing.po.approve'))
            ->get();
        $poNumber = $schedule->purchaseOrder?->po_number ?? '—';

        $this->notifications->send($audience, 'supplier.schedule_cancelled', [
            'title' => "PO {$poNumber} — supplier cancelled the {$schedule->month} delivery schedule",
            'message' => "Reason: {$schedule->cancel_reason}",
            'link_to' => $schedule->purchaseOrder ? "/purchasing/purchase-orders/{$schedule->purchaseOrder->hash_id}" : null,
            'entity_type' => 'purchase_order',
            'entity_id' => $schedule->purchaseOrder?->hash_id,
            'po_number' => $poNumber,
        ]);
    }
}
