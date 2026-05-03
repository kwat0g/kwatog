<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Support\SearchOperator;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sprint 7 — Task 66. Outbound delivery lifecycle.
 *
 *   create()              — opens delivery only for items that passed outgoing QC
 *   updateStatus()        — enforces forward-only transitions, stamps timestamps
 *   uploadReceiptPhoto()  — stores driver's receipt photo on `delivered`
 *   confirm()             — CRM officer marks confirmed; auto-creates draft invoice
 */
class DeliveryService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Delivery::query()->with([
            'salesOrder:id,so_number,customer_id',
            'vehicle:id,plate_number,name',
            'driver:id,name,role_id',
        ]);

        foreach (['status'] as $f) if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        if (! empty($filters['sales_order_id'])) $q->where('sales_order_id', (int) $filters['sales_order_id']);
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b->where('delivery_number', SearchOperator::like(), $term));
        }
        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(Delivery $d): Delivery
    {
        return $d->load([
            'salesOrder:id,so_number,customer_id',
            'vehicle:id,plate_number,name,vehicle_type',
            'driver:id,name,role_id',
            'confirmer:id,name,role_id',
            'creator:id,name,role_id',
            'invoice:id,invoice_number,total_amount,status',
            'items.salesOrderItem:id,sales_order_id,product_id,quantity,unit_price',
            'items.inspection:id,inspection_number,stage,status',
        ]);
    }

    /**
     * Create a delivery for selected SO items, all of which must have a
     * passed outgoing-QC inspection in our books.
     *
     * @param array{
     *   sales_order_id: int,
     *   vehicle_id?: int|null,
     *   driver_id?: int|null,
     *   scheduled_date: string,
     *   notes?: string|null,
     *   items: array<int, array{
     *     sales_order_item_id: int,
     *     quantity: float|string,
     *     inspection_id?: int|null
     *   }>
     * } $data
     */
    public function create(array $data, User $by): Delivery
    {
        $so = SalesOrder::query()->findOrFail((int) $data['sales_order_id']);
        if (empty($data['items'])) {
            throw new RuntimeException('At least one delivery item is required.');
        }

        return DB::transaction(function () use ($so, $data, $by) {
            $delivery = Delivery::create([
                'delivery_number' => $this->sequences->generate('delivery'),
                'sales_order_id'  => $so->id,
                'vehicle_id'      => $data['vehicle_id'] ?? null,
                'driver_id'       => $data['driver_id'] ?? null,
                'status'          => DeliveryStatus::Scheduled->value,
                'scheduled_date'  => $data['scheduled_date'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $by->id,
            ]);

            foreach ($data['items'] as $row) {
                $soItem = SalesOrderItem::query()
                    ->where('id', (int) $row['sales_order_item_id'])
                    ->where('sales_order_id', $so->id)
                    ->firstOrFail();

                $inspectionId = $this->resolveAndValidateInspection(
                    productId: (int) $soItem->product_id,
                    suppliedInspectionId: $row['inspection_id'] ?? null,
                );

                DeliveryItem::create([
                    'delivery_id'         => $delivery->id,
                    'sales_order_item_id' => $soItem->id,
                    'inspection_id'       => $inspectionId,
                    'quantity'            => (string) $row['quantity'],
                    'unit_price'          => (string) $soItem->unit_price,
                ]);
            }

            return $this->show($delivery);
        });
    }

    /**
     * For each delivery line we need a passed outgoing inspection on the
     * matching product. If the caller supplied one, validate it; otherwise
     * pick the most-recent passed outgoing inspection.
     */
    private function resolveAndValidateInspection(int $productId, ?int $suppliedInspectionId): ?int
    {
        if ($suppliedInspectionId) {
            $insp = Inspection::find($suppliedInspectionId);
            if (! $insp
                || $insp->product_id !== $productId
                || ($insp->stage instanceof InspectionStage ? $insp->stage : InspectionStage::from((string) $insp->stage)) !== InspectionStage::Outgoing
                || ($insp->status instanceof InspectionStatus ? $insp->status : InspectionStatus::from((string) $insp->status)) !== InspectionStatus::Passed) {
                throw new RuntimeException("Supplied inspection #{$suppliedInspectionId} is not a passed outgoing inspection for the product.");
            }
            return (int) $insp->id;
        }

        $latest = Inspection::query()
            ->where('product_id', $productId)
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('status', InspectionStatus::Passed->value)
            ->orderByDesc('completed_at')
            ->first();

        if (! $latest) {
            throw new RuntimeException("Cannot ship — no passed outgoing inspection found for product #{$productId}.");
        }
        return (int) $latest->id;
    }

    public function updateStatus(Delivery $d, DeliveryStatus $next, ?string $note = null): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if (! $current->canTransitionTo($next)) {
            throw new RuntimeException("Cannot transition delivery {$d->delivery_number} from {$current->value} to {$next->value}.");
        }
        $patch = ['status' => $next->value];
        $now = now();
        if ($next === DeliveryStatus::InTransit && ! $d->departed_at)  $patch['departed_at']  = $now;
        if ($next === DeliveryStatus::Delivered && ! $d->delivered_at) $patch['delivered_at'] = $now;
        if ($note) $patch['notes'] = trim(($d->notes ? $d->notes."\n" : '').'['.$next->value.'] '.$note);
        $d->forceFill($patch)->save();

        // Mark the vehicle in-use / available based on transition.
        if ($d->vehicle_id) {
            $vehicleStatus = match ($next) {
                DeliveryStatus::Loading, DeliveryStatus::InTransit => 'in_use',
                DeliveryStatus::Delivered, DeliveryStatus::Confirmed, DeliveryStatus::Cancelled => 'available',
                default => null,
            };
            if ($vehicleStatus) {
                Vehicle::where('id', $d->vehicle_id)->update(['status' => $vehicleStatus]);
            }
        }

        return $this->show($d);
    }

    public function uploadReceiptPhoto(Delivery $d, UploadedFile $file): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if (! in_array($current, [DeliveryStatus::Delivered, DeliveryStatus::Confirmed], true)) {
            throw new RuntimeException('Receipt photo can only be uploaded after delivery is marked delivered.');
        }
        $path = $file->store("deliveries/{$d->id}", 'public');
        $d->forceFill(['receipt_photo_path' => $path])->save();
        return $this->show($d);
    }

    /**
     * CRM officer confirms delivery → auto-create draft invoice for the SO.
     * Idempotent: if an invoice is already linked, returns it untouched.
     */
    public function confirm(Delivery $d, User $by): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current === DeliveryStatus::Confirmed) return $this->show($d);
        if ($current !== DeliveryStatus::Delivered) {
            throw new RuntimeException('Only delivered deliveries can be confirmed.');
        }

        return DB::transaction(function () use ($d, $by) {
            $d->forceFill([
                'status'       => DeliveryStatus::Confirmed->value,
                'confirmed_at' => now(),
                'confirmed_by' => $by->id,
            ])->save();

            // Auto-create draft invoice (best-effort — Accounting may be disabled).
            try {
                $invoiceId = $this->createDraftInvoice($d, $by);
                if ($invoiceId) {
                    $d->forceFill(['invoice_id' => $invoiceId])->save();
                }
            } catch (\Throwable) {
                // Skip silently — manual invoicing remains possible.
            }

            return $this->show($d);
        });
    }

    private function createDraftInvoice(Delivery $d, User $by): ?int
    {
        $svc = app(\App\Modules\Accounting\Services\InvoiceService::class);
        $d->loadMissing(['salesOrder.customer', 'items.salesOrderItem.product']);
        if (! $d->salesOrder?->customer) return null;

        $customerHashId = app('hashids')->encode($d->salesOrder->customer_id);
        $items = $d->items->map(fn (DeliveryItem $i) => [
            'description' => $i->salesOrderItem?->product?->name ?? 'Delivery line',
            'quantity'    => (string) $i->quantity,
            'unit_price'  => (string) $i->unit_price,
            'amount'      => bcmul((string) $i->quantity, (string) $i->unit_price, 2),
        ])->all();

        $invoice = $svc->create([
            'customer_id' => $customerHashId,
            'date'        => now()->toDateString(),
            'is_vatable'  => true,
            'items'       => $items,
            'remarks'     => "Auto-generated from delivery {$d->delivery_number}",
        ], $by);

        return (int) $invoice->id;
    }

    public function delete(Delivery $d): void
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current === DeliveryStatus::Confirmed) {
            throw new RuntimeException('Cannot delete a confirmed delivery (an invoice may be attached).');
        }
        DB::transaction(function () use ($d) {
            if ($d->receipt_photo_path) Storage::disk('public')->delete($d->receipt_photo_path);
            $d->delete();
        });
    }
}
