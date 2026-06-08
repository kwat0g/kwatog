<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Support\SearchOperator;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\CoCService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly CoCService $coc,
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
        $d = $d->load([
            'salesOrder:id,so_number,customer_id',
            'vehicle:id,plate_number,name,vehicle_type',
            'driver:id,name,role_id',
            'confirmer:id,name,role_id',
            'creator:id,name,role_id',
            'invoice:id,invoice_number,total_amount,status',
            'items.salesOrderItem:id,sales_order_id,product_id,quantity,unit_price',
            'items.inspection:id,inspection_number,stage,status',
            // ADV3 — surface the shipment lot for the detail page.
            'shipmentLot.product:id,part_number,name',
            'shipmentLot.customer:id,name',
            // ADV7 — Proof of Delivery files for the detail page.
            'proofs' => fn ($q) => $q->orderByDesc('created_at'),
            'proofs.uploader:id,name',
        ]);
        return $d;
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

        // Series C — Task C4. Real-time chain progress for the delivery
        // detail page on the SPA.
        app(\App\Common\Services\ChainBroadcaster::class)
            ->broadcastFor($d->fresh(), $next->value, auth()->user());

        return $this->show($d);
    }

    /**
     * Quick receipt photo upload — sets the legacy receipt_photo_path AND
     * registers a DeliveryProof row so it counts toward the ADV7 proof
     * requirement for confirmation.
     */
    public function uploadReceiptPhoto(Delivery $d, UploadedFile $file, ?User $by = null): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if (! in_array($current, [DeliveryStatus::Delivered, DeliveryStatus::Confirmed], true)) {
            throw new RuntimeException('Receipt photo can only be uploaded after delivery is marked delivered.');
        }

        // P3.2 — Store the file BEFORE opening the transaction so that a DB
        // rollback cannot orphan a file that was written inside the transaction.
        // If the transaction fails we delete the file and re-throw.
        $path = $file->store("deliveries/{$d->id}", 'local');

        try {
            return DB::transaction(function () use ($d, $file, $path, $by) {
                $d->forceFill(['receipt_photo_path' => $path])->save();

                // ADV7 — also register the legacy upload as a DeliveryProof so the
                // confirmation guard sees it. Falls back to the delivery creator
                // if no user is supplied.
                \App\Modules\SupplyChain\Models\DeliveryProof::create([
                    'delivery_id' => $d->id,
                    'proof_type'  => 'photo',
                    'file_name'   => $file->getClientOriginalName() ?: basename($path),
                    'file_path'   => $path,
                    'file_size'   => $file->getSize() ?: null,
                    'mime_type'   => $file->getMimeType(),
                    'uploaded_by' => $by?->id ?? $d->created_by,
                    'notes'       => 'Quick receipt photo',
                ]);

                return $this->show($d);
            });
        } catch (\Throwable $e) {
            // Clean up the already-stored file so we don't leave orphans.
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * CRM officer confirms delivery → auto-create draft invoice for the SO.
     * Idempotent: if an invoice is already linked, returns it untouched.
     *
     * ADV7 — Proof of Delivery is mandatory: the delivery must have at least
     * one proof file uploaded before it can be confirmed. Optional receiver
     * capture fields (receiver_name, receiver_position, delivery_remarks) may
     * be supplied here to stamp the delivery in a single round-trip.
     *
     * @param array{
     *   receiver_name?: string|null,
     *   receiver_position?: string|null,
     *   delivery_remarks?: string|null,
     * } $receiverData
     */
    public function confirm(Delivery $d, User $by, array $receiverData = []): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current !== DeliveryStatus::Delivered && $current !== DeliveryStatus::Confirmed) {
            throw new RuntimeException('Only delivered deliveries can be confirmed.');
        }

        return DB::transaction(function () use ($d, $by, $receiverData) {
            // P3.1 — Re-read the delivery under an exclusive row lock so that
            // two concurrent confirm() calls cannot both pass the status check
            // and both write a confirmed state / draft invoice.
            $locked = Delivery::whereKey($d->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new RuntimeException('Delivery not found.');
            }

            $lockedStatus = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);

            // Already confirmed by a concurrent request — no-op.
            if ($lockedStatus === DeliveryStatus::Confirmed) {
                return $this->show($locked);
            }

            if ($lockedStatus !== DeliveryStatus::Delivered) {
                throw new RuntimeException('Only delivered deliveries can be confirmed.');
            }

            // ADV7 — Block confirmation without proof. This is the legally
            // defensible record for any future customer dispute.
            if ($locked->proofs()->count() === 0) {
                throw new RuntimeException('At least one proof of delivery (signed DR or photo) must be uploaded before confirming.');
            }

            $patch = [
                'status'       => DeliveryStatus::Confirmed->value,
                'confirmed_at' => now(),
                'confirmed_by' => $by->id,
            ];
            if (! empty($receiverData['receiver_name']))     $patch['receiver_name']     = $receiverData['receiver_name'];
            if (! empty($receiverData['receiver_position'])) $patch['receiver_position'] = $receiverData['receiver_position'];
            if (! empty($receiverData['delivery_remarks']))  $patch['delivery_remarks']  = $receiverData['delivery_remarks'];
            if (! $locked->received_at) $patch['received_at'] = now();
            $locked->forceFill($patch)->save();
            // Keep $d in sync with the locked copy so callers see the new state.
            $d->forceFill($patch);

            // M-20 — Auto-attach CoC for each passed outgoing inspection linked
            // to this delivery. Best-effort; never blocks confirm.
            try {
                $this->attachCertificatesOfConformance($locked, $by);
            } catch (\Throwable $e) {
                Log::warning('CoC auto-attach failed on delivery confirm', [
                    'delivery_id' => $locked->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            // C-2 — Promote the parent SO based on delivered coverage. The
            // locked delivery has just been status-flipped inside this txn,
            // so Postgres MVCC sees the in-txn write when we aggregate below.
            if ($locked->sales_order_id) {
                $coverage = $this->computeSalesOrderDeliveryCoverage((int) $locked->sales_order_id);
                $soService = app(\App\Modules\CRM\Services\SalesOrderService::class);
                if ($coverage === 'full') {
                    $soService->markDelivered((int) $locked->sales_order_id);
                } elseif ($coverage === 'partial') {
                    $soService->markPartiallyDelivered((int) $locked->sales_order_id);
                }
            }

            // Auto-create draft invoice (best-effort — Accounting may be disabled).
            $invoiceId = null;
            try {
                $invoiceId = $this->createDraftInvoice($locked, $by);
                if ($invoiceId) {
                    $locked->forceFill(['invoice_id' => $invoiceId])->save();
                }
            } catch (\Throwable $e) {
                // Draft-invoice creation is best-effort (Accounting may be disabled
                // or misconfigured) — never block the delivery confirm. Log so the
                // failure is visible and manual invoicing can be triaged.
                Log::error('Draft invoice creation failed on delivery confirm', [
                    'delivery_id' => $locked->id,
                    'error'       => $e->getMessage(),
                ]);

                // C-1 — surface the failure to AR clerks so manual invoicing can
                // be triaged. Defer past commit so the row lock is released first
                // and a slow notification path can't extend lock hold time.
                $deliveryForNotify = $locked;
                DB::afterCommit(function () use ($deliveryForNotify) {
                    try {
                        $this->notifyAutoInvoiceFailure($deliveryForNotify);
                    } catch (\Throwable $notifyError) {
                        Log::warning('Auto-invoice failure notification dispatch failed', [
                            'delivery_id' => $deliveryForNotify->id,
                            'error'       => $notifyError->getMessage(),
                        ]);
                    }
                });
            }

            // Task A4 — fan out a DeliveryConfirmed event after commit so
            // listeners (Finance notification, dashboard refresh) only see
            // the persisted state.
            $delivery = $this->show($locked);
            DB::afterCommit(function () use ($delivery, $invoiceId, $by) {
                DeliveryConfirmed::dispatch($delivery, $invoiceId);
                // Series C — Task C4. Real-time chain progress.
                app(\App\Common\Services\ChainBroadcaster::class)
                    ->broadcastFor($delivery, DeliveryStatus::Confirmed->value, $by);
            });

            return $delivery;
        });
    }

    /**
     * M-20 — Auto-attach a CoC for each passed outgoing Inspection referenced
     * by this delivery's items. Idempotent: skips inspections that already
     * have a CoC attached to this delivery.
     */
    private function attachCertificatesOfConformance(Delivery $delivery, User $by): void
    {
        $delivery->loadMissing('items');

        $inspectionIds = $delivery->items
            ->pluck('inspection_id')
            ->filter()
            ->unique()
            ->values();

        if ($inspectionIds->isEmpty()) {
            return;
        }

        $inspections = Inspection::query()
            ->whereIn('id', $inspectionIds->all())
            ->get()
            ->keyBy('id');

        foreach ($inspectionIds as $inspectionId) {
            $inspection = $inspections->get($inspectionId);
            if (! $inspection) {
                continue;
            }

            $stage = $inspection->stage instanceof InspectionStage
                ? $inspection->stage
                : InspectionStage::from((string) $inspection->stage);
            $status = $inspection->status instanceof InspectionStatus
                ? $inspection->status
                : InspectionStatus::from((string) $inspection->status);

            if ($stage !== InspectionStage::Outgoing || $status !== InspectionStatus::Passed) {
                continue;
            }

            $built = $this->coc->buildBinaryForInspection($inspection, $delivery->delivery_number);
            $cocNumber = $built['coc_number'];

            // Idempotency — file_name is deterministic from coc_number.
            $alreadyAttached = DeliveryProof::query()
                ->where('delivery_id', $delivery->id)
                ->where('proof_type', 'coc')
                ->where('file_name', 'LIKE', "CoC-{$cocNumber}%")
                ->exists();
            if ($alreadyAttached) {
                continue;
            }

            $path = "deliveries/{$delivery->id}/proofs/coc-{$cocNumber}.pdf";
            Storage::disk('local')->put($path, $built['contents']);

            DeliveryProof::create([
                'delivery_id' => $delivery->id,
                'proof_type'  => 'coc',
                'file_name'   => $built['file_name'],
                'file_path'   => $path,
                'file_size'   => strlen($built['contents']),
                'mime_type'   => 'application/pdf',
                'uploaded_by' => $by->id,
                'notes'       => "Auto-generated from inspection #{$inspection->inspection_number}",
            ]);
        }
    }

    private function createDraftInvoice(Delivery $d, User $by): ?int
    {
        $svc = app(\App\Modules\Accounting\Services\InvoiceService::class);
        $d->loadMissing(['salesOrder.customer', 'items.salesOrderItem.product']);
        if (! $d->salesOrder?->customer) return null;

        // C-1 — Resolve the default revenue account once per call. Falls back
        // to '4010' (Sales Revenue) if the setting was never seeded.
        $defaultCode      = (string) $this->settings->get('accounting.default_sales_revenue_account_code', '4010');
        $defaultAccountId = $defaultCode === ''
            ? null
            : Account::query()->where('code', $defaultCode)->value('id');

        $customerHashId = app('hashids')->encode($d->salesOrder->customer_id);
        $hashids        = app('hashids');

        $items = $d->items->map(function (DeliveryItem $i) use ($defaultAccountId, $hashids) {
            $revenueId = $i->salesOrderItem?->product?->revenue_account_id
                ?? $defaultAccountId;

            if (! $revenueId) {
                throw new RuntimeException('Default revenue account not configured.');
            }

            return [
                'revenue_account_id' => $hashids->encode((int) $revenueId),
                'description'        => $i->salesOrderItem?->product?->name ?? 'Delivery line',
                'quantity'           => (string) $i->quantity,
                'unit_price'         => (string) $i->unit_price,
            ];
        })->all();

        $invoice = $svc->create([
            'customer_id'    => $customerHashId,
            'date'           => now()->toDateString(),
            'is_vatable'     => true,
            'items'          => $items,
            'remarks'        => "Auto-generated from delivery {$d->delivery_number}",
            // C-2 — link the invoice back to the parent SO + this delivery so
            // InvoiceService::finalize can promote the SO to 'invoiced'.
            'sales_order_id' => $d->sales_order_id ? app('hashids')->encode((int) $d->sales_order_id) : null,
            'delivery_id'    => app('hashids')->encode((int) $d->id),
        ], $by);

        return (int) $invoice->id;
    }

    /**
     * C-1 — Notify AR clerks (anyone with accounting.invoices.create) that an
     * auto-invoice attempt failed so they can triage manual invoicing. The
     * exception detail is in Log::error already; the user-visible body is a
     * generic, non-leaky message.
     */
    private function notifyAutoInvoiceFailure(Delivery $d): void
    {
        $recipients = User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'accounting.invoices.create'))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->send($recipients, 'invoice.auto_failed', [
            'title'       => "Auto-invoice failed for delivery {$d->delivery_number}",
            'message'     => 'Auto-invoice could not be created automatically. Please create the invoice manually.',
            'link_to'     => "/supply-chain/deliveries/{$d->hash_id}",
            'entity_type' => 'delivery',
            'entity_id'   => $d->hash_id,
        ]);
    }

    public function delete(Delivery $d): void
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current === DeliveryStatus::Confirmed) {
            throw new RuntimeException('Cannot delete a confirmed delivery (an invoice may be attached).');
        }
        DB::transaction(function () use ($d) {
            if ($d->receipt_photo_path) Storage::disk('local')->delete($d->receipt_photo_path);
            $d->delete();
        });
    }

    /**
     * C-2 — Compute whether an SO is fully, partially, or not-yet covered by
     * confirmed (or about-to-be-confirmed) deliveries.
     *
     * Returns 'full' | 'partial' | 'none'.
     *
     * The currently-locked delivery is included because we run mid-transaction
     * after its status has been flipped to Confirmed but before commit.
     */
    private function computeSalesOrderDeliveryCoverage(int $salesOrderId): string
    {
        $deliveredByItem = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->where('d.sales_order_id', $salesOrderId)
            ->whereIn('d.status', [
                DeliveryStatus::Confirmed->value,
                DeliveryStatus::Delivered->value,
            ])
            ->selectRaw('di.sales_order_item_id, SUM(di.quantity) AS qty')
            ->groupBy('di.sales_order_item_id')
            ->pluck('qty', 'sales_order_item_id');

        $orderedByItem = DB::table('sales_order_items')
            ->where('sales_order_id', $salesOrderId)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'id');

        if ($orderedByItem->isEmpty()) {
            return 'none';
        }

        $allCovered = true;
        $anyCovered = false;
        foreach ($orderedByItem as $itemId => $orderedQty) {
            $deliveredQty = (string) ($deliveredByItem[$itemId] ?? 0);
            if (bccomp($deliveredQty, '0', 4) > 0) {
                $anyCovered = true;
            }
            if (bccomp($deliveredQty, (string) $orderedQty, 4) < 0) {
                $allCovered = false;
            }
        }

        return $allCovered ? 'full' : ($anyCovered ? 'partial' : 'none');
    }
}
