<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
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
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $q->where('grn_number', 'ilike', '%'.$filters['search'].'%');
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
            'items.item' => fn ($item) => $item->select('id', 'code', 'name', 'unit_of_measure')
                ->withExists(['qualityPlans as has_active_quality_plan' => fn ($plan) => $plan->effective()]),
            'items.location.zone.warehouse',
            'items.purchaseOrderItem',
            'receiver:id,name,role_id', 'acceptor:id,name,role_id',
            'bills:id,bill_number,status,total_amount',
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
        if (! in_array($po->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ], true)) {
            throw new BusinessRuleException("PO {$po->po_number} is not open for receiving (status={$po->status->value}).");
        }

        return DB::transaction(function () use ($po, $items, $meta, $by) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, [
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new BusinessRuleException("PO {$po->po_number} is not open for receiving (status={$po->status->value}).");
            }

            $grn = GoodsReceiptNote::create([
                'grn_number' => $this->sequences->generate('grn'),
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'received_date' => $meta['received_date'] ?? now()->toDateString(),
                'received_by' => $by->id,
                'status' => GrnStatus::PendingQc,
                'incoming_qc_handoff_status' => IncomingQcHandoffStatus::NotStarted,
                'incoming_qc_handoff_at' => now(),
                'remarks' => $meta['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
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

                // OGAMI-004 — multi-UOM receiving. If the caller supplies a
                // `received_uom_code` that differs from the item base uom, the
                // received quantity is converted to BASE before it touches the
                // over-receipt check, GrnItem storage, the PO-line running
                // total, and (later, on accept) the stock movement — preserving
                // the base-uom storage invariant. Identity when the code is
                // null or equals the base uom.
                //
                // NOTE: PO lines do not yet carry their own purchase-uom column
                // (owned by the Purchasing module). Capturing the ordered uom on
                // the PO line — and validating that `received_uom_code` is a
                // configured conversion for that line — is a follow-up. Until
                // then the PO quantity is treated as already being in base uom.
                $qtyReceived = (string) $row['quantity_received'];
                if (! is_numeric($qtyReceived) || bccomp($qtyReceived, '0', 3) <= 0) {
                    throw new BusinessRuleException(
                        "PO line {$poi->id} must have a positive received quantity."
                    );
                }
                if (! empty($row['received_uom_code'])) {
                    $item = Item::query()->findOrFail($itemId);
                    $qtyReceived = $item->convertToBase($qtyReceived, (string) $row['received_uom_code']);
                }
                if (! is_numeric($qtyReceived) || bccomp($qtyReceived, '0', 3) <= 0) {
                    throw new BusinessRuleException(
                        "PO line {$poi->id} converts to a non-positive base received quantity."
                    );
                }

                $remaining = bcsub((string) $poi->quantity, (string) $poi->quantity_received, 3);
                if (bccomp($qtyReceived, $remaining, 3) > 0) {
                    // OGAMI-014 — over-receipt tolerance. Resin sold in full bags/
                    // drums often lands slightly above the ordered quantity; a
                    // configurable tolerance (% of the ORDERED line qty, default 0)
                    // accepts the overage instead of hard-blocking the whole GRN.
                    $tolerancePct = (string) $this->settings->requiredFloat('inventory.over_receipt_tolerance_pct', 0);
                    $allowance = bcmul((string) $poi->quantity, bcdiv($tolerancePct, '100', 6), 3);
                    $maxReceivable = bcadd($remaining, $allowance, 3);
                    if (bccomp($qtyReceived, $maxReceivable, 3) > 0) {
                        throw new BusinessRuleException(
                            "Cannot receive {$qtyReceived} for PO line {$poi->id}: only {$remaining} remaining"
                            .($tolerancePct !== '0' ? " (tolerance {$tolerancePct}% → max {$maxReceivable})" : '').'.'
                        );
                    }
                }

                $unitCost = $row['unit_cost'] ?? $poi->unit_price;
                if ($unitCost === null || trim((string) $unitCost) === '') {
                    throw new BusinessRuleException("PO line {$poi->id} has no authoritative unit cost; receive pricing must be recorded first.");
                }

                GrnItem::create([
                    'goods_receipt_note_id' => $grn->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_id' => $itemId,
                    'location_id' => $locationId,
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
                    'coa_verified' => (bool) ($row['coa_verified'] ?? false),
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
                GrnItem::create([
                    'goods_receipt_note_id'  => $grn->id,
                    'purchase_order_item_id' => $line->id,
                    'item_id'                => $line->item_id,
                    'location_id'            => null,
                    'quantity_received'      => '0',
                    'quantity_accepted'      => '0',
                    'unit_cost'              => (string) $line->unit_price,
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
            $lockedGrn->loadMissing('items');
            $draftLineById = $lockedGrn->items->keyBy('purchase_order_item_id');

            foreach ($items as $row) {
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

                $locationId = HashIdFilter::decode($row['location_id'], WarehouseLocation::class)
                    ?? (int) $row['location_id'];
                $qtyReceived = (string) $row['quantity_received'];
                if (! is_numeric($qtyReceived) || bccomp($qtyReceived, '0', 3) <= 0) {
                    throw new BusinessRuleException(
                        "PO line {$poi->id} must have a positive received quantity."
                    );
                }

                // Same over-receipt guard as create(): what was already
                // received (from earlier GRNs) caps what this line may take.
                $remaining = bcsub((string) $poi->quantity, (string) $poi->quantity_received, 3);
                if (bccomp($qtyReceived, $remaining, 3) > 0) {
                    $tolerancePct = (string) $this->settings->requiredFloat('inventory.over_receipt_tolerance_pct', 0);
                    $allowance = bcmul((string) $poi->quantity, bcdiv($tolerancePct, '100', 6), 3);
                    $maxReceivable = bcadd($remaining, $allowance, 3);
                    if (bccomp($qtyReceived, $maxReceivable, 3) > 0) {
                        throw new BusinessRuleException(
                            "Cannot receive {$qtyReceived} for PO line {$poi->id}: only {$remaining} remaining"
                            .($tolerancePct !== '0' ? " (tolerance {$tolerancePct}% → max {$maxReceivable})" : '').'.'
                        );
                    }
                }

                $draftLine->update([
                    'location_id'       => $locationId,
                    'quantity_received' => $qtyReceived,
                    'quantity_accepted' => '0',
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

            foreach ($rows as $row) {
                $delta = bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3);
                if (bccomp($delta, '0', 3) < 0) {
                    throw new BusinessRuleException("Accepted quantity exceeds received for line {$row->id}.");
                }
                $row->quantity_accepted = $row->quantity_received;
                $row->save();
                $this->moveAcceptedQuantity($row, $delta, $by, "GRN {$lockedGrn->grn_number}");
            }
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
            $this->assertQcGate($lockedGrn);

            $rows = GrnItem::query()
                ->where('goods_receipt_note_id', $lockedGrn->id)
                ->lockForUpdate()
                ->get();
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
            $fresh = $lockedGrn->fresh();

            $this->gl->post($fresh);
            $fresh = $fresh->fresh();

            if ($allFull) {
                app(OutboxService::class)->recordForChain(
                    new GoodsReceiptNoteAccepted($fresh),
                    $fresh,
                    'p2p',
                    'grn',
                    GrnStatus::Accepted->value,
                );
            }

            app(ChainBroadcaster::class)->broadcastFor(
                $fresh,
                $allFull ? GrnStatus::Accepted->value : GrnStatus::PartialAccepted->value,
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
            $this->reversePoReceipt($lockedGrn, $by);
            $lockedGrn->update([
                'status' => GrnStatus::Rejected,
                'rejected_reason' => $reason,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);

            $fresh = $lockedGrn->fresh();
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, GrnStatus::Rejected->value, $by);

            return $fresh;
        });
        return $result;
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
     */
    private function assertQcGate(GoodsReceiptNote $grn): void
    {
        $statuses = DB::table('inspections')
            ->where('stage', 'incoming')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->pluck('status');
        if ($statuses->isEmpty() && ! $grn->qc_inspection_id) {
            if ($this->hasQcEligibleLines($grn)) {
                throw new RuntimeException(
                    "GRN {$grn->grn_number} has no incoming inspection records; "
                    .'incoming QC must be completed before acceptance.'
                );
            }
            return;
        }
        if ($statuses->isEmpty()) {
            $statuses = collect([DB::table('inspections')
                ->where('stage', 'incoming')
                ->where('id', $grn->qc_inspection_id)
                ->value('status')]);
        }
        // F-12 — a cancelled inspection (logistics rejection, P3.6) is a
        // completed decision; it must not block acceptance forever.
        $blocking = $statuses->first(fn ($status) => ! in_array($status, ['passed', 'cancelled'], true));
        if ($blocking !== null) {
            throw new RuntimeException(
                "GRN {$grn->grn_number} cannot be accepted until every incoming inspection passes (current: {$blocking})."
            );
        }
    }

    private function hasQcEligibleLines(GoodsReceiptNote $grn): bool
    {
        $grn->loadMissing('items.item');
        foreach ($grn->items as $line) {
            if (! $line->item) {
                continue;
            }
            if ($line->item->item_type === ItemType::RawMaterial) {
                return true;
            }
            if ($line->item->qualityPlans()->effective(now()->toDateString())->exists()) {
                return true;
            }
        }
        return false;
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
        return DB::transaction(function () use ($po, $items, $meta, $qcData, $by) {
            if (
                in_array($qcData['result'] ?? null, ['passed', 'passed_with_remarks', 'failed'], true)
                && ! $by->hasPermission('quality.inspections.manage')
            ) {
                throw new BusinessRuleException(
                    'The quality.inspections.manage permission is required to submit a terminal QC result.'
                );
            }

            // 1. Create GRN (pending_qc)
            $grn = $this->create($po, $items, $meta, $by);

            // 2. Create QC inspection if inspection data provided
            $inspection = null;
            $inspectionService = $this->resolveInspectionService();

            // F-06 — GrnService::create() now creates the incoming inspections
            // synchronously. If they already exist (the normal case), reuse
            // them and apply the operator verdict to all of them instead of
            // double-creating (which trips the per-GRN unique constraint).
            $existingInspections = $inspectionService
                ? Inspection::query()
                    ->where('entity_type', 'grn')
                    ->where('entity_id', $grn->id)
                    ->get()
                : collect();

            if ($existingInspections->isNotEmpty()) {
                // The single-screen verdict lands on the first inspection of
                // record; the fast-complete loop below applies it to all.
                $inspection = $existingInspections->first();
            }

            if ($inspectionService && ! empty($qcData) && $existingInspections->isEmpty()) {
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
                    $totalQty = collect($items)->sum(fn ($r) => (float) $r['quantity_received']);
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
                                (int) $totalQty,
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
                            (int) collect($items)->sum(fn ($row) => (float) $row['quantity_received']),
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
                $grn = $this->acceptInternal($grn, $by);
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
                $grn = $this->rejectInternal(
                    $grn,
                    $qcData['failure_reason'] ?? 'QC inspection failed',
                    $by
                );
            }
            // If 'pending', leave GRN in pending_qc status for later decision

            return [
                'grn' => $this->show($grn->fresh()),
                'inspection' => $inspection,
                'qc_result' => $qcResult,
                'disposition' => $disposition,
                'stock_updated' => in_array($qcResult, ['passed', 'passed_with_remarks'], true),
            ];
        });
    }

    /**
     * Accept GRN internally — moves stock for every line. Used by receiveWithQc()
     * to bypass the public accept() method's QC gate (since we control the flow).
     */
    private function acceptInternal(GoodsReceiptNote $grn, User $by): GoodsReceiptNote
    {
        foreach ($grn->items as $row) {
            $row->quantity_accepted = $row->quantity_received;
            $row->save();
            $mvmt = $this->movements->move(new StockMovementInput(
                type: StockMovementType::GrnReceipt,
                itemId: $row->item_id,
                fromLocationId: null,
                toLocationId: $row->location_id,
                quantity: (string) $row->quantity_received,
                unitCost: (string) $row->unit_cost,
                referenceType: 'goods_receipt_note',
                referenceId: $grn->id,
                remarks: "GRN {$grn->grn_number}",
                createdBy: $by->id,
            ));
            $this->movements->stampLot(
                $mvmt,
                $row->material_lot_number,
                $row->expiry_date?->toDateString(),
            );
        }
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
        $mvmt = $this->movements->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $row->item_id,
            fromLocationId: null,
            toLocationId: $row->location_id,
            quantity: $quantity,
            unitCost: (string) $row->unit_cost,
            referenceType: 'goods_receipt_note',
            referenceId: $row->goods_receipt_note_id,
            remarks: $remarks,
            createdBy: $by->id,
        ));
        // OGAMI-012 — propagate the captured lot/expiry onto the ledger.
        $this->movements->stampLot(
            $mvmt,
            $row->material_lot_number,
            $row->expiry_date?->toDateString(),
        );
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

        $svc->complete($inspection->fresh(), $by);
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

        $po->load('items');
        $allReceived = $po->items->isNotEmpty() && $po->items->every(
            fn ($l) => bccomp((string) $l->quantity_received, (string) $l->quantity, 3) >= 0
        );
        $anyReceived = $po->items->contains(
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
