<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
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
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = GoodsReceiptNote::query()
            ->with(['vendor:id,name', 'purchaseOrder:id,po_number', 'receiver:id,name,role_id']);

        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], \App\Modules\Accounting\Models\Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (! empty($filters['purchase_order_id'])) {
            $pid = HashIdFilter::decode($filters['purchase_order_id'], PurchaseOrder::class);
            if ($pid) $q->where('purchase_order_id', $pid);
        }
        if (! empty($filters['from'])) $q->whereDate('received_date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('received_date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('grn_number', 'ilike', '%'.$filters['search'].'%');
        }

        return $q->orderByDesc('received_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(GoodsReceiptNote $grn): GoodsReceiptNote
    {
        return $grn->load([
            'vendor', 'purchaseOrder',
            'items.item:id,code,name,unit_of_measure',
            'items.location.zone.warehouse',
            'items.purchaseOrderItem',
            'receiver:id,name,role_id', 'acceptor:id,name,role_id',
        ]);
    }

    /**
     * Create a GRN for a PO, in `pending_qc` status. Stock is NOT yet incremented.
     * Stock is increased only when accept() is called (Sprint 7 will gate this on QC).
     *
     * @param array<int, array{purchase_order_item_id:int|string, item_id:int|string, location_id:int|string, quantity_received:string, unit_cost?:string|null, remarks?:string|null}> $items
     */
    public function create(PurchaseOrder $po, array $items, array $meta, User $by): GoodsReceiptNote
    {
        if (! in_array($po->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ], true)) {
            throw new RuntimeException("PO {$po->po_number} is not open for receiving (status={$po->status->value}).");
        }

        return DB::transaction(function () use ($po, $items, $meta, $by) {
            $grn = GoodsReceiptNote::create([
                'grn_number'        => $this->sequences->generate('grn'),
                'purchase_order_id' => $po->id,
                'vendor_id'         => $po->vendor_id,
                'received_date'     => $meta['received_date'] ?? now()->toDateString(),
                'received_by'       => $by->id,
                'status'            => GrnStatus::PendingQc,
                'remarks'           => $meta['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
                $poiId = HashIdFilter::decode($row['purchase_order_item_id'], PurchaseOrderItem::class)
                    ?? (is_int($row['purchase_order_item_id']) ? $row['purchase_order_item_id'] : null);
                $poi = PurchaseOrderItem::query()->whereKey($poiId)->lockForUpdate()->firstOrFail();
                if ($poi->purchase_order_id !== $po->id) {
                    throw new RuntimeException("PO line {$poi->id} does not belong to PO {$po->id}.");
                }

                $locationId = HashIdFilter::decode($row['location_id'], WarehouseLocation::class)
                    ?? (int) $row['location_id'];
                $itemId = HashIdFilter::decode($row['item_id'], \App\Modules\Inventory\Models\Item::class)
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
                if (! empty($row['received_uom_code'])) {
                    $item = \App\Modules\Inventory\Models\Item::query()->findOrFail($itemId);
                    $qtyReceived = $item->convertToBase($qtyReceived, (string) $row['received_uom_code']);
                }

                $remaining = bcsub((string) $poi->quantity, (string) $poi->quantity_received, 3);
                if (bccomp($qtyReceived, $remaining, 3) > 0) {
                    // OGAMI-014 — over-receipt tolerance. Resin sold in full bags/
                    // drums often lands slightly above the ordered quantity; a
                    // configurable tolerance (% of the ORDERED line qty, default 0)
                    // accepts the overage instead of hard-blocking the whole GRN.
                    $tolerancePct = (string) config('inventory.over_receipt_tolerance_pct', '0');
                    $allowance = bcmul((string) $poi->quantity, bcdiv($tolerancePct, '100', 6), 3);
                    $maxReceivable = bcadd($remaining, $allowance, 3);
                    if (bccomp($qtyReceived, $maxReceivable, 3) > 0) {
                        throw new RuntimeException(
                            "Cannot receive {$qtyReceived} for PO line {$poi->id}: only {$remaining} remaining"
                            .($tolerancePct !== '0' ? " (tolerance {$tolerancePct}% → max {$maxReceivable})" : '').'.'
                        );
                    }
                }

                GrnItem::create([
                    'goods_receipt_note_id'  => $grn->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_id'                => $itemId,
                    'location_id'            => $locationId,
                    'quantity_received'      => $qtyReceived,
                    'quantity_accepted'      => 0,
                    'unit_cost'              => $row['unit_cost'] ?? $poi->unit_price,
                    'remarks'                => $row['remarks'] ?? null,
                    // OGAMI-012 — optional lot capture per received line. The
                    // existing ADV3 `material_lot_number` column is the lot of
                    // record; we also persist an optional expiry. Both null-safe.
                    'material_lot_number'    => $row['lot_number'] ?? ($row['material_lot_number'] ?? null),
                    'supplier_lot_reference' => $row['supplier_lot_reference'] ?? null,
                    'expiry_date'            => $row['expiry_date'] ?? null,
                    // OGAMI-005 — IATF incoming resin QC attributes (null-safe).
                    'moisture_percentage'    => $row['moisture_percentage'] ?? null,
                    'coa_document_path'      => $row['coa_document_path'] ?? null,
                    'coa_verified'           => (bool) ($row['coa_verified'] ?? false),
                ]);

                // Update PO line running total of received quantity (base uom).
                $poi->quantity_received = bcadd((string) $poi->quantity_received, $qtyReceived, 3);
                $poi->save();
            }

            $this->refreshPoStatus($po);

            // Series C — Task C2. Domain event for chain listeners
            // (TriggerIncomingQC). Fires after commit so the row is visible.
            DB::afterCommit(fn () =>
                event(new \App\Modules\Inventory\Events\GoodsReceiptNoteCreated($grn->fresh()))
            );

            return $this->show($grn->fresh());
        });
    }

    /** Accept the entire GRN — moves stock for every line at full quantity_received. */
    public function accept(GoodsReceiptNote $grn, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be accepted.');
        }
        $this->assertQcGate($grn);
        return DB::transaction(function () use ($grn, $by) {
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
                // OGAMI-012 — propagate the captured lot/expiry onto the ledger.
                $this->movements->stampLot(
                    $mvmt,
                    $row->material_lot_number,
                    $row->expiry_date?->toDateString(),
                );
            }
            $grn->update([
                'status'      => GrnStatus::Accepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            $fresh = $grn->fresh();

            // Task 5 — post the inventory receipt to the GL (DR Inventory /
            // CR GRNI). Flag-gated, idempotent, and non-blocking: a GL post
            // failure must not abort the GRN acceptance itself.
            try {
                $this->gl->post($fresh);
                $fresh = $fresh->fresh();
            } catch (\Throwable $e) {
                Log::error('GrnService::accept — GL post failed; GRN remains accepted', [
                    'grn_id' => $fresh->id,
                    'error'  => $e->getMessage(),
                ]);
            }

            // Series C — Task C4. Real-time chain progress for the GRN
            // detail page on the SPA.
            DB::afterCommit(fn () =>
                app(\App\Common\Services\ChainBroadcaster::class)
                    ->broadcastFor($fresh, GrnStatus::Accepted->value, $by)
            );
            return $fresh;
        });
    }

    /** Partially accept — caller supplies quantity_accepted per grn_item id. */
    public function partialAccept(GoodsReceiptNote $grn, array $itemAcceptedMap, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be partially accepted.');
        }
        $this->assertQcGate($grn);
        return DB::transaction(function () use ($grn, $itemAcceptedMap, $by) {
            $allFull = true;
            foreach ($grn->items as $row) {
                $accepted = (string) ($itemAcceptedMap[$row->id] ?? '0');
                if (bccomp($accepted, (string) $row->quantity_received, 3) > 0) {
                    throw new RuntimeException("Accepted quantity exceeds received for line {$row->id}.");
                }
                if (bccomp($accepted, (string) $row->quantity_received, 3) < 0) {
                    $allFull = false;
                }
                $row->quantity_accepted = $accepted;
                $row->save();
                if (bccomp($accepted, '0', 3) > 0) {
                    $mvmt = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::GrnReceipt,
                        itemId: $row->item_id,
                        fromLocationId: null,
                        toLocationId: $row->location_id,
                        quantity: $accepted,
                        unitCost: (string) $row->unit_cost,
                        referenceType: 'goods_receipt_note',
                        referenceId: $grn->id,
                        remarks: "GRN {$grn->grn_number} (partial)",
                        createdBy: $by->id,
                    ));
                    $this->movements->stampLot(
                        $mvmt,
                        $row->material_lot_number,
                        $row->expiry_date?->toDateString(),
                    );
                }
            }
            $grn->update([
                'status'      => $allFull ? GrnStatus::Accepted : GrnStatus::PartialAccepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            $fresh = $grn->fresh();

            // Task 5 — post the accepted value to the GL. Same try/catch
            // guard as accept(): GL failures must not abort the GRN.
            try {
                $this->gl->post($fresh);
                $fresh = $fresh->fresh();
            } catch (\Throwable $e) {
                Log::error('GrnService::partialAccept — GL post failed; GRN remains accepted', [
                    'grn_id' => $fresh->id,
                    'error'  => $e->getMessage(),
                ]);
            }

            return $fresh;
        });
    }

    public function reject(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be rejected.');
        }
        $result = DB::transaction(function () use ($grn, $reason, $by) {
            $grn->update([
                'status'          => GrnStatus::Rejected,
                'rejected_reason' => $reason,
                'accepted_by'     => $by->id,
                'accepted_at'     => now(),
            ]);
            return $grn->fresh();
        });

        // Series C — Task C4. Real-time chain progress.
        app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
            $result,
            GrnStatus::Rejected->value,
            $by,
        );

        return $result;
    }

    /**
     * Sprint 7 Task 60 — incoming-QC gate.
     *
     * If the GRN has been linked to an inspection (qc_inspection_id),
     * accepting the GRN requires that inspection to be in `passed` status.
     * GRNs without a linked inspection bypass this gate (back-compat with
     * Sprint 5 flows where QC was not yet enforced).
     */
    private function assertQcGate(GoodsReceiptNote $grn): void
    {
        if (! $grn->qc_inspection_id) return;
        $status = DB::table('inspections')
            ->where('id', $grn->qc_inspection_id)
            ->value('status');
        if ($status !== 'passed') {
            throw new RuntimeException(
                "GRN {$grn->grn_number} cannot be accepted until its incoming inspection passes (current: {$status})."
            );
        }
    }

    /**
     * CA2 — Single-screen receiving. Creates GRN + records QC inspection + accepts/rejects
     * in one atomic transaction, combining what were previously 3 separate API calls.
     *
     * @param  array  $items   Same format as create()
     * @param  array  $meta    ['received_date' => ..., 'remarks' => ...]
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
            // 1. Create GRN (pending_qc)
            $grn = $this->create($po, $items, $meta, $by);

            // 2. Create QC inspection if inspection data provided
            $inspection = null;
            $inspectionService = $this->resolveInspectionService();

            if ($inspectionService && ! empty($qcData)) {
                $inspectorId = null;
                if (! empty($qcData['inspector_id'])) {
                    $inspectorId = HashIdFilter::decode($qcData['inspector_id'], User::class)
                        ?? (ctype_digit((string) $qcData['inspector_id']) ? (int) $qcData['inspector_id'] : null);
                }

                $productId = null;
                if (! empty($qcData['product_id'])) {
                    $productId = HashIdFilter::decode($qcData['product_id'], \App\Modules\CRM\Models\Product::class)
                        ?? (ctype_digit((string) $qcData['product_id']) ? (int) $qcData['product_id'] : null);
                }

                // Use the existing InspectionService::create() which builds
                // measurement scaffolds from the product's inspection spec.
                // This requires a product_id; if one is not supplied, we skip
                // the full inspection record and still process the GRN result.
                if ($productId) {
                    $totalQty = collect($items)->sum(fn ($r) => (float) $r['quantity_received']);
                    $inspector = $inspectorId
                        ? User::query()->findOrFail($inspectorId)
                        : $by;

                    try {
                        $inspection = $inspectionService->create([
                            'stage'          => 'incoming',
                            'product_id'     => $productId,
                            'batch_quantity' => max(1, (int) $totalQty),
                            'entity_type'    => 'grn',
                            'entity_id'      => $grn->id,
                            'notes'          => $qcData['remarks'] ?? null,
                        ], $inspector);

                        // The InspectionService::create() already back-links
                        // qc_inspection_id onto the GRN via DB::table update,
                        // so we reload to pick up the change.
                        $grn->refresh();
                    } catch (RuntimeException) {
                        // No active inspection spec for this product — non-fatal
                        // for the receiving flow. GRN proceeds without QC record.
                    }
                }
            }

            // 3. Based on QC result, accept or leave pending
            $qcResult    = $qcData['result'] ?? 'passed';
            $disposition = null;

            if ($qcResult === 'passed' || $qcResult === 'passed_with_remarks') {
                // If an inspection was created, fast-complete it as passed
                // so the QC gate in acceptInternal() does not block.
                if ($inspection) {
                    $this->fastCompleteInspection($inspection, true, $by);
                }
                $grn = $this->acceptInternal($grn, $by);
            } elseif ($qcResult === 'failed') {
                $disposition = $qcData['disposition'] ?? 'return_to_supplier';
                // Distinguish between a genuine quality failure (triggers NCR)
                // and a logistics rejection such as wrong part number or short
                // shipment (must NOT open an NCR — P3.6 audit fix).
                $isQualityFailure = ($qcData['is_quality_failure'] ?? true) !== false;
                if ($inspection) {
                    $this->fastCompleteInspection($inspection, false, $by, $isQualityFailure);
                }
                $grn = $this->rejectInternal(
                    $grn,
                    $qcData['failure_reason'] ?? 'QC inspection failed',
                    $by
                );
            }
            // If 'pending', leave GRN in pending_qc status for later decision

            return [
                'grn'           => $this->show($grn->fresh()),
                'inspection'    => $inspection,
                'qc_result'     => $qcResult,
                'disposition'   => $disposition,
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
            'status'      => GrnStatus::Accepted,
            'accepted_by' => $by->id,
            'accepted_at' => now(),
        ]);

        $fresh = $grn->fresh();
        DB::afterCommit(fn () =>
            app(\App\Common\Services\ChainBroadcaster::class)
                ->broadcastFor($fresh, GrnStatus::Accepted->value, $by)
        );

        return $fresh;
    }

    /**
     * Reject GRN internally — marks as rejected without stock movement.
     */
    private function rejectInternal(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        $grn->update([
            'status'          => GrnStatus::Rejected,
            'rejected_reason' => $reason,
            'accepted_by'     => $by->id,
            'accepted_at'     => now(),
        ]);

        $fresh = $grn->fresh();
        DB::afterCommit(fn () =>
            app(\App\Common\Services\ChainBroadcaster::class)
                ->broadcastFor($fresh, GrnStatus::Rejected->value, $by)
        );

        return $fresh;
    }

    /**
     * Fast-complete an inspection created inline during receiveWithQc().
     * Sets all measurement rows to is_pass = $passed and finalises status
     * so that the QC gate and downstream events fire correctly.
     *
     * When $isQualityFailure is false (a logistics rejection — e.g. wrong
     * part number, short shipment) the inspection is cancelled instead of
     * force-completed as failed. This prevents InspectionService::complete()
     * from triggering the afterCommit → NcrService::openFromInspectionFailure()
     * path, which would pollute the NCR queue with non-quality events.
     *
     * @param  bool  $isQualityFailure  true (default) = genuine QC failure,
     *                                  NCR auto-created; false = logistics /
     *                                  non-quality reason, no NCR created.
     */
    private function fastCompleteInspection(
        \App\Modules\Quality\Models\Inspection $inspection,
        bool $passed,
        User $by,
        bool $isQualityFailure = true,
    ): void {
        $svc = $this->resolveInspectionService();
        if (! $svc) return;

        // Logistics rejection: cancel the inspection so that complete() is
        // never called and no NCR is auto-opened (P3.6 audit fix).
        if (! $passed && ! $isQualityFailure) {
            $svc->cancel($inspection, 'Logistics rejection — no quality issue found', $by);
            return;
        }

        // Fill all measurement rows with the verdict so complete() won't
        // complain about unresolved measurements.
        $rows = \App\Modules\Quality\Models\InspectionMeasurement::query()
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
    private function resolveInspectionService(): ?\App\Modules\Quality\Services\InspectionService
    {
        $cls = '\\App\\Modules\\Quality\\Services\\InspectionService';
        return class_exists($cls) ? app($cls) : null;
    }

    private function refreshPoStatus(PurchaseOrder $po): void
    {
        $po->load('items');
        $allReceived = $po->items->every(
            fn ($l) => bccomp((string) $l->quantity_received, (string) $l->quantity, 3) >= 0
        );
        $anyReceived = $po->items->contains(
            fn ($l) => bccomp((string) $l->quantity_received, '0', 3) > 0
        );
        if ($allReceived) {
            $po->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $po->status = PurchaseOrderStatus::PartiallyReceived;
        }
        $po->save();
    }
}
