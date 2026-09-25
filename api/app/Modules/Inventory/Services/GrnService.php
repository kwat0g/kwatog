<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentLandedCost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use RuntimeException;

class GrnService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockMovementService $movements,
        private readonly GrnGlPostingService $gl,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = GoodsReceiptNote::query()
            ->with(['vendor:id,name', 'purchaseOrder:id,po_number', 'receiver:id,name,role_id']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vid) {
                $q->where('vendor_id', $vid);
            }
        }
        if (! empty($filters['purchase_order_id'])) {
            $pid = HashIdFilter::decode($filters['purchase_order_id'], PurchaseOrder::class);
            if ($pid) {
                $q->where('purchase_order_id', $pid);
            }
        }
        if (! empty($filters['from'])) {
            $q->whereDate('received_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('received_date', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $q->where('grn_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }

        return $q->orderByDesc('received_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(GoodsReceiptNote $grn): GoodsReceiptNote
    {
        return $grn->load([
            'vendor',
            // 2026-08-08 — compact P2P stepper: PR → PO → GRN → Bill → Paid.
            'purchaseOrder.purchaseRequest:id,pr_number',
            'shipment:id,shipment_number,status',
            // Receipt order. Unordered, Postgres returns an updated row last,
            // so lines swapped places on the page once QC settled one of them.
            'items' => fn ($items) => $items->orderBy('id'),
            'items.item' => fn ($item) => $item->select('id', 'code', 'name', 'unit_of_measure')
                ->withExists(['qualityPlans as has_active_quality_plan' => fn ($plan) => $plan->effective()]),
            'items.location.zone.warehouse',
            'items.purchaseOrderItem',
            'items.inspection',
            'qcInspection:id,inspection_number,status,stage',
            'journalEntry:id,entry_number,status',
            'receiver:id,name,role_id', 'acceptor:id,name,role_id', 'remainderRejectedBy:id,name,role_id',
            'bills:id,goods_receipt_note_id,bill_number,status,total_amount',
        ]);
    }

    /**
     * List purchase orders that can be received against.
     * Warehouse staff have no purchasing.view permission, so this is their
     * entrypoint to discover open POs without cross-module permissions.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function receivablePurchaseOrders(): Collection
    {
        return PurchaseOrder::query()
            ->whereIn('status', PurchaseOrderStatus::receivable())
            ->with('vendor:id,name')
            ->orderByDesc('po_number')
            ->limit(200)
            ->get();
    }

    /**
     * Show a single receivable purchase order with its items.
     * Throws BusinessRuleException if the PO is not in a receivable status.
     *
     * @throws BusinessRuleException
     */
    public function receivablePurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        if (! in_array($po->status, PurchaseOrderStatus::receivable(), true)) {
            throw new BusinessRuleException("Purchase order {$po->po_number} is not open for receiving.");
        }

        return $po->loadMissing([
            'vendor:id,name',
            'items.item:id,code,name,unit_of_measure',
        ]);
    }

    /**
     * Create a GRN for a PO, in `pending_qc` status. Stock is NOT yet incremented.
     * Stock is increased only when accept() is called (Sprint 7 will gate this on QC).
     *
     * @param  array<int, array{purchase_order_item_id:int|string, item_id:int|string, location_id:int|string, quantity_received:string, unit_cost?:string|null, remarks?:string|null}>  $items
     */
    public function create(PurchaseOrder $po, array $items, array $meta, User $by): GoodsReceiptNote
    {
        $idempotencyKey = $this->normaliseIdempotencyKey($meta['idempotency_key'] ?? null);
        $fingerprint = $idempotencyKey === null
            ? null
            : (string) ($meta['idempotency_fingerprint'] ?? $this->fingerprint([
                'operation' => 'grn.create',
                'actor_id' => $by->id,
                'purchase_order_id' => $po->id,
                'items' => $items,
                'received_date' => $meta['received_date'] ?? null,
                'remarks' => $meta['remarks'] ?? null,
            ]));

        try {
            return DB::transaction(function () use ($po, $items, $meta, $by, $idempotencyKey, $fingerprint) {
                $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
                if ($idempotencyKey !== null) {
                    $existing = GoodsReceiptNote::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $this->replayIdempotentGrn($existing, (string) $fingerprint);
                    }
                }
                if (! in_array($po->status, PurchaseOrderStatus::receivable(), true)) {
                    throw new BusinessRuleException("PO {$po->po_number} is not open for receiving (status={$po->status->value}).");
                }

                $header = [
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'received_date' => $meta['received_date'] ?? now()->toDateString(),
                'received_by' => $by->id,
                'status' => GrnStatus::PendingQc,
                'incoming_qc_handoff_status' => IncomingQcHandoffStatus::NotStarted,
                'incoming_qc_handoff_at' => now(),
                'remarks' => $meta['remarks'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $fingerprint,
            ];

            // Sending the PO (or a shipment arriving) stages an expected-receipt
            // draft. Receiving against the PO completes that draft rather than
            // opening a second GRN beside it — the draft was otherwise stranded
            // at zero quantity with its own number, and once the PO was fully
            // received it could never be finalized or cleared. Its zero-qty
            // placeholder lines are replaced by the lines actually received.
            $draft = GoodsReceiptNote::query()
                ->where('purchase_order_id', $po->id)
                ->where('status', GrnStatus::Draft->value)
                ->lockForUpdate()
                ->first();
            if ($draft) {
                $draft->items()->each(fn (GrnItem $line) => $line->delete());
                $draft->forceFill($header)->save();
                $grn = $draft;
            } else {
                $grn = GoodsReceiptNote::create(['grn_number' => $this->sequences->generate('grn')] + $header);
            }

            foreach ($items as $row) {
                if (array_key_exists('coa_verified', $row)) {
                    throw new BusinessRuleException(
                        'COA verification is a Quality decision and cannot be set while receiving.'
                    );
                }

                $poiId = HashIdFilter::decode($row['purchase_order_item_id'], PurchaseOrderItem::class)
                    ?? (is_int($row['purchase_order_item_id']) ? $row['purchase_order_item_id'] : null);
                $poi = PurchaseOrderItem::query()->whereKey($poiId)->lockForUpdate()->firstOrFail();
                if ($poi->purchase_order_id !== $po->id) {
                    throw new BusinessRuleException("PO line {$poi->id} does not belong to PO {$po->id}.");
                }

                $locationId = HashIdFilter::decode($row['location_id'], WarehouseLocation::class)
                    ?? (int) $row['location_id'];
                $itemId = HashIdFilter::decode($row['item_id'], Item::class)
                    ?? (int) $row['item_id'];
                if ((int) $poi->item_id !== (int) $itemId) {
                    throw new BusinessRuleException(
                        "Received item {$itemId} does not match PO line {$poi->id} item {$poi->item_id}."
                    );
                }
                // The PO line is authoritative for the item that is received.
                // The caller-supplied item_id is retained only as an explicit
                // identity check, never as the downstream source of truth.
                $itemId = (int) $poi->item_id;
                $locationId = $this->resolveReceivingLocation($locationId);

                // One quantity/UOM/over-receipt contract is shared by direct and
                // staged-draft receiving so the paths cannot drift.
                $qtyReceived = $this->receivedBaseQuantity(
                    $poi,
                    (string) $row['quantity_received'],
                    $itemId,
                    $row['received_uom_code'] ?? null,
                );

                // Receipt cost is authoritative from the PO (including RFQ charges as landed cost);
                // price differences settle at billing. deliveredUnitCost() is per PO purchase unit;
                // GRN quantities and stock movements are per item base unit.
                // Load PO relationship for deliveredUnitCost() to compute header allocation.
                $poi->loadMissing('purchaseOrder.items');
                $unitCost = $poi->deliveredUnitCost();
                if ($unitCost === null || trim((string) $unitCost) === '') {
                    throw new BusinessRuleException("PO line {$poi->id} has no authoritative unit cost; receive pricing must be recorded first.");
                }

                // A client may echo either the bare PO price or the delivered cost it was shown.
                if (array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null
                    && bccomp((string) $row['unit_cost'], (string) $poi->unit_price, 4) !== 0
                    && bccomp((string) $row['unit_cost'], (string) $unitCost, 4) !== 0) {
                    throw new BusinessRuleException("PO line {$poi->id}: receipt cost is fixed at the PO price ({$poi->unit_price}). Price differences are settled on the supplier bill.");
                }
                $unitCost = $this->baseUnitCost($poi, $unitCost);

                GrnItem::create([
                    'goods_receipt_note_id' => $grn->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'received_uom_code' => $row['received_uom_code'] ?? null,
                    'quantity_received' => $qtyReceived,
                    'quantity_accepted' => 0,
                    'unit_cost' => $unitCost,
                    'remarks' => $row['remarks'] ?? null,
                    // OGAMI-012 — optional lot capture per received line. The
                    // existing ADV3 `material_lot_number` column is the lot of
                    // record; we also persist an optional expiry. Both null-safe.
                    'material_lot_number' => $row['lot_number'] ?? ($row['material_lot_number'] ?? null),
                    'supplier_lot_reference' => $row['supplier_lot_reference'] ?? null,
                    'expiry_date' => $row['expiry_date'] ?? null,
                    // OGAMI-005 — IATF incoming resin QC attributes (null-safe).
                    'moisture_percentage' => $row['moisture_percentage'] ?? null,
                    'coa_document_path' => $row['coa_document_path'] ?? null,
                    // COA verification is a Quality-owned decision. Receiving
                    // may capture the document reference, but cannot self-verify it.
                    'coa_verified' => false,
                ]);

                // Update PO line running total of received quantity (base uom).
                $poi->quantity_received = bcadd((string) $poi->quantity_received, $qtyReceived, 3);
                $poi->save();
            }

            $this->refreshPoStatus($po, $by);

            // F-06 — create incoming-QC inspections SYNCHRONOUSLY so the QC
            // gate can never fail open when the queue worker is down or the
            // Quality module boots late. The afterCommit event below is kept
            // for the async/retry path; TriggerIncomingQC is idempotent and
            // skips the records created here.
            try {
                app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($grn->fresh()));
            } catch (\Throwable $e) {
                $this->markIncomingQcHandoffPending(
                    $grn->id,
                    'Incoming QC trigger is waiting for queue replay: '.$e->getMessage(),
                );
                Log::warning('Synchronous incoming-QC trigger failed', [
                    'grn_id' => $grn->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Durable event publication is recorded with the GRN write. The
            // outbox dispatcher publishes after commit and safely replays the
            // idempotent incoming-QC listener if the queue is unavailable.
            $fresh = $grn->fresh();
            app(OutboxService::class)->recordForChain(
                new GoodsReceiptNoteCreated($fresh),
                $fresh,
                'p2p',
                'grn',
                'received',
            );

                return $this->show($fresh);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey === null
                || $e->getCode() !== '23505'
                || ! str_contains($e->getMessage(), 'goods_receipt_notes_idempotency_unique')) {
                throw $e;
            }

            $existing = GoodsReceiptNote::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return $this->replayIdempotentGrn($existing, (string) $fingerprint);
        }
    }

    private function normaliseIdempotencyKey(?string $key): ?string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $key)) {
            throw new BusinessRuleException('Idempotency-Key must contain only letters, numbers, dot, underscore, colon, or hyphen and be at most 128 characters.');
        }

        return $key;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function replayIdempotentGrn(GoodsReceiptNote $existing, string $fingerprint): GoodsReceiptNote
    {
        if (! hash_equals((string) $existing->idempotency_fingerprint, $fingerprint)) {
            throw new BusinessRuleException('The idempotency key was already used for a different GRN payload.');
        }

        return $this->show($existing);
    }

    /**
     * 2026-08-08 — Create a draft (expected) GRN for a PO that has been sent
     * to the supplier. Pre-fills one line per PO line at quantity_received = 0;
     * no location, no stock movement, no QC, no PO-line totals — the goods
     * have not arrived. The warehouse completes it via finalizeDraft().
     *
     * Idempotent: returns the existing draft GRN when one is already open for
     * this PO (a second send / stale event must not duplicate expectations).
     * Returns null when the PO already has a non-draft GRN (already received).
     */
    public function createDraftForPo(PurchaseOrder $po, ?User $by = null): ?GoodsReceiptNote
    {
        return DB::transaction(function () use ($po, $by) {
            // The PO is the serialization point for expected-receipt staging.
            // The old read-then-create sequence allowed two replayed
            // PurchaseOrderSent events to both observe no draft and create two
            // expectations. It also trusted the event's serialized status, so a
            // delayed sent event could stage a draft after cancellation.
            $lockedPo = PurchaseOrder::query()
                ->lockForUpdate()
                ->find($po->id);
            if (! $lockedPo || $lockedPo->status !== PurchaseOrderStatus::Sent) {
                return null;
            }

            $existingDraft = GoodsReceiptNote::query()
                ->where('purchase_order_id', $lockedPo->id)
                ->where('status', GrnStatus::Draft->value)
                ->first();
            if ($existingDraft) {
                return $existingDraft;
            }
            if (GoodsReceiptNote::query()
                ->where('purchase_order_id', $lockedPo->id)
                ->where('status', '!=', GrnStatus::Draft->value)
                ->exists()) {
                return null; // already received — no expectation needed
            }

            $grn = GoodsReceiptNote::create([
                'grn_number'         => $this->sequences->generate('grn'),
                'purchase_order_id'  => $lockedPo->id,
                'vendor_id'          => $lockedPo->vendor_id,
                'received_date'      => null,
                'received_by'        => $by?->id,
                'status'             => GrnStatus::Draft,
                'remarks'            => 'Expected receipt — auto-created when the PO was sent to the supplier.',
            ]);

            $lockedPo->load('items');
            foreach ($lockedPo->items as $line) {
                $line->setRelation('purchaseOrder', $lockedPo);
                GrnItem::create([
                    'goods_receipt_note_id'  => $grn->id,
                    'purchase_order_item_id' => $line->id,
                    'item_id'                => $line->item_id,
                    'location_id'            => null,
                    'quantity_received'      => '0',
                    'quantity_accepted'      => '0',
                    'unit_cost'              => $line->deliveredUnitCost(),
                ]);
            }

            return $this->show($grn->fresh());
        });
    }

    /**
     * Stage the receiving work item for a shipment that physically arrived.
     *
     * Shipment receipt is a logistics fact; it does not invent quantities or
     * warehouse bins. It links the shipment to an existing expected GRN, or
     * creates one when the PO is still receivable. Repeated calls return the
     * same GRN and never create a second receiving obligation.
     */
    public function stageForShipment(Shipment $shipment): GoodsReceiptNote
    {
        return DB::transaction(function () use ($shipment): GoodsReceiptNote {
            $lockedShipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);
            if ($lockedShipment->status !== ShipmentStatus::Received) {
                throw new BusinessRuleException('Only received shipments can be handed off to receiving.');
            }

            $linked = GoodsReceiptNote::query()
                ->where('shipment_id', $lockedShipment->id)
                ->lockForUpdate()
                ->first();
            if ($linked) {
                return $this->show($linked);
            }

            $po = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($lockedShipment->purchase_order_id);
            if (! in_array($po->status, PurchaseOrderStatus::receivable(), true)) {
                throw new BusinessRuleException(
                    "PO {$po->po_number} is not open for receiving (status={$po->status->value})."
                );
            }

            $grn = GoodsReceiptNote::query()
                ->where('purchase_order_id', $po->id)
                ->where('status', GrnStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($grn) {
                $grn->forceFill(['shipment_id' => $lockedShipment->id])->save();

                return $this->show($grn->fresh());
            }

            $grn = GoodsReceiptNote::create([
                'grn_number' => $this->sequences->generate('grn'),
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'shipment_id' => $lockedShipment->id,
                'received_date' => null,
                'received_by' => null,
                'status' => GrnStatus::Draft,
                'remarks' => 'Expected receipt — staged when shipment arrived.',
            ]);

            $po->load('items');
            foreach ($po->items as $line) {
                $line->setRelation('purchaseOrder', $po);
                GrnItem::create([
                    'goods_receipt_note_id' => $grn->id,
                    'purchase_order_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'location_id' => null,
                    'quantity_received' => '0',
                    'quantity_accepted' => '0',
                    'unit_cost' => $line->deliveredUnitCost(),
                ]);
            }

            return $this->show($grn->fresh());
        });
    }

    /**
     * 2026-08-08 — The warehouse completes a draft (expected) GRN: assigns a
     * bin + actual received quantity per line, then the GRN flips to
     * pending_qc and the normal flow takes over (incoming QC, stock on
     * accept). PO-line received totals and status are updated exactly like
     * create().
     *
     * @param  array<int, array{purchase_order_item_id:int|string, location_id:int|string, quantity_received:string, remarks?:string|null}>  $items
     */
    public function finalizeDraft(GoodsReceiptNote $grn, array $items, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::Draft) {
            throw new BusinessRuleException('Only draft GRNs can be finalized.');
        }

        return DB::transaction(function () use ($grn, $items, $by) {
            $lockedGrn = GoodsReceiptNote::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            if ($lockedGrn->status !== GrnStatus::Draft) {
                throw new BusinessRuleException('Only draft GRNs can be finalized.');
            }
            $po = PurchaseOrder::query()
                ->whereKey($lockedGrn->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($po->status, PurchaseOrderStatus::receivable(), true)) {
                throw new BusinessRuleException(
                    "Cannot finalize receiving for PO {$po->po_number}: PO is {$po->status->value}."
                );
            }
            $lockedGrn->loadMissing('items');
            $draftLineById = $lockedGrn->items->keyBy('purchase_order_item_id');

            foreach ($items as $row) {
                if (array_key_exists('coa_verified', $row)) {
                    throw new BusinessRuleException(
                        'COA verification is a Quality decision and cannot be set while receiving.'
                    );
                }

                $poiId = HashIdFilter::decode($row['purchase_order_item_id'], PurchaseOrderItem::class)
                    ?? (is_int($row['purchase_order_item_id']) ? $row['purchase_order_item_id'] : null);
                $poi = PurchaseOrderItem::query()->whereKey($poiId)->lockForUpdate()->firstOrFail();
                if ($poi->purchase_order_id !== $po->id) {
                    throw new BusinessRuleException("PO line {$poi->id} does not belong to PO {$po->id}.");
                }
                $draftLine = $draftLineById->get($poi->id);
                if (! $draftLine) {
                    throw new BusinessRuleException("PO line {$poi->id} has no draft GRN line.");
                }

                $locationId = $this->resolveReceivingLocation(
                    $row['location_id'],
                );
                $receivedUomCode = $row['received_uom_code'] ?? null;
                $qtyReceived = $this->receivedBaseQuantity(
                    $poi,
                    (string) $row['quantity_received'],
                    (int) $draftLine->item_id,
                    $receivedUomCode,
                );

                // Re-derive the base-unit delivered cost at finalize: the PO
                // price or its freight allocation may have moved since staging.
                $poi->setRelation('purchaseOrder', $po->loadMissing('items'));

                $draftLine->update([
                    'location_id'       => $locationId,
                    'received_uom_code' => $receivedUomCode,
                    'quantity_received' => $qtyReceived,
                    'quantity_accepted' => '0',
                    'unit_cost'         => $this->baseUnitCost($poi, $poi->deliveredUnitCost()),
                    'material_lot_number' => $row['lot_number'] ?? ($row['material_lot_number'] ?? null),
                    'supplier_lot_reference' => $row['supplier_lot_reference'] ?? null,
                    'expiry_date'       => $row['expiry_date'] ?? null,
                    'moisture_percentage' => $row['moisture_percentage'] ?? null,
                    'coa_document_path' => $row['coa_document_path'] ?? null,
                    // COA verification is a Quality-owned decision.
                    'coa_verified'      => false,
                    'remarks'           => $row['remarks'] ?? null,
                ]);

                $poi->quantity_received = bcadd((string) $poi->quantity_received, $qtyReceived, 3);
                $poi->save();
            }

            $this->refreshPoStatus($po, $by);

            $lockedGrn->update([
                'status'        => GrnStatus::PendingQc,
                'received_date' => now()->toDateString(),
                'received_by'   => $by->id,
                'incoming_qc_handoff_status' => IncomingQcHandoffStatus::NotStarted,
                'incoming_qc_handoff_message' => null,
                'incoming_qc_handoff_at' => now(),
            ]);
            $fresh = $lockedGrn->fresh();

            // Same chain wiring as create(): incoming QC synchronously, then
            // the async event for retry/idempotent listeners.
            try {
                app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($fresh));
            } catch (\Throwable $e) {
                $this->markIncomingQcHandoffPending(
                    $lockedGrn->id,
                    'Incoming QC trigger is waiting for queue replay: '.$e->getMessage(),
                );
                Log::warning('Synchronous incoming-QC trigger failed on GRN finalize', [
                    'grn_id' => $lockedGrn->id,
                    'error' => $e->getMessage(),
                ]);
            }
            app(OutboxService::class)->recordForChain(
                new GoodsReceiptNoteCreated($fresh),
                $fresh,
                'p2p',
                'grn',
                'received',
            );

            return $this->show($fresh);
        });
    }

    /** Retry only the GRN → Quality incoming-QC handoff. */
    public function retryIncomingQcHandoff(GoodsReceiptNote $grn): GoodsReceiptNote
    {
        return DB::transaction(function () use ($grn): GoodsReceiptNote {
            $locked = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== GrnStatus::PendingQc) {
                throw new BusinessRuleException(
                    'Only pending_qc GRNs can retry the incoming Quality handoff.'
                );
            }

            app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($locked->fresh()));

            return $this->show($locked->fresh());
        });
    }

    /** Retry the idempotent accepted-GRN journal handoff after accounting is configured/enabled. */
    public function retryGlHandoff(GoodsReceiptNote $grn): GoodsReceiptNote
    {
        if ($this->gl->post($grn) === null) {
            throw new BusinessRuleException(
                'The GRN GL handoff was skipped. Enable Accounting and verify the journal schema before retrying.',
            );
        }

        return $grn->fresh();
    }

    public function markIncomingQcHandoffGenerated(int $grnId): void
    {
        GoodsReceiptNote::query()->whereKey($grnId)->update([
            'incoming_qc_handoff_status' => IncomingQcHandoffStatus::Generated->value,
            'incoming_qc_handoff_message' => null,
            'incoming_qc_handoff_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markIncomingQcHandoffNotRequired(int $grnId): void
    {
        GoodsReceiptNote::query()->whereKey($grnId)->update([
            'incoming_qc_handoff_status' => IncomingQcHandoffStatus::NotRequired->value,
            'incoming_qc_handoff_message' => null,
            'incoming_qc_handoff_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markIncomingQcHandoffManual(int $grnId, ?string $message = null): void
    {
        GoodsReceiptNote::query()->whereKey($grnId)->update([
            'incoming_qc_handoff_status' => IncomingQcHandoffStatus::ManualRequired->value,
            'incoming_qc_handoff_message' => $message ?: 'Incoming QC trigger requires manual action.',
            'incoming_qc_handoff_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markIncomingQcHandoffPending(int $grnId, ?string $message = null): void
    {
        GoodsReceiptNote::query()->whereKey($grnId)->update([
            'incoming_qc_handoff_status' => IncomingQcHandoffStatus::NotStarted->value,
            'incoming_qc_handoff_message' => $message,
            'incoming_qc_handoff_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Accept the entire GRN — moves stock for every line at full quantity_received. */
    public function accept(GoodsReceiptNote $grn, User $by): GoodsReceiptNote
    {
        return DB::transaction(function () use ($grn, $by) {
            $lockedGrn = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedGrn->status !== GrnStatus::PendingQc) {
                throw new BusinessRuleException('Only pending_qc GRNs can be accepted.');
            }
            $this->assertQcGate($lockedGrn);

            $rows = GrnItem::query()
                ->where('goods_receipt_note_id', $lockedGrn->id)
                ->lockForUpdate()
                ->get();
            $this->snapshotLandedCosts($lockedGrn, $rows);

            foreach ($rows as $row) {
                $delta = bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3);
                if (bccomp($delta, '0', 3) < 0) {
                    throw new BusinessRuleException("Accepted quantity exceeds received for line {$row->id}.");
                }
                $row->quantity_accepted = $row->quantity_received;
                $row->save();
                $poItem = PurchaseOrderItem::query()->whereKey($row->purchase_order_item_id)->lockForUpdate()->firstOrFail();
                $poItem->quantity_accepted = bcadd((string) $poItem->quantity_accepted, $delta, 3);
                $poItem->save();
                $this->moveAcceptedQuantity($row, $delta, $by, "GRN {$lockedGrn->grn_number}");
            }

            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($lockedGrn->purchase_order_id);
            $this->refreshPoStatus($po, $by);
            $lockedGrn->update([
                'status' => GrnStatus::Accepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            $fresh = $lockedGrn->fresh();

            // Keep inventory and accounting atomic. When Accounting is disabled
            // post() intentionally returns null; when it is enabled, a missing
            // account or JE failure must roll back the stock receipt as well.
            $this->gl->post($fresh);
            $fresh = $fresh->fresh();

            app(OutboxService::class)->recordForChain(
                new GoodsReceiptNoteAccepted($fresh),
                $fresh,
                'p2p',
                'grn',
                GrnStatus::Accepted->value,
            );

            // Series C — Task C4. Stage real-time chain progress with the
            // acceptance; the outbox dispatch itself waits for commit.
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, GrnStatus::Accepted->value, $by);

            return $fresh;
        });
    }

    /** Partially accept — caller supplies quantity_accepted per grn_item id. */
    public function partialAccept(GoodsReceiptNote $grn, array $itemAcceptedMap, User $by): GoodsReceiptNote
    {
        $result = DB::transaction(function () use ($grn, $itemAcceptedMap, $by) {
            $lockedGrn = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedGrn->status, [GrnStatus::PendingQc, GrnStatus::PartialAccepted], true)) {
                throw new BusinessRuleException('Only pending_qc or partial_accepted GRNs can be accepted.');
            }
            if ($lockedGrn->remainder_rejected_at !== null) {
                throw new BusinessRuleException('The remainder of this GRN was rejected; it can no longer be accepted.');
            }
            $this->assertQcGate($lockedGrn, $itemAcceptedMap);

            $rows = GrnItem::query()
                ->where('goods_receipt_note_id', $lockedGrn->id)
                ->lockForUpdate()
                ->get();
            $this->snapshotLandedCosts($lockedGrn, $rows);
            $allFull = true;
            $hasDelta = false;
            foreach ($rows as $row) {
                $accepted = array_key_exists($row->id, $itemAcceptedMap)
                    ? (string) $itemAcceptedMap[$row->id]
                    : (string) $row->quantity_accepted;
                if (! is_numeric($accepted) || bccomp($accepted, '0', 3) < 0) {
                    throw new BusinessRuleException("Accepted quantity for line {$row->id} must be a non-negative number.");
                }
                $current = (string) $row->quantity_accepted;
                if (bccomp($accepted, $current, 3) < 0) {
                    throw new BusinessRuleException("Accepted quantity for line {$row->id} cannot be reduced after stock was posted.");
                }
                if (bccomp($accepted, (string) $row->quantity_received, 3) > 0) {
                    throw new BusinessRuleException("Accepted quantity exceeds received for line {$row->id}.");
                }
                if (bccomp($accepted, (string) $row->quantity_received, 3) < 0) {
                    $allFull = false;
                }
                $delta = bcsub($accepted, $current, 3);
                if (bccomp($delta, '0', 3) > 0) {
                    $hasDelta = true;
                }
                $row->quantity_accepted = $accepted;
                $row->save();
                $poItem = PurchaseOrderItem::query()->whereKey($row->purchase_order_item_id)->lockForUpdate()->firstOrFail();
                $poItem->quantity_accepted = bcadd((string) $poItem->quantity_accepted, $delta, 3);
                $poItem->save();
                $this->moveAcceptedQuantity($row, $delta, $by, "GRN {$lockedGrn->grn_number} (acceptance delta)");
            }
            if (! $hasDelta) {
                throw new BusinessRuleException('Increase at least one accepted quantity before submitting.');
            }

            $lockedGrn->update([
                'status' => $allFull ? GrnStatus::Accepted : GrnStatus::PartialAccepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($lockedGrn->purchase_order_id);
            $this->refreshPoStatus($po, $by);
            $fresh = $lockedGrn->fresh();

            $this->gl->post($fresh);
            $fresh = $fresh->fresh();

            // A partial acceptance moves real stock and posts its GRNI, so it
            // carries a payable exactly like a full acceptance. Publish the
            // accepted event for BOTH terminal states; createDraftForGrn()
            // keys off GrnStatus::billable() and stays idempotent (one bill
            // per GRN) when a later continuation accepts the remainder.
            app(OutboxService::class)->recordForChain(
                new GoodsReceiptNoteAccepted($fresh),
                $fresh,
                'p2p',
                'grn',
                $fresh->status->value,
            );

            app(ChainBroadcaster::class)->broadcastFor(
                $fresh,
                $fresh->status->value,
                $by,
            );

            return $fresh;
        });
        return $result;
    }

    public function reject(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        $result = DB::transaction(function () use ($grn, $reason, $by) {
            $lockedGrn = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedGrn->status !== GrnStatus::PendingQc) {
                throw new BusinessRuleException('Only pending_qc GRNs can be rejected.');
            }
            $lockedGrn->loadMissing('qcInspection');
            if ($lockedGrn->qcInspection && ! in_array(
                $lockedGrn->qcInspection->status?->value,
                ['passed', 'failed', 'cancelled'],
                true,
            )) {
                $this->resolveInspectionService()?->cancel(
                    $lockedGrn->qcInspection,
                    'Logistics rejection — no quality issue found',
                    $by,
                );
            }
            $this->reversePoReceipt($lockedGrn, $by);
            $lockedGrn->update([
                'status' => GrnStatus::Rejected,
                'rejected_reason' => $reason,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);

            $fresh = $lockedGrn->fresh();
            $this->openSupplierReturnForRejectedGrn($fresh, $by, $reason);
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, GrnStatus::Rejected->value, $by);

            return $fresh;
        });
        return $result;
    }

    /**
     * Reject the un-accepted remainder of a partially accepted GRN.
     * The remainder is the difference between quantity_received and quantity_accepted per line.
     * This opens a supplier return for the remainder and updates PO line quantities.
     */
    public function rejectRemainder(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        return DB::transaction(function () use ($grn, $reason, $by) {
            $lockedGrn = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGrn->status !== GrnStatus::PartialAccepted) {
                throw new BusinessRuleException('Only partial_accepted GRNs can have their remainder rejected.');
            }

            if ($lockedGrn->remainder_rejected_at !== null) {
                throw new BusinessRuleException('The remainder of this GRN was already rejected.');
            }

            $po = PurchaseOrder::query()
                ->whereKey($lockedGrn->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $rows = GrnItem::query()
                ->where('goods_receipt_note_id', $lockedGrn->id)
                ->lockForUpdate()
                ->get();

            $hadRemainder = false;
            foreach ($rows as $row) {
                $remainder = bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3);
                if (bccomp($remainder, '0', 3) <= 0) {
                    continue;
                }

                $hadRemainder = true;
                $poItem = PurchaseOrderItem::query()
                    ->whereKey($row->purchase_order_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (bccomp((string) $poItem->quantity_received, $remainder, 3) < 0) {
                    throw new BusinessRuleException(
                        "Cannot reject remainder for GRN {$lockedGrn->grn_number}: PO line {$poItem->id} received quantity would go negative."
                    );
                }

                $poItem->quantity_received = bcsub((string) $poItem->quantity_received, $remainder, 3);
                $poItem->save();
            }

            if (! $hadRemainder) {
                throw new BusinessRuleException('Nothing left to reject on this GRN.');
            }

            $lockedGrn->forceFill([
                'remainder_rejected_at' => now(),
                'remainder_rejected_by' => $by->id,
                'remainder_rejected_reason' => $reason,
            ])->save();

            $zeroStatus = $po->sent_to_supplier_at
                ? PurchaseOrderStatus::Sent
                : PurchaseOrderStatus::Approved;
            $this->refreshPoStatus($po, $by, $zeroStatus);

            $fresh = $lockedGrn->fresh();
            $this->openSupplierReturnForRejectedRemainder($fresh, $by, $reason);

            app(ChainBroadcaster::class)->broadcastFor($fresh, $fresh->status->value, $by);

            return $fresh;
        });
    }

    /**
     * Settle incoming QC when all inspections are terminal (passed/failed/cancelled).
     *
     * Rules (when MRB review is enabled):
     * - Any failed inspection without NCR disposition → return 'awaiting_mrb', do nothing.
     * - GRN-level failed inspection → reject entire GRN based on NCR disposition.
     * - Line-level failures → build accepted map based on each NCR disposition:
     *   - passed inspection → full quantity_received
     *   - use_as_is disposition → full quantity_received (concession)
     *   - rework disposition → full quantity_received (held in quarantine, MRB record opened)
     *   - return_to_supplier or scrap → mrb_accepted_quantity ?? 0 (sorting decision)
     *
     * Rules (when MRB review is disabled or legacy behavior):
     * - No failed lines → accept() entire GRN.
     * - All lines failed → reject() entire GRN.
     * - Mixed (some passed, some failed) → partialAccept() the passed lines, rejectRemainder() the failed lines.
     *
     * Common rules:
     * - Any inspection still draft/in-progress → return 'awaiting_sibling_qc', do nothing.
     * - A cancelled inspection counts as not failed (completed logistics decision).
     * - A line with no inspection (QC-exempt item) counts as not failed.
     * - An inspection with grn_item_id=null that failed means ALL lines failed.
     *
     * @return string  One of: 'awaiting_sibling_qc', 'awaiting_mrb', 'grn_accepted', 'grn_rejected', 'grn_partially_accepted'
     */
    public function settleIncomingQc(GoodsReceiptNote $grn, User $by): string
    {
        return DB::transaction(function () use ($grn, $by): string {
            $lockedGrn = GoodsReceiptNote::query()
                ->whereKey($grn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGrn->status !== GrnStatus::PendingQc) {
                return 'grn_already_terminal';
            }

            $inspections = $this->incomingInspections($lockedGrn);

            // Check if any inspection is still draft/in-progress (not terminal).
            $pending = $inspections->first(fn (object $inspection): bool =>
                ! in_array((string) $inspection->status, ['passed', 'failed', 'cancelled'], true)
                || ($inspection->status !== 'cancelled'
                    && ! $this->inspectionIsChecked($inspection))
            );
            if ($pending !== null) {
                return 'awaiting_sibling_qc';
            }

            // Categorize inspections by result.
            $failedLineIds = [];
            $failedLineInspectionMap = [];  // Maps line ID → inspection number
            $failedInspectionNumbers = [];
            $grnLevelFailed = false;

            foreach ($inspections as $inspection) {
                if ($inspection->status === 'failed') {
                    if ($inspection->grn_item_id === null) {
                        // GRN-level failure means all lines failed.
                        $grnLevelFailed = true;
                    } else {
                        $lineId = (int) $inspection->grn_item_id;
                        $failedLineIds[] = $lineId;
                        $failedLineInspectionMap[$lineId] = (string) $inspection->inspection_number;
                    }
                    $failedInspectionNumbers[] = (string) $inspection->inspection_number;
                }
            }

            $failedInspections = $inspections->filter(fn (object $i): bool => $i->status === 'failed')->values();

            // Case 1: nothing failed → accept the whole GRN.
            if ($failedInspections->isEmpty()) {
                $this->accept($lockedGrn, $by);
                return 'grn_accepted';
            }

            // inspection_number already carries its QC- prefix.
            $reason = 'Auto-rejected: incoming inspection '.implode(', ', $failedInspectionNumbers).' failed.';

            if ($this->settings->requiredBool('quality.incoming_failure.mrb_review', true)) {
                return $this->settleIncomingQcByMrb($lockedGrn, $failedInspections, $failedInspectionNumbers, $by);
            }

            // Legacy (MRB review off): a failed verdict is final.
            if ($grnLevelFailed) {
                $this->reject($lockedGrn, $reason, $by);
                return 'grn_rejected';
            }

            $lockedGrn->loadMissing('items');
            $itemAcceptedMap = [];
            foreach ($lockedGrn->items as $line) {
                $itemAcceptedMap[(int) $line->id] = in_array((int) $line->id, $failedLineIds, true)
                    ? '0'
                    : (string) $line->quantity_received;
            }
            if (collect($itemAcceptedMap)->every(fn (string $qty): bool => bccomp($qty, '0', 3) === 0)) {
                $this->reject($lockedGrn, $reason, $by);
                return 'grn_rejected';
            }

            $this->partialAccept($lockedGrn, $itemAcceptedMap, $by);
            $this->rejectRemainder($lockedGrn->fresh(), $reason, $by);

            return 'grn_partially_accepted';
        });
    }

    /**
     * MRB path: a failed incoming inspection is not final until the Material
     * Review Board records a disposition on its NCR. The disposition decides
     * how much of each affected line enters stock:
     *   use_as_is / rework       → the whole line (rework is then held in quarantine)
     *   return_to_supplier/scrap → only the good pieces kept after sorting
     *                              (mrb_accepted_quantity, default 0)
     * The rest is rejected back to the supplier through the normal GRN
     * reject / reject-remainder paths, so the PO receipt and RMA stay single-sourced.
     *
     * @param  Collection<int, object>  $failedInspections
     * @param  list<string>  $failedInspectionNumbers
     */
    private function settleIncomingQcByMrb(
        GoodsReceiptNote $lockedGrn,
        Collection $failedInspections,
        array $failedInspectionNumbers,
        User $by,
    ): string {
        $decisions = $this->incomingMrbDecisions($failedInspections);

        // An NCR that exists but has no disposition yet is the board's to
        // decide; hold the GRN in pending_qc. A failed inspection with no NCR
        // at all (NCR creation disabled/failed) keeps the legacy "reject" meaning.
        foreach ($failedInspections as $inspection) {
            $decision = $decisions[(int) $inspection->id] ?? null;
            if ($decision !== null && ($decision->disposition === null || $decision->mrb_decided_at === null)) {
                return 'awaiting_mrb';
            }
        }

        $lockedGrn->loadMissing('items');
        $itemAcceptedMap = [];
        $reworkLines = [];
        $dispositions = [];
        foreach ($lockedGrn->items as $line) {
            $accepted = (string) $line->quantity_received;
            $rework = null;
            foreach ($failedInspections as $inspection) {
                if ($inspection->grn_item_id !== null && (int) $inspection->grn_item_id !== (int) $line->id) {
                    continue;
                }
                $decision = $decisions[(int) $inspection->id] ?? null;
                $cap = $this->mrbAcceptableQuantity($decision, $line);
                if (bccomp($cap, $accepted, 3) < 0) {
                    $accepted = $cap;
                }
                if ($decision !== null) {
                    $dispositions[] = (string) $decision->disposition;
                    if ($decision->disposition === 'rework') {
                        $rework = $decision->ncr_id;
                    }
                }
            }
            $itemAcceptedMap[(int) $line->id] = $accepted;
            if ($rework !== null && bccomp($accepted, '0', 3) > 0) {
                $reworkLines[(int) $line->id] = \App\Modules\Quality\Models\NonConformanceReport::query()->findOrFail($rework);
            }
        }

        $reason = 'MRB disposition ('.implode(', ', array_unique($dispositions) ?: ['no NCR']).') on incoming inspection '
            .implode(', ', $failedInspectionNumbers).'.';

        $allFull = true;
        $allZero = true;
        foreach ($lockedGrn->items as $line) {
            $accepted = $itemAcceptedMap[(int) $line->id];
            $allFull = $allFull && bccomp($accepted, (string) $line->quantity_received, 3) === 0;
            $allZero = $allZero && bccomp($accepted, '0', 3) === 0;
        }

        if ($allZero) {
            $this->reject($lockedGrn, $reason, $by);
            return 'grn_rejected';
        }

        if ($allFull) {
            $settled = $this->accept($lockedGrn, $by);
            $outcome = 'grn_accepted';
        } else {
            $this->partialAccept($lockedGrn, $itemAcceptedMap, $by);
            $settled = $this->rejectRemainder($lockedGrn->fresh(), $reason, $by);
            $outcome = 'grn_partially_accepted';
        }

        if ($reworkLines !== []) {
            app(QuarantineService::class)->holdMultipleForMrb($settled, $reworkLines, $by);
        }

        return $outcome;
    }

    /**
     * MRB decisions for failed incoming inspections, keyed by inspection id.
     * Empty when MRB review is off, so a failed verdict stays final.
     *
     * @param  Collection<int, object>  $failedInspections
     * @return array<int, object{ncr_id:int, disposition:?string, mrb_accepted_quantity:?string, mrb_decided_at:?string}>
     */
    private function incomingMrbDecisions(Collection $failedInspections): array
    {
        if ($failedInspections->isEmpty()
            || ! $this->settings->requiredBool('quality.incoming_failure.mrb_review', true)) {
            return [];
        }

        return DB::table('non_conformance_reports')
            ->whereIn('inspection_id', $failedInspections->pluck('id')->all())
            ->where('status', '!=', 'cancelled')
            ->get(['id as ncr_id', 'inspection_id', 'disposition', 'mrb_accepted_quantity', 'mrb_decided_at'])
            ->keyBy(fn (object $row): int => (int) $row->inspection_id)
            ->all();
    }

    /**
     * The most of this line a failed inspection lets into stock. Without a
     * recorded MRB decision the failed verdict is binding (0).
     */
    private function mrbAcceptableQuantity(?object $decision, GrnItem $line): string
    {
        $received = (string) $line->quantity_received;
        if ($decision === null || $decision->mrb_decided_at === null) {
            return '0';
        }

        $cap = match ((string) $decision->disposition) {
            'use_as_is', 'rework' => $received,
            'return_to_supplier', 'scrap' => (string) ($decision->mrb_accepted_quantity ?? '0'),
            default => '0',
        };

        return bccomp($cap, $received, 3) > 0 ? $received : bcadd($cap, '0', 3);
    }

    /**
     * Open a supplier return for the rejected remainder of a partially accepted GRN.
     * Similar to openSupplierReturnForRejectedGrn but uses the remainder quantity instead of full received quantity.
     *
     * Deliberately NOT wrapped in try/catch: a remainder rejection that reverses the PO
     * quantity but silently opens no RMA is a stranded goods/money state.
     * It must surface and roll back for a retry.
     */
    private function openSupplierReturnForRejectedRemainder(GoodsReceiptNote $grn, User $by, string $reason): void
    {
        if (! $grn->vendor_id) {
            throw new BusinessRuleException(
                "Cannot open a supplier return for rejected GRN remainder {$grn->grn_number}: it has no vendor."
            );
        }

        $grn->loadMissing(['items.purchaseOrderItem']);
        $lines = [];
        foreach ($grn->items as $row) {
            $remainder = bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3);
            if (bccomp($remainder, '0', 3) <= 0) {
                continue;
            }

            $poItem = $row->purchaseOrderItem;
            $lines[] = [
                'grn_item_id'            => (int) $row->id,
                'purchase_order_item_id' => $row->purchase_order_item_id ? (int) $row->purchase_order_item_id : null,
                'item_id'                => (int) $row->item_id,
                'quantity'               => $remainder,
                'unit_price'             => $poItem
                    ? $this->baseUnitCost($poItem, (string) $poItem->unit_price)
                    : (string) $row->unit_cost,
                'reason'                 => $reason,
                'lot_number'             => $row->material_lot_number,
                'reversal_already_applied' => true,
            ];
        }

        if ($lines === []) {
            return;
        }

        // Use 'grn-rejection:' key so that NcrService::openSupplierReturnRmaForNcr()
        // can find and reuse the RMA opened here when closing the failed inspection's NCR.
        // Full rejection (grn-rejection:) and remainder rejection (same key) are mutually
        // exclusive on one GRN (pending_qc vs partial_accepted), so one key is correct.
        app(ReturnRequestService::class)->openSupplierReturnForReversedGoods(
            vendorId: (int) $grn->vendor_id,
            purchaseOrderId: $grn->purchase_order_id ? (int) $grn->purchase_order_id : null,
            goodsReceiptNoteId: (int) $grn->id,
            lines: $lines,
            by: $by,
            reason: $reason,
            dedupeKey: 'grn-rejection:'.$grn->id,
        );
    }

    /**
     * A rejected incoming receipt still moves money: the supplier is owed a
     * credit and the buyer often needs a replacement. Open the supplier-return
     * RMA here — inside the same transaction as the rejection — so the receipt
     * reversal and the return ledger commit or roll back together. The
     * `source_key` makes this idempotent across a redelivered
     * InspectionFailed listener or an operator retry.
     *
     * Deliberately NOT wrapped in try/catch: a rejection that reverses the PO
     * quantity but silently opens no RMA is a stranded goods/money state, and
     * swallowing the failure is exactly the "dead subsystem" shape the repo
     * forbids. It must surface and roll the rejection back for a retry.
     */
    private function openSupplierReturnForRejectedGrn(GoodsReceiptNote $grn, User $by, string $reason): void
    {
        if (! $grn->vendor_id) {
            throw new BusinessRuleException(
                "Cannot open a supplier return for rejected GRN {$grn->grn_number}: it has no vendor."
            );
        }

        $grn->loadMissing(['items.purchaseOrderItem']);
        $lines = [];
        foreach ($grn->items as $row) {
            $quantity = (string) $row->quantity_received;
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }
            $poItem = $row->purchaseOrderItem;
            $lines[] = [
                'grn_item_id'            => (int) $row->id,
                'purchase_order_item_id' => $row->purchase_order_item_id ? (int) $row->purchase_order_item_id : null,
                'item_id'                => (int) $row->item_id,
                'quantity'               => $quantity,
                'unit_price'             => $poItem
                    ? $this->baseUnitCost($poItem, (string) $poItem->unit_price)
                    : (string) $row->unit_cost,
                'reason'                 => $reason,
                'lot_number'             => $row->material_lot_number,
                // GrnService::reversePoReceipt() already reduced the PO
                // received quantity, so the RMA must not do it a second time.
                'reversal_already_applied' => true,
            ];
        }

        if ($lines === []) {
            return;
        }

        app(ReturnRequestService::class)->openSupplierReturnForReversedGoods(
            vendorId: (int) $grn->vendor_id,
            purchaseOrderId: $grn->purchase_order_id ? (int) $grn->purchase_order_id : null,
            goodsReceiptNoteId: (int) $grn->id,
            lines: $lines,
            by: $by,
            reason: $reason,
            dedupeKey: 'grn-rejection:'.$grn->id,
        );
    }

    /**
     * Sprint 7 Task 60 — incoming-QC gate.
     *
     * If the GRN has been linked to an inspection (qc_inspection_id),
     * accepting the GRN requires that inspection to be in `passed` status.
     * F-06: the gate FAILS CLOSED — a GRN whose QC-eligible lines have no
     * inspection record at all means QC was skipped or failed to materialize,
     * and must not be accepted. Inspection rows are created synchronously at
     * GRN creation, so their absence is an anomaly, not a back-compat state.
     * Only GRNs with no QC-eligible lines (no raw-material items, no active
     * quality plan) bypass the gate.
     *
     * When $itemAcceptedMap is given (partial accept only), a FAILED
     * inspection for a line that results in accepted quantity > 0 is blocking.
     * A failed line with accepted quantity = 0 is allowed (the line is fully
     * rejected). A failed GRN-level inspection (grn_item_id null) always blocks.
     */
    private function assertQcGate(GoodsReceiptNote $grn, ?array $itemAcceptedMap = null): void
    {
        $inspections = $this->incomingInspections($grn);
        $this->assertIncomingInspectionCoverage($grn, $inspections);

        if ($inspections->isEmpty()) {
            return;
        }

        $grn->loadMissing('items');
        // A failed verdict the MRB dispositioned (concession / rework / sort)
        // permits acceptance up to the board's quantity; nothing else does.
        $mrbDecisions = $this->incomingMrbDecisions($inspections->filter(
            fn (object $inspection): bool => $inspection->status === 'failed' && $this->inspectionIsChecked($inspection),
        ));
        $mrbAllowsFull = function (object $inspection) use ($grn, $mrbDecisions): bool {
            $decision = $mrbDecisions[(int) $inspection->id] ?? null;
            if ($decision === null) {
                return false;
            }
            foreach ($grn->items as $line) {
                if ($inspection->grn_item_id !== null && (int) $inspection->grn_item_id !== (int) $line->id) {
                    continue;
                }
                if (bccomp($this->mrbAcceptableQuantity($decision, $line), (string) $line->quantity_received, 3) < 0) {
                    return false;
                }
            }

            return true;
        };

        // F-12 — a cancelled inspection (logistics rejection, P3.6) is a
        // completed decision; it must not block acceptance forever.
        $blocking = $inspections->first(fn (object $inspection): bool =>
            ! in_array((string) $inspection->status, ['passed', 'cancelled', 'failed'], true)
            || ($inspection->status === 'passed' && ! $this->inspectionIsChecked($inspection))
            || ($inspection->status === 'failed' && (! $this->inspectionIsChecked($inspection) || ! $mrbAllowsFull($inspection)))
        );

        // Full accept (no map): any unfinished or failed inspection blocks.
        if ($itemAcceptedMap === null) {
            if ($blocking !== null) {
                if ((string) $blocking->status === 'passed'
                    && ! $this->inspectionIsChecked($blocking)) {
                    throw new BusinessRuleException(
                        "GRN {$grn->grn_number} cannot be accepted until the incoming inspection is checked."
                    );
                }
                throw new BusinessRuleException(
                    "GRN {$grn->grn_number} cannot be accepted until every incoming inspection passes (current: "
                    .((string) $blocking->status ?: 'unknown').').'
                );
            }
            return;
        }

        // Line-aware check for partialAccept. Every non-passed inspection is
        // checked, not just the first: a failed LINE inspection is allowed
        // only when that line accepts 0 (or no more than the MRB allowed).
        // Unfinished verdicts still block, so a partial accept never
        // pre-empts a pending result.
        foreach ($inspections as $inspection) {
            $status = (string) $inspection->status;
            if ($status === 'cancelled') {
                continue;
            }
            if ($status === 'passed' && ! $this->inspectionIsChecked($inspection)) {
                throw new BusinessRuleException(
                    "GRN {$grn->grn_number} cannot be accepted until the incoming inspection is checked."
                );
            }
            if ($status === 'passed') {
                continue;
            }
            if ($status !== 'failed') {
                throw new BusinessRuleException(
                    "GRN {$grn->grn_number} cannot be accepted until every incoming inspection is complete (current: "
                    .($status ?: 'unknown').').'
                );
            }
            if (! $this->inspectionIsChecked($inspection)) {
                throw new BusinessRuleException(
                    "GRN {$grn->grn_number} cannot settle an incoming failure until it is checked."
                );
            }
            $decision = $mrbDecisions[(int) $inspection->id] ?? null;
            if ($inspection->grn_item_id === null && $decision === null) {
                throw new BusinessRuleException(
                    "GRN {$grn->grn_number} failed incoming QC and cannot be accepted."
                );
            }

            foreach ($grn->items as $line) {
                if ($inspection->grn_item_id !== null && (int) $inspection->grn_item_id !== (int) $line->id) {
                    continue;
                }
                $lineId = (int) $line->id;
                $resultingAccepted = (string) ($itemAcceptedMap[$lineId] ?? $line->quantity_accepted ?? '0');
                $cap = $this->mrbAcceptableQuantity($decision, $line);
                if (bccomp($resultingAccepted, $cap, 3) > 0) {
                    throw new BusinessRuleException(bccomp($cap, '0', 3) === 0
                        ? "Line {$lineId} failed incoming QC and cannot be accepted."
                        : "Line {$lineId} failed incoming QC; the MRB allows at most {$cap} to be accepted.");
                }
            }
        }
    }

    /**
     * Load only incoming inspections belonging to this GRN.
     *
     * @return Collection<int, object{ id:int, status:string, grn_item_id:int|null, inspection_number:string }>
     */
    private function incomingInspections(GoodsReceiptNote $grn): Collection
    {
        return DB::table('inspections')
            ->where('stage', 'incoming')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get(['id', 'status', 'inspector_id', 'reviewed_by', 'reviewed_at', 'grn_item_id', 'inspection_number']);
    }

    private function inspectionIsChecked(object $inspection): bool
    {
        return $inspection->inspector_id !== null
            && $inspection->reviewed_by !== null
            && $inspection->reviewed_at !== null
            && (int) $inspection->inspector_id !== (int) $inspection->reviewed_by;
    }

    /**
     * A terminal single-screen decision must have an incoming inspection for
     * every QC-eligible line. This is separate from assertQcGate() because a
     * failed verdict is allowed to complete an inspection as failed, while a
     * missing inspection must never be treated as a failed verdict.
     */
    private function assertIncomingInspectionCoverage(
        GoodsReceiptNote $grn,
        ?Collection $inspections = null,
    ): void {
        $inspections ??= $this->incomingInspections($grn);

        // qc_inspection_id is an anchor, not an exemption. A stale, wrong-stage,
        // or wrong-GRN anchor must fail closed instead of becoming a null status.
        if ($grn->qc_inspection_id !== null && ! $inspections->contains(
            static fn (object $inspection): bool => (int) $inspection->id === (int) $grn->qc_inspection_id,
        )) {
            throw new BusinessRuleException(
                "GRN {$grn->grn_number} has an invalid incoming QC inspection anchor."
            );
        }

        $eligibleLineIds = $this->qcEligibleLineIds($grn);
        if ($eligibleLineIds === []) {
            return;
        }

        if ($inspections->isEmpty()) {
            throw new BusinessRuleException(
                "GRN {$grn->grn_number} has no incoming inspection records; "
                .'incoming QC must be completed before acceptance.'
            );
        }

        foreach ($eligibleLineIds as $lineId) {
            if ($inspections->contains(
                static fn (object $inspection): bool => (int) $inspection->grn_item_id === $lineId,
            )) {
                continue;
            }
            throw new BusinessRuleException(
                "GRN {$grn->grn_number} has no incoming inspection for line {$lineId}; "
                .'incoming QC must be completed before acceptance.'
            );
        }
    }

    private function hasQcEligibleLines(GoodsReceiptNote $grn): bool
    {
        return $this->qcEligibleLineIds($grn) !== [];
    }

    /**
     * Which GRN lines require an incoming inspection before acceptance.
     *
     * `Item` uses SoftDeletes, so `$line->item` is NULL once inventory-master
     * archives the item — and a null item used to make the line silently
     * INELIGIBLE. That turned the whole fail-closed gate into a fail-open one:
     * with the item archived, an empty eligible set short-circuits
     * assertIncomingInspectionCoverage(), and an empty inspection set then
     * short-circuits assertQcGate(). Measured: a receipt with ZERO inspection
     * rows and a nulled qc_inspection_id was ACCEPTED and moved 10.000 units
     * into stock, while the identical receipt with a live item was refused.
     *
     * Archiving a master-data row is not a quality decision, so the item is
     * resolved withTrashed(); and a line whose item cannot be resolved at all
     * is treated as eligible — an anomaly must require QC, never waive it.
     *
     * @return array<int, int>
     */
    private function qcEligibleLineIds(GoodsReceiptNote $grn): array
    {
        $grn->loadMissing('items.item');
        $eligible = [];
        foreach ($grn->items as $line) {
            $item = $line->item
                ?? Item::withTrashed()->find($line->item_id);
            if (! $item) {
                $eligible[] = (int) $line->id;
                continue;
            }
            if ($item->item_type === ItemType::RawMaterial) {
                $eligible[] = (int) $line->id;
                continue;
            }
            if ($item->qualityPlans()->effective(now()->toDateString())->exists()) {
                $eligible[] = (int) $line->id;
            }
        }
        return $eligible;
    }

    /**
     * The Quality inspection schema stores an integer batch quantity. Do not
     * silently truncate a decimal receipt into a smaller QC batch.
     */
    private function hasFractionalQcQuantity(GoodsReceiptNote $grn): bool
    {
        $eligible = array_fill_keys($this->qcEligibleLineIds($grn), true);
        foreach ($grn->items as $line) {
            if (! isset($eligible[(int) $line->id])) {
                continue;
            }

            $quantity = (string) $line->quantity_received;
            if (bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function hasFractionalReceivedQuantity(GoodsReceiptNote $grn): bool
    {
        foreach ($grn->items as $line) {
            $quantity = (string) $line->quantity_received;
            if (bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert the stored base-unit receipt total to the integer quantity
     * required by the fallback incoming-inspection contract.
     */
    private function inspectionBatchQuantity(GoodsReceiptNote $grn): int
    {
        $total = '0.000';
        foreach ($grn->items as $line) {
            $total = bcadd($total, (string) $line->quantity_received, 3);
        }

        if (bccomp($total, bcadd($total, '0', 0), 3) !== 0) {
            throw new BusinessRuleException(
                'Fractional incoming quantities require line-level Quality inspection; '
                .'the single-screen QC flow cannot truncate them.'
            );
        }

        return (int) bcadd($total, '0', 0);
    }

    /**
     * CA2 — Single-screen receiving. Creates GRN + records QC inspection + accepts/rejects
     * in one atomic transaction, combining what were previously 3 separate API calls.
     *
     * @param  array  $items  Same format as create()
     * @param  array  $meta  ['received_date' => ..., 'remarks' => ...]
     * @param  array  $qcData  ['result' => passed|failed|passed_with_remarks|pending, 'inspector_id' => ..., 'product_id' => ..., 'checks' => [...], 'remarks' => ..., 'failure_reason' => ..., 'disposition' => ...]
     * @return array{grn: GoodsReceiptNote, inspection: mixed, qc_result: string, disposition: string|null, stock_updated: bool}
     */
    public function receiveWithQc(
        PurchaseOrder $po,
        array $items,
        array $meta,
        array $qcData,
        User $by,
    ): array {
        $idempotencyKey = $this->normaliseIdempotencyKey($meta['idempotency_key'] ?? null);
        $fingerprint = $idempotencyKey === null ? null : $this->fingerprint([
            'operation' => 'grn.receive_with_qc',
            'actor_id' => $by->id,
            'purchase_order_id' => $po->id,
            'items' => $items,
            'received_date' => $meta['received_date'] ?? null,
            'remarks' => $meta['remarks'] ?? null,
            'qc' => $qcData,
        ]);

        return DB::transaction(function () use ($po, $items, $meta, $qcData, $by, $idempotencyKey, $fingerprint) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null) {
                $existing = GoodsReceiptNote::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->replayIdempotentGrn($existing, (string) $fingerprint);
                    $response = $existing->idempotency_response;
                    if (! is_array($response)) {
                        throw new BusinessRuleException('The original single-screen receiving result is unavailable; inspect the linked GRN before retrying.');
                    }

                    return [
                        'grn' => $this->show($existing),
                        'inspection' => null,
                        'qc_result' => $response['qc_result'],
                        'disposition' => $response['disposition'],
                        'stock_updated' => $response['stock_updated'],
                    ];
                }
            }

            if (in_array($qcData['disposition'] ?? null, ['use_under_concession', 'partial_accept'], true)) {
                throw new BusinessRuleException(
                    'QC disposition is not implemented for single-screen receiving. Use the GRN partial-accept or supplier-return workflow instead.'
                );
            }
            if (
                in_array($qcData['result'] ?? null, ['passed', 'passed_with_remarks', 'failed'], true)
                && ! $by->hasPermission('quality.inspections.manage')
            ) {
                throw new BusinessRuleException(
                    'The quality.inspections.manage permission is required to submit a terminal QC result.'
                );
            }

            // 1. Create GRN (pending_qc)
            $grn = $this->create($po, $items, array_merge($meta, [
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $fingerprint,
            ]), $by);

            // 2. Create QC inspection if inspection data provided
            $inspection = null;
            $inspectionService = $this->resolveInspectionService();

            // F-06 — GrnService::create() now creates the incoming inspections
            // synchronously. If they already exist (the normal case), reuse
            // them and apply the operator verdict to all of them instead of
            // double-creating (which trips the per-GRN unique constraint).
            $existingInspections = $inspectionService
                ? Inspection::query()
                    ->where('stage', 'incoming')
                    ->where('entity_type', 'grn')
                    ->where('entity_id', $grn->id)
                    ->get()
                : collect();

            if ($existingInspections->isNotEmpty()) {
                // The single-screen verdict lands on the first inspection of
                // record; the fast-complete loop below applies it to all.
                $inspection = $existingInspections->first();
            }

            $hasQcEligibleLines = $this->hasQcEligibleLines($grn);
            $hasFractionalQcQuantity = $hasQcEligibleLines
                && $this->hasFractionalQcQuantity($grn);
            $hasFractionalReceivedQuantity = $this->hasFractionalReceivedQuantity($grn);

            if (
                $inspectionService
                && ! empty($qcData)
                && $existingInspections->isEmpty()
                && $hasQcEligibleLines
                && ! $hasFractionalQcQuantity
            ) {
                $inspectorId = null;
                if (! empty($qcData['inspector_id'])) {
                    $inspectorId = HashIdFilter::decode($qcData['inspector_id'], User::class)
                        ?? (ctype_digit((string) $qcData['inspector_id']) ? (int) $qcData['inspector_id'] : null);
                }

                $productId = null;
                if (! empty($qcData['product_id'])) {
                    $productId = HashIdFilter::decode($qcData['product_id'], Product::class)
                        ?? (ctype_digit((string) $qcData['product_id']) ? (int) $qcData['product_id'] : null);
                }

                // Use the existing InspectionService::create() which builds
                // measurement scaffolds from the product's inspection spec.
                // This requires a product_id; if one is not supplied, we skip
                // the full inspection record and still process the GRN result.
                if ($productId) {
                    $totalQty = $this->inspectionBatchQuantity($grn);
                    if ($totalQty < 1) {
                        throw new BusinessRuleException('Incoming inspection requires a positive received quantity.');
                    }
                    $inspector = $inspectorId
                        ? User::query()->findOrFail($inspectorId)
                        : $by;

                    try {
                        $inspection = $inspectionService->create([
                            'stage' => 'incoming',
                            'product_id' => $productId,
                            'batch_quantity' => (int) $totalQty,
                            'entity_type' => 'grn',
                            'entity_id' => $grn->id,
                            'notes' => $qcData['remarks'] ?? null,
                        ], $inspector);

                        // The InspectionService::create() already back-links
                        // qc_inspection_id onto the GRN via DB::table update,
                        // so we reload to pick up the change.
                        $grn->refresh();
                    } catch (RuntimeException) {
                        // A received raw item may have no CRM-product spec. Keep
                        // the QC decision auditable with the item-level verdict.
                        $line = $grn->items
                            ->sortByDesc(fn ($row) => (float) $row->quantity_received)
                            ->first();
                        if ($line) {
                            $inspection = $inspectionService->createIncomingForItem(
                                Item::query()->findOrFail($line->item_id),
                                $totalQty,
                                $grn->id,
                                $inspector,
                                $qcData['remarks'] ?? null,
                            );
                            $grn->refresh();
                        }
                    }
                } else {
                    $line = $grn->items
                        ->sortByDesc(fn ($row) => (float) $row->quantity_received)
                        ->first();
                    if ($line) {
                        $inspector = $inspectorId
                            ? User::query()->findOrFail($inspectorId)
                            : $by;
                        $inspection = $inspectionService->createIncomingForItem(
                            Item::query()->findOrFail($line->item_id),
                            $this->inspectionBatchQuantity($grn),
                            $grn->id,
                            $inspector,
                            $qcData['remarks'] ?? null,
                        );
                        $grn->refresh();
                    }
                }
            }

            // 3. Based on QC result, accept or leave pending
            // Never infer a passed inspection when QC data is absent. A
            // missing verdict remains pending until an inspector records it.
            $qcResult = $qcData['result'] ?? (string) $this->settings->get('inventory.grn.default_qc_result', '');
            $disposition = null;

            if (in_array($qcResult, ['passed', 'passed_with_remarks', 'failed'], true)) {
                if ($hasFractionalReceivedQuantity) {
                    throw new BusinessRuleException(
                        'Fractional incoming quantities require line-level Quality inspection; '
                        .'single-screen terminal QC is not supported.'
                    );
                }
                // A failed handoff must not become a terminal single-screen
                // decision without persisted QC rows.
                $this->assertIncomingInspectionCoverage($grn);
            }

            if ($qcResult === 'passed' || $qcResult === 'passed_with_remarks') {
                // F-06 — the verdict applies to every inspection on the GRN
                // (synchronously created at GRN creation), not just the one
                // created by this single-screen flow.
                $inspectionsToComplete = $existingInspections->isNotEmpty()
                    ? $existingInspections
                    : collect([$inspection])->filter();
                foreach ($inspectionsToComplete as $insp) {
                    $this->fastCompleteInspection($insp, true, $by);
                }
                if ($inspection) {
                    $inspection = $inspection->fresh();
                }
                // When the inspection requires maker-checker review (incoming GRN inspections always do),
                // it moves to awaiting_review status. Do not attempt acceptance yet — the
                // AcceptGrnOnIncomingQcPass listener will accept the GRN once a different user reviews.
                $inspectionStatus = $inspection ? ($inspection->status instanceof \BackedEnum
                    ? $inspection->status->value
                    : (string) $inspection->status) : null;
                if ($inspection && $inspectionStatus === 'awaiting_review') {
                    // Leave GRN in pending_qc; the listener will accept when inspection is reviewed
                } else {
                    $grn = $this->acceptInternal($grn, $by);
                }
            } elseif ($qcResult === 'failed') {
                $disposition = $qcData['disposition'] ?? null;
                // Distinguish between a genuine quality failure (triggers NCR)
                // and a logistics rejection such as wrong part number or short
                // shipment (must NOT open an NCR — P3.6 audit fix).
                $isQualityFailure = ($qcData['is_quality_failure'] ?? true) !== false;
                $inspectionsToComplete = $existingInspections->isNotEmpty()
                    ? $existingInspections
                    : collect([$inspection])->filter();
                foreach ($inspectionsToComplete as $insp) {
                    $this->fastCompleteInspection($insp, false, $by, $isQualityFailure);
                }
                if ($inspection) {
                    $inspection = $inspection->fresh();
                }
                // A quality failure is a verdict like any other: it counts once a
                // different user reviews it. The review opens the NCR and settles
                // the receipt (through the MRB when enabled), so rejecting here
                // would bypass both. A logistics rejection is not a QC verdict.
                $failureAwaitsReview = $isQualityFailure && $inspectionsToComplete->contains(
                    fn (Inspection $insp): bool => $insp->fresh()?->status === \App\Modules\Quality\Enums\InspectionStatus::AwaitingReview,
                );
                if (! $failureAwaitsReview) {
                    $grn = $this->rejectInternal(
                        $grn,
                        $qcData['failure_reason'] ?? 'QC inspection failed',
                        $by
                    );
                }
            }
            // If 'pending', leave GRN in pending_qc status for later decision

            $result = [
                'grn' => $this->show($grn->fresh()),
                'inspection' => $inspection,
                'qc_result' => $qcResult,
                'disposition' => $disposition,
                'stock_updated' => in_array($qcResult, ['passed', 'passed_with_remarks'], true),
            ];

            if ($idempotencyKey !== null) {
                $grn->forceFill(['idempotency_response' => [
                    'qc_result' => $result['qc_result'],
                    'disposition' => $result['disposition'],
                    'stock_updated' => $result['stock_updated'],
                ]])->save();
            }

            return $result;
        });
    }

    /**
     * Accept GRN internally — moves stock for every line. Used by receiveWithQc()
     * to bypass the public accept() method's QC gate (since we control the flow).
     */
    private function acceptInternal(GoodsReceiptNote $grn, User $by): GoodsReceiptNote
    {
        $grn->refresh();
        $this->assertQcGate($grn);
        $rows = GrnItem::query()
            ->where('goods_receipt_note_id', $grn->id)
            ->lockForUpdate()
            ->get();
        $this->snapshotLandedCosts($grn, $rows);

        foreach ($rows as $row) {
            $delta = bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3);
            if (bccomp($delta, '0', 3) < 0) {
                throw new BusinessRuleException("Accepted quantity exceeds received for line {$row->id}.");
            }
            $row->quantity_accepted = $row->quantity_received;
            $row->save();
            $poItem = PurchaseOrderItem::query()->whereKey($row->purchase_order_item_id)->lockForUpdate()->firstOrFail();
            $poItem->quantity_accepted = bcadd((string) $poItem->quantity_accepted, $delta, 3);
            $poItem->save();
            $this->moveAcceptedQuantity($row, $delta, $by, "GRN {$grn->grn_number}");
        }
        $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($grn->purchase_order_id);
        $this->refreshPoStatus($po, $by);
        $grn->update([
            'status' => GrnStatus::Accepted,
            'accepted_by' => $by->id,
            'accepted_at' => now(),
        ]);

        $fresh = $grn->fresh();
        // The consolidated receive+QC path must obey the same inventory/GL
        // invariant as the standalone accept endpoint.
        $this->gl->post($fresh);
        $fresh = $fresh->fresh();
        app(OutboxService::class)->recordForChain(
            new GoodsReceiptNoteAccepted($fresh),
            $fresh,
            'p2p',
            'grn',
            GrnStatus::Accepted->value,
        );
        app(ChainBroadcaster::class)
            ->broadcastFor($fresh, GrnStatus::Accepted->value, $by);

        return $fresh;
    }

    /**
     * Reject GRN internally — marks as rejected without stock movement.
     */
    private function rejectInternal(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        $this->reversePoReceipt($grn, $by);
        $grn->update([
            'status' => GrnStatus::Rejected,
            'rejected_reason' => $reason,
            'accepted_by' => $by->id,
            'accepted_at' => now(),
        ]);

        $fresh = $grn->fresh();
        $this->openSupplierReturnForRejectedGrn($fresh, $by, $reason);
        app(ChainBroadcaster::class)
            ->broadcastFor($fresh, GrnStatus::Rejected->value, $by);

        return $fresh;
    }

    /**
     * Reverse the pre-QC PO receipt created by create()/finalizeDraft().
     *
     * The PO running total represents physically received goods, so a GRN
     * rejection must remove exactly this GRN's quantities. Locks prevent a
     * concurrent receipt or return from producing a negative or stale total.
     */
    private function reversePoReceipt(GoodsReceiptNote $grn, ?User $by = null): void
    {
        $po = PurchaseOrder::query()
            ->whereKey($grn->purchase_order_id)
            ->lockForUpdate()
            ->firstOrFail();
        $rows = GrnItem::query()
            ->where('goods_receipt_note_id', $grn->id)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $poItem = PurchaseOrderItem::query()->whereKey($row->purchase_order_item_id)->lockForUpdate()->firstOrFail();
            if (bccomp((string) $poItem->quantity_received, (string) $row->quantity_received, 3) < 0) {
                throw new BusinessRuleException(
                    "Cannot reject GRN {$grn->grn_number}: PO line {$poItem->id} is already below the receipt quantity."
                );
            }
            $poItem->quantity_received = bcsub(
                (string) $poItem->quantity_received,
                (string) $row->quantity_received,
                3,
            );
            $accepted = min((float) $poItem->quantity_accepted, (float) $row->quantity_accepted);
            $poItem->quantity_accepted = bcsub((string) $poItem->quantity_accepted, number_format($accepted, 3, '.', ''), 3);
            $poItem->save();
        }

        $zeroStatus = $po->sent_to_supplier_at
            ? PurchaseOrderStatus::Sent
            : PurchaseOrderStatus::Approved;
        $this->refreshPoStatus($po, $by, $zeroStatus);
    }

    /** Move only a newly accepted delta into inventory. */
    private function moveAcceptedQuantity(GrnItem $row, string $quantity, User $by, string $remarks): void
    {
        if (bccomp($quantity, '0', 3) <= 0) {
            return;
        }
        $locationId = $this->resolveReceivingLocation($row->location_id);
        $mvmt = $this->movements->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $row->item_id,
            fromLocationId: null,
            toLocationId: $locationId,
            quantity: $quantity,
            unitCost: $this->effectiveUnitCost($row),
            referenceType: 'goods_receipt_note',
            referenceId: $row->goods_receipt_note_id,
            remarks: $remarks,
            createdBy: $by->id,
            lotNumber: $row->material_lot_number,
            expiryDate: $row->expiry_date?->toDateString(),
        ));
    }

    /**
     * Freeze each GRN line's share of the shipment allocation before the first
     * accepted quantity moves. The snapshot is based on physically received
     * quantity, so later partial acceptance uses the same unit cost and only
     * posts the newly accepted delta.
     */
    private function snapshotLandedCosts(GoodsReceiptNote $grn, Collection $rows): void
    {
        if ($rows->every(static fn (GrnItem $row): bool => $row->landed_cost_total !== null)) {
            return;
        }

        $allocations = $grn->shipment_id
            ? ShipmentLandedCost::query()
                ->where('shipment_id', $grn->shipment_id)
                ->pluck('total_allocated', 'purchase_order_item_id')
            : collect();

        foreach ($rows->groupBy('purchase_order_item_id') as $purchaseOrderItemId => $lineRows) {
            $allocation = Money::round2((string) ($allocations[$purchaseOrderItemId] ?? '0'));
            $receivedTotal = '0';
            foreach ($lineRows as $line) {
                $receivedTotal = bcadd($receivedTotal, (string) $line->quantity_received, 8);
            }

            $remaining = $allocation;
            $lastIndex = $lineRows->count() - 1;
            foreach ($lineRows->values() as $index => $line) {
                $lineCost = $index === $lastIndex || bccomp($receivedTotal, '0', 8) === 0
                    ? $remaining
                    : Money::round2(bcmul(
                        $allocation,
                        bcdiv((string) $line->quantity_received, $receivedTotal, 12),
                        6,
                    ));
                $remaining = Money::sub($remaining, $lineCost);
                $unit = bccomp((string) $line->quantity_received, '0', 8) > 0
                    ? $this->round4(bcdiv($lineCost, (string) $line->quantity_received, 8))
                    : '0.0000';

                $line->forceFill([
                    'landed_cost_unit' => $unit,
                    'landed_cost_total' => $lineCost,
                ])->save();
            }
        }
    }

    private function effectiveUnitCost(GrnItem $row): string
    {
        return bcadd(
            (string) $row->unit_cost,
            (string) ($row->landed_cost_unit ?? '0.0000'),
            4,
        );
    }

    private function round4(string $value): string
    {
        $negative = bccomp($value, '0', 8) < 0;
        $absolute = $negative ? ltrim($value, '-') : $value;
        $rounded = bcadd($absolute, '0.00005', 4);

        return $negative ? bcmul($rounded, '-1', 4) : $rounded;
    }

    /**
     * Resolve a destination used by receiving and enforce its lifecycle state.
     * Soft-deleted locations are excluded by the model query; inactive and
     * blocked locations must also be rejected for direct service callers.
     */
    private function receivedBaseQuantity(
        PurchaseOrderItem $purchaseOrderItem,
        string $receivedQuantity,
        int $itemId,
        ?string $receivedUomCode,
    ): string {
        if (! is_numeric($receivedQuantity) || bccomp($receivedQuantity, '0', 3) <= 0) {
            throw new BusinessRuleException(
                "PO line {$purchaseOrderItem->id} must have a positive received quantity."
            );
        }

        $item = Item::query()->findOrFail($itemId);
        if ($receivedUomCode !== null && trim($receivedUomCode) !== '') {
            $receivedQuantity = $item->convertToBase($receivedQuantity, $receivedUomCode);
        }
        if (! is_numeric($receivedQuantity) || bccomp($receivedQuantity, '0', 3) <= 0) {
            throw new BusinessRuleException(
                "PO line {$purchaseOrderItem->id} converts to a non-positive base received quantity."
            );
        }

        $orderedBase = $this->orderedBaseQuantity($purchaseOrderItem, $item);
        $remaining = bcsub($orderedBase, (string) $purchaseOrderItem->quantity_received, 3);
        if (bccomp($receivedQuantity, $remaining, 3) > 0) {
            // OGAMI-014 — configurable tolerance is a percent of ordered qty.
            $tolerancePct = (string) $this->settings->requiredFloat('inventory.over_receipt_tolerance_pct', 0);
            $allowance = bcmul($orderedBase, bcdiv($tolerancePct, '100', 6), 3);
            $maxReceivable = bcadd($remaining, $allowance, 3);
            if (bccomp($receivedQuantity, $maxReceivable, 3) > 0) {
                throw new BusinessRuleException(
                    "Cannot receive {$receivedQuantity} for PO line {$purchaseOrderItem->id}: only {$remaining} remaining"
                    .($tolerancePct !== '0' ? " (tolerance {$tolerancePct}% → max {$maxReceivable})" : '').'.'
                );
            }
        }

        return $receivedQuantity;
    }

    private function orderedBaseQuantity(PurchaseOrderItem $line, ?Item $item = null): string
    {
        $item ??= Item::withTrashed()->findOrFail($line->item_id);

        return $item->convertToBase((string) $line->quantity, trim((string) $line->unit) ?: null);
    }

    private function baseUnitCost(PurchaseOrderItem $line, string $purchaseUnitCost): string
    {
        $orderedBase = $this->orderedBaseQuantity($line);
        if (bccomp($orderedBase, '0', 6) <= 0) {
            throw new BusinessRuleException("PO line {$line->id} must have a positive base ordered quantity.");
        }

        return $this->round4(bcdiv(bcmul($purchaseUnitCost, (string) $line->quantity, 8), $orderedBase, 8));
    }

    private function resolveReceivingLocation(mixed $value): int
    {
        $locationId = HashIdFilter::decode($value, WarehouseLocation::class);
        if ($locationId === null) {
            throw new BusinessRuleException('A valid receiving location is required.');
        }

        $location = WarehouseLocation::query()
            ->with('zone.warehouse')
            ->whereKey($locationId)
            ->first();
        if (! $location || ! $location->zone || ! $location->zone->warehouse) {
            throw new BusinessRuleException(
                "Receiving location {$locationId} does not exist or has been removed."
            );
        }
        if (! (bool) $location->is_active) {
            throw new BusinessRuleException("Receiving location {$location->code} is inactive.");
        }
        if ((bool) $location->is_blocked) {
            throw new BusinessRuleException("Receiving location {$location->code} is blocked.");
        }
        if (! (bool) $location->zone->warehouse->is_active) {
            throw new BusinessRuleException(
                "Receiving warehouse {$location->zone->warehouse->code} is inactive."
            );
        }

        $zoneType = $location->zone->zone_type instanceof WarehouseZoneType
            ? $location->zone->zone_type->value
            : (string) $location->zone->zone_type;
        if (in_array($zoneType, [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value], true)) {
            throw new BusinessRuleException(
                "Receiving location {$location->code} is in a {$zoneType} zone and cannot receive normal goods."
            );
        }

        return (int) $location->id;
    }

    /**
     * Fast-complete an inspection created inline during receiveWithQc().
     * Sets all measurement rows to is_pass = $passed and finalises status
     * so that the QC gate and downstream events fire correctly.
     *
     * When $isQualityFailure is false (a logistics rejection — e.g. wrong
     * part number, short shipment) the inspection is cancelled instead of
     * force-completed as failed. This prevents InspectionService::complete()
     * from triggering the transactional NcrService::openFromInspectionFailure()
     * path, which would pollute the NCR queue with non-quality events.
     *
     * @param  bool  $isQualityFailure  true (default) = genuine QC failure,
     *                                  NCR auto-created; false = logistics /
     *                                  non-quality reason, no NCR created.
     */
    private function fastCompleteInspection(
        Inspection $inspection,
        bool $passed,
        User $by,
        bool $isQualityFailure = true,
    ): void {
        $svc = $this->resolveInspectionService();
        if (! $svc) {
            return;
        }

        // Logistics rejection: cancel the inspection so that complete() is
        // never called and no NCR is auto-opened (P3.6 audit fix).
        if (! $passed && ! $isQualityFailure) {
            $svc->cancel($inspection, 'Logistics rejection — no quality issue found', $by);

            return;
        }

        // Fill all measurement rows with the verdict so complete() won't
        // complain about unresolved measurements.
        $rows = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->get();

        $patches = [];
        foreach ($rows as $m) {
            $patches[$m->id] = ['is_pass' => $passed];
        }
        if (! empty($patches)) {
            $svc->recordMeasurements($inspection, $patches, $by);
        }

        // For lot_checklist inspections, set sample_defect_count = 0 (the
        // single-screen receiveWithQc path confirms all pieces; no defects found).
        $inspection = $inspection->fresh();
        if ($inspection->inspection_mode && $inspection->inspection_mode->value === 'lot_checklist') {
            $inspection->forceFill(['sample_defect_count' => 0])->save();
            $inspection = $inspection->fresh();
        }

        $svc->complete($inspection, $by);
    }

    /**
     * Resolve the InspectionService if the Quality module is available.
     */
    private function resolveInspectionService(): ?InspectionService
    {
        $cls = '\\App\\Modules\\Quality\\Services\\InspectionService';

        return class_exists($cls) ? app($cls) : null;
    }

    private function refreshPoStatus(
        PurchaseOrder $po,
        ?User $by = null,
        ?PurchaseOrderStatus $zeroStatus = null,
    ): void
    {
        $previousStatus = $po->status instanceof PurchaseOrderStatus
            ? $po->status->value
            : (string) $po->status;

        $po->load(['items.item' => fn ($q) => $q->withTrashed()]);
        $allReceived = $po->items->isNotEmpty() && $po->items->every(
            fn (PurchaseOrderItem $l) => bccomp(
                (string) $l->quantity_accepted,
                $this->orderedBaseQuantity($l, $l->item),
                3,
            ) >= 0
        );
        $anyReceived = $po->items->contains(
            // Physical receipt is visible to purchasing before QC acceptance.
            // A full Received status still requires accepted quantities above,
            // but any received quantity must move the PO out of Sent.
            fn ($l) => bccomp((string) $l->quantity_received, '0', 3) > 0
        );
        if ($allReceived) {
            $po->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $po->status = PurchaseOrderStatus::PartiallyReceived;
        } elseif ($zeroStatus !== null) {
            $po->status = $zeroStatus;
        }
        $po->save();

        $currentStatus = $po->status instanceof PurchaseOrderStatus
            ? $po->status->value
            : (string) $po->status;

        if ($currentStatus !== $previousStatus) {
            $fresh = $po->fresh();
            if ($fresh) {
                app(ChainBroadcaster::class)->broadcastFor(
                    $fresh,
                    $fresh->status instanceof PurchaseOrderStatus
                        ? $fresh->status->value
                        : (string) $fresh->status,
                    $by,
                );
            }
        }
    }
}
