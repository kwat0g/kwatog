<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\ApprovalService;
use App\Common\Services\BusinessPolicyService;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Events\PurchaseOrderSubmitted;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Quality\Services\PpapService;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ApprovalService $approvals,
        private readonly BusinessPolicyService $businessPolicy,
        private readonly BudgetEnforcementService $budget,
        private readonly TaxPolicyService $taxPolicy,
        private readonly SettingsService $settings,
        private readonly SupplierDispatchService $supplierDispatches,
        private readonly PurchaseOrderAccessPolicy $visibility,
        private readonly VendorSourcingService $sourcing,
    ) {}

    private function resolveDepartmentId(array $data): ?int
    {
        if (! empty($data['purchase_request_id'])) {
            $prId = is_int($data['purchase_request_id'])
                ? $data['purchase_request_id']
                : HashIdFilter::decode($data['purchase_request_id'], PurchaseRequest::class);
            if ($prId) {
                $deptId = PurchaseRequest::find($prId)?->department_id;

                return $deptId !== null ? (int) $deptId : null;
            }
        }

        return null;
    }

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = PurchaseOrder::query()->with([
            'vendor:id,name', 'creator:id,name,role_id',
            'purchaseRequest:id,pr_number',
            'approvalRecords',
            'supplierDispatch',
            'latestResponse.items',
        ])->withExists(['goodsReceiptNotes as has_accepted_receipt' => fn ($q) => $q->whereIn('status', GrnStatus::billableValues())]);
        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vid) {
                $q->where('vendor_id', $vid);
            }
        }
        if (isset($filters['requires_vp_approval']) && $filters['requires_vp_approval'] !== '') {
            $q->where('requires_vp_approval', filter_var($filters['requires_vp_approval'], FILTER_VALIDATE_BOOLEAN));
        }
        if (filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // The committed date is the supplier's confirmed date when present,
            // otherwise OGAMI's required date. Open = the enum's single set, so
            // new supplier-response states stay counted as overdue.
            $q->whereRaw(
                'COALESCE(confirmed_delivery_date, expected_delivery_date) < ?',
                [now()->toDateString()],
            )->whereIn('status', PurchaseOrderStatus::open());
        }
        if (! empty($filters['from'])) {
            $q->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $q->where('po_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }

        // Row-level filtering. Admin and Purchasing approvers see everything.
        // Department Head sees POs for their department via the linked PR.
        // Everyone else sees only POs they created. The rule lives in
        // PurchaseOrderAccessPolicy so global search and the approval board
        // can never drift from it again.
        if ($user) {
            $q = $this->visibility->visibleTo($q, $user);
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseOrder $po): PurchaseOrder
    {
        return $po
            ->load([
                'vendor', 'purchaseRequest:id,pr_number', 'rfq:id,rfq_number,status',
                'items.item:id,code,name,unit_of_measure', 'items.rfqAward', 'items.supplierQuoteVersion',
                'approvalRecords.approver:id,name',
                'goodsReceiptNotes:id,grn_number,received_date,status,purchase_order_id',
                'latestResponse.items', 'pendingChangeResponse.items',
                // Every column PurchaseOrderResource reads off a bill must be in this
                // projection. `has_variances`, `three_way_overridden`,
                // `three_way_match_snapshot` (via Bill::threeWayReviewStatus()) and
                // `due_date` used to be omitted, and because
                // preventAccessingMissingAttributes() is deliberately OFF
                // (AppServiceProvider) an unselected column reads as null instead of
                // throwing: `(bool) null` is false, and threeWayReviewStatus() fell
                // through to 'matched'. The PO detail page therefore showed a green
                // "Matched"/"Matched within tolerance" for a bill whose three-way
                // match was actually BLOCKED, and the three variance branches in
                // detail.tsx had never once rendered.
                'bills:id,bill_number,total_amount,balance,status,purchase_order_id,due_date,has_variances,three_way_overridden,three_way_match_snapshot',
                'supplierDispatch',
                'creator:id,name,role_id', 'approver:id,name,role_id',
            ])
            ->loadExists(['goodsReceiptNotes as has_accepted_receipt' => fn ($q) => $q->whereIn('status', GrnStatus::billableValues())]);
    }

    /**
     * Create a PO sourced from an approved PR. POs must trace back to a PR
     * (PR → approved → PO). System-generated POs (AutoPurchaseOrderService
     * critical shortages, supplier-return replacement POs) pass
     * $systemGenerated = true and are marked is_auto_generated — the same
     * documented bypass the auto-PO path already uses.
     */
    public function create(array $data, User $by, bool $systemGenerated = false, bool $completeConversion = false): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $by, $systemGenerated, $completeConversion) {
            // System-generated orders may be standalone (critical-stock or
            // replacement POs) or may still be sourced from a PR (the approved
            // PR auto-converter). Preserve a supplied source link in both
            // cases; only the manual path requires the PR to be approved here.
            $prId = ! empty($data['purchase_request_id'])
                ? (is_int($data['purchase_request_id'])
                    ? $data['purchase_request_id']
                    : HashIdFilter::decode($data['purchase_request_id'], PurchaseRequest::class))
                : null;
            if (! $systemGenerated && $prId === null) {
                throw new BusinessRuleException('A purchase order must be created from a purchase request (PR).');
            }

            // Serialize the source row before validating line provenance. The
            // PR intentionally permits multiple POs by vendor, so the source
            // row is the idempotency boundary as well as the provenance
            // authority.
            $sourcePr = $prId === null ? null : PurchaseRequest::query()
                ->lockForUpdate()
                ->with('items')
                ->find($prId);
            if ($prId !== null && (! $sourcePr || (! $systemGenerated && $sourcePr->status !== PurchaseRequestStatus::Approved))) {
                throw new BusinessRuleException('Only approved purchase requests can be converted to purchase orders.');
            }
            // Only a live sourcing event holds the PR. An awarded or cancelled
            // RFQ hands whatever it did not cover back to the Direct PO path.
            if ($prId !== null && ! $systemGenerated && empty($data['request_for_quote_id']) && $sourcePr?->rfqs()->whereIn('status', RfqStatus::active())->exists()) {
                throw new BusinessRuleException('This purchase request is already committed to an active RFQ.');
            }

            $vendorId = HashIdFilter::decode($data['vendor_id'], Vendor::class)
                ?? (int) $data['vendor_id'];
            $this->assertVendorPurchasable($vendorId);
            $isVatable = (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered());

            [$lines, $subtotal] = $this->normalizeLines($data['items'] ?? [], $sourcePr);
            if ($sourcePr !== null) {
                $this->assertPrLineQuantityCoverage($sourcePr, $lines);
            }
            $rfqCommercial = is_array($data['rfq_commercial'] ?? null) ? $data['rfq_commercial'] : null;
            if ($rfqCommercial !== null) {
                $subtotal = Money::add(
                    $subtotal,
                    (string) ($rfqCommercial['freight_amount'] ?? '0'),
                    (string) ($rfqCommercial['other_charges'] ?? '0'),
                );
                $isVatable = false;
            }
            $vat = $rfqCommercial !== null
                ? (string) ($rfqCommercial['vat_amount'] ?? '0')
                : ($isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero());
            $total = Money::add($subtotal, $vat);
            $threshold = $this->businessPolicy->purchaseOrderVpThreshold();

            $deptId = $this->resolveDepartmentId($data);

            $po = PurchaseOrder::create([
                'po_number' => $this->sequences->generate('purchase_order'),
                'vendor_id' => $vendorId,
                'purchase_request_id' => $prId,
                'request_for_quote_id' => $data['request_for_quote_id'] ?? null,
                'is_auto_generated' => $systemGenerated,
                'date' => $data['date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'is_vatable' => $isVatable,
                'rfq_vat_amount' => $rfqCommercial['vat_amount'] ?? null,
                'rfq_freight_amount' => $rfqCommercial['freight_amount'] ?? null,
                'rfq_other_charges' => $rfqCommercial['other_charges'] ?? null,
                'requires_vp_approval' => (float) $total >= $threshold,
                'created_by' => $by->id,
                'remarks' => $data['remarks'] ?? null,
                'incoterm' => $data['incoterm'] ?? null,
            ]);
            // status is non-fillable; service-only.
            $po->forceFill(['status' => PurchaseOrderStatus::Draft])->save();
            if ($deptId) {
                $this->budget->assess($po, $deptId, (string) $total);
            }
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $po->id]));
            }

            // A manual PO created from an approved PR counts as that PR's
            // conversion — but only the lines it actually covers. Reconciling
            // coverage (rather than blindly flipping the PR to `converted`) is
            // what stops a manual PO that omitted some lines from permanently
            // stranding them: the PR lands on `partial` and the remainder stays
            // convertible. convertFromPr() reconciles the same way itself, so it
            // passes this flag as false.
            if ($completeConversion && $sourcePr !== null) {
                $this->syncConversionStatus($sourcePr);
            }

            return $this->show($po);
        });
    }

    /**
     * Convert an approved PR into one or more POs (grouped by vendor).
     *
     * Partial conversion (2026-09-11): `$vendorMap` is `{ pr_item_id => vendor_id }`
     * for the lines being converted. Lines omitted from the map (or already on a
     * live PO) are left untouched, so a PR whose lines span suppliers that are
     * only partly known converts what it can and moves to `partial`. When every
     * line ends up covered it becomes `converted` as before.
     */
    public function convertFromPr(PurchaseRequest $pr, array $vendorMap, User $by, bool $systemGenerated = false, ?string $expectedDeliveryDate = null): array
    {
        return DB::transaction(function () use ($pr, $vendorMap, $by, $systemGenerated, $expectedDeliveryDate): array {
            // The queued auto-converter and the manual conversion endpoint can
            // receive the same approved PR at the same time. Lock the PR before
            // reading its status or creating any PO. The PR intentionally allows
            // multiple POs (one per vendor), so a unique index cannot protect
            // this boundary.
            $lockedPr = PurchaseRequest::query()
                ->lockForUpdate()
                ->find($pr->id);
            if (! $lockedPr) {
                throw new BusinessRuleException('The purchase request no longer exists.');
            }

            $livePos = $lockedPr->purchaseOrders()
                ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                ->withoutTrashed()
                ->get();

            // A retry after the first conversion committed is an idempotent
            // read, not a second conversion. The approved+live-PO case covers
            // legacy/manual rows that predate this lock as well.
            if ($lockedPr->status === PurchaseRequestStatus::Converted && $livePos->isNotEmpty()) {
                return $livePos->all();
            }
            if ($lockedPr->status !== PurchaseRequestStatus::Approved) {
                throw new BusinessRuleException('Only approved PRs can be converted to POs.');
            }
            if (! $systemGenerated
                && $lockedPr->sourcing_method !== PurchaseRequestSourcingMethod::DirectPo
                && ! $lockedPr->rfqHandedBack()) {
                throw new BusinessRuleException('This purchase request is not marked for direct PO sourcing.');
            }

            // Lines whose requested quantity is already fully ordered on live
            // POs are done. Lines with remaining uncovered quantity can still
            // be ordered up to the unfulfilled remainder.
            $orderedByLine = $this->orderedQuantitiesByPrLine($lockedPr);
            $fullyCovered = $this->fullyCoveredPrLineIds($lockedPr);
            $coveredLineIds = $fullyCovered === [] ? [] : array_flip($fullyCovered);

            $lockedPr->load('items');
            $byVendor = [];
            foreach ($lockedPr->items as $line) {
                if (isset($coveredLineIds[$line->id])) {
                    continue;
                }
                $vendorId = $vendorMap[$line->id] ?? null;
                if (! $vendorId) {
                    // No vendor supplied for this line → leave it for later.
                    continue;
                }
                $byVendor[$vendorId][] = $line;
            }

            if ($byVendor === []) {
                // Nothing new to create. If uncovered lines remain, the caller
                // supplied no vendor for any of them — refuse with a named line
                // (never the raw PK) rather than silently no-op'ing. Only a PR
                // whose every line is already on a live PO falls through to the
                // idempotent return.
                $uncovered = $lockedPr->items->reject(
                    static fn ($line): bool => isset($coveredLineIds[$line->id]),
                );
                if ($uncovered->isNotEmpty()) {
                    $first = $uncovered->first();
                    throw new BusinessRuleException(
                        'PR line "'.($first->description ?? 'unnamed').'" has no vendor assignment.',
                    );
                }

                $this->syncConversionStatus($lockedPr);

                return $this->livePurchaseOrders($lockedPr);
            }

            $created = [];
            foreach ($byVendor as $vendorId => $lines) {
                if (! Vendor::query()->whereKey($vendorId)->exists()) {
                    throw new BusinessRuleException('A selected vendor for this purchase request no longer exists.');
                }
                $itemPayload = [];
                foreach ($lines as $line) {
                    // The supplier's qualified price is authoritative; the PR
                    // estimate is a budget number. Fall back to the estimate
                    // only when no qualified price is known.
                    $unitPrice = $line->item_id
                        ? ($this->sourcing->priceFor((int) $line->item_id, (int) $vendorId) ?? $line->estimated_unit_price)
                        : $line->estimated_unit_price;
                    if ($unitPrice === null || Money::lte((string) $unitPrice, '0')) {
                        throw new BusinessRuleException('PR line "'.($line->description ?? 'unnamed').'" has no authoritative unit price.');
                    }
                    $orderedBase = (string) ($orderedByLine[$line->id] ?? '0');
                    $quantityToOrder = (string) $line->quantity;
                    if (bccomp($orderedBase, '0', 6) > 0) {
                        $factor = $this->baseQuantityForPrLine($line, '1', $line->unit);
                        $remainingBase = bcsub(
                            $this->baseQuantityForPrLine($line, (string) $line->quantity, $line->unit),
                            $orderedBase,
                            6,
                        );
                        $quantityToOrder = bcdiv($remainingBase, $factor, 3);
                        if (bccomp($quantityToOrder, '0', 3) <= 0
                            || bccomp($this->baseQuantityForPrLine($line, $quantityToOrder, $line->unit), $remainingBase, 6) !== 0) {
                            throw new BusinessRuleException('Remaining PR quantity cannot be represented exactly in the requested unit; adjust the PR unit or quantity.');
                        }
                    }
                    $itemPayload[] = [
                        'item_id' => $line->item_id,
                        'purchase_request_item_id' => $line->id,
                        'description' => $line->description,
                        'quantity' => $quantityToOrder,
                        'unit' => $line->unit,
                        'unit_price' => (string) $unitPrice,
                    ];
                }
                // Derive expected delivery date: use the provided value if given, otherwise
                // fall back to the PR's required_delivery_date. Never set a date in the past;
                // use today instead if needed (supplier cannot deliver retroactively).
                $finalDeliveryDate = $expectedDeliveryDate ?? $lockedPr->required_delivery_date?->toDateString();
                if ($finalDeliveryDate !== null && $finalDeliveryDate < now()->toDateString()) {
                    $finalDeliveryDate = now()->toDateString();
                }

                $po = $this->create([
                    'vendor_id' => $vendorId,
                    'date' => now()->toDateString(),
                    'expected_delivery_date' => $finalDeliveryDate,
                    'is_vatable' => $this->taxPolicy->isVatRegistered(),
                    'remarks' => "Auto-converted from PR {$lockedPr->pr_number}",
                    'items' => $itemPayload,
                    'purchase_request_id' => $lockedPr->id,
                ], $by, $systemGenerated);
                $created[] = $po;
            }

            $this->syncConversionStatus($lockedPr);

            return $created;
        });
    }

    /** @return array<int, string> Map of purchase_request_item_id => total ordered in base UoM */
    private function orderedQuantitiesByPrLine(PurchaseRequest $pr, ?int $exceptPurchaseOrderId = null): array
    {
        $pr->loadMissing('items.item');
        $sourceLines = $pr->items->keyBy('id');
        $query = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.purchase_request_id', $pr->id)
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNotNull('purchase_order_items.purchase_request_item_id');
        if ($exceptPurchaseOrderId !== null) {
            $query->where('purchase_orders.id', '!=', $exceptPurchaseOrderId);
        }

        $ordered = [];
        foreach ($query->get(['purchase_order_items.purchase_request_item_id', 'purchase_order_items.quantity', 'purchase_order_items.unit']) as $poLine) {
            $sourceLine = $sourceLines->get($poLine->purchase_request_item_id);
            if ($sourceLine === null) {
                throw new BusinessRuleException('A PO line refers to a missing purchase-request line.');
            }
            $id = (int) $sourceLine->id;
            $ordered[$id] = bcadd(
                $ordered[$id] ?? '0',
                $this->baseQuantityForPrLine($sourceLine, (string) $poLine->quantity, $poLine->unit),
                6,
            );
        }

        return $ordered;
    }

    private function baseQuantityForPrLine(PurchaseRequestItem $line, string $quantity, ?string $unit): string
    {
        if ($line->item === null) {
            throw new BusinessRuleException('A purchase-request line must reference an item before it can be ordered.');
        }

        return $line->item->convertToBase($quantity, trim((string) $unit) ?: null);
    }

    /** @param array<int, array{purchase_request_item_id:?int, quantity:string, unit:?string}> $lines */
    private function assertPrLineQuantityCoverage(
        PurchaseRequest $pr,
        array $lines,
        ?int $exceptPurchaseOrderId = null,
    ): void {
        $ordered = $this->orderedQuantitiesByPrLine($pr, $exceptPurchaseOrderId);
        $adding = [];
        foreach ($lines as $line) {
            $sourceLineId = $line['purchase_request_item_id'] ?? null;
            if ($sourceLineId !== null) {
                $sourceLine = $pr->items->firstWhere('id', $sourceLineId);
                $adding[$sourceLineId] = bcadd(
                    $adding[$sourceLineId] ?? '0',
                    $this->baseQuantityForPrLine($sourceLine, (string) $line['quantity'], $line['unit']),
                    6,
                );
            }
        }

        foreach ($pr->items as $sourceLine) {
            $sourceLineId = (int) $sourceLine->id;
            if (! isset($adding[$sourceLineId])) {
                continue;
            }

            $total = bcadd((string) ($ordered[$sourceLineId] ?? '0'), $adding[$sourceLineId], 6);
            if (bccomp($total, $this->baseQuantityForPrLine($sourceLine, (string) $sourceLine->quantity, $sourceLine->unit), 6) > 0) {
                throw new BusinessRuleException(
                    'PO quantity for PR line "'.($sourceLine->description ?? 'unnamed').'" would exceed the requested quantity.',
                );
            }
        }
    }

    /** @return list<int> Lines whose ordered quantity meets or exceeds requested quantity */
    private function fullyCoveredPrLineIds(PurchaseRequest $pr): array
    {
        $ordered = $this->orderedQuantitiesByPrLine($pr);
        $fullyCovered = [];
        foreach ($pr->items as $line) {
            $orderedQty = (string) ($ordered[$line->id] ?? '0');
            if (bccomp($orderedQty, '0', 6) <= 0) {
                continue;
            }
            if (bccomp($orderedQty, $this->baseQuantityForPrLine($line, (string) $line->quantity, $line->unit), 6) >= 0) {
                $fullyCovered[] = (int) $line->id;
            }
        }

        return $fullyCovered;
    }

    /**
     * Quantity per PR line (in the line's own unit) not yet on a live PO. An
     * RFQ for a PR that is already partly ordered sources only this remainder.
     *
     * @return array<int, string> purchase_request_item_id => remaining quantity (3 dp, never negative)
     */
    public function remainingQuantitiesByPrLine(PurchaseRequest $pr): array
    {
        $pr->loadMissing('items.item');
        $ordered = $this->orderedQuantitiesByPrLine($pr);
        $remaining = [];
        foreach ($pr->items as $line) {
            $orderedBase = (string) ($ordered[$line->id] ?? '0');
            if (bccomp($orderedBase, '0', 6) <= 0) {
                $remaining[(int) $line->id] = bcadd((string) $line->quantity, '0', 3);

                continue;
            }
            $remainingBase = bcsub($this->baseQuantityForPrLine($line, (string) $line->quantity, $line->unit), $orderedBase, 6);
            $remaining[(int) $line->id] = bccomp($remainingBase, '0', 6) > 0
                ? bcdiv($remainingBase, $this->baseQuantityForPrLine($line, '1', $line->unit), 3)
                : '0.000';
        }

        return $remaining;
    }

    /**
     * Reconcile `status` + `po_conversion_status` from actual line coverage:
     * every line on a live PO → converted; some → partial; none → approved and
     * not started. Idempotent, so it doubles as the reopen path when a PO is
     * cancelled/rejected/deleted.
     */
    public function syncConversionStatus(PurchaseRequest $pr): void
    {
        if (in_array($pr->status, [
            PurchaseRequestStatus::Draft,
            PurchaseRequestStatus::Pending,
            PurchaseRequestStatus::Rejected,
            PurchaseRequestStatus::Cancelled,
        ], true)) {
            return;
        }

        $pr->loadMissing('items');
        $fullyCovered = array_flip($this->fullyCoveredPrLineIds($pr));
        $orderedByLine = $this->orderedQuantitiesByPrLine($pr);
        $total = $pr->items->count();
        $fullyCoveredCount = $pr->items->filter(static fn ($line): bool => isset($fullyCovered[$line->id]))->count();
        $anyCoveredCount = $pr->items->filter(static fn ($line): bool => isset($orderedByLine[$line->id]) && bccomp((string) $orderedByLine[$line->id], '0', 6) > 0)->count();

        if ($total > 0 && $fullyCoveredCount >= $total) {
            $pr->forceFill([
                'status' => PurchaseRequestStatus::Converted,
                'po_conversion_status' => PurchaseRequestConversionStatus::Converted,
                'po_conversion_note' => null,
                'po_conversion_at' => now(),
            ])->save();

            // The PR chain's terminal step (§7.5): conversion completes the
            // PR's own journey; the downstream stages live on the PO/GRN/Bill
            // chains. Idempotent — syncConversionStatus is also the reopen
            // path, but a re-converted PR is still 'converted' so a replay
            // re-stages an identical (deduped) event at worst.
            app(ChainBroadcaster::class)->broadcastFor(
                $pr->fresh(),
                PurchaseRequestStatus::Converted->value,
            );

            return;
        }

        if ($anyCoveredCount > 0) {
            $pr->forceFill([
                'status' => PurchaseRequestStatus::Approved,
                'po_conversion_status' => PurchaseRequestConversionStatus::Partial,
                'po_conversion_at' => now(),
            ])->save();

            return;
        }

        $pr->forceFill([
            'status' => PurchaseRequestStatus::Approved,
            'po_conversion_status' => PurchaseRequestConversionStatus::NotStarted,
            'po_conversion_note' => null,
            'po_conversion_at' => null,
        ])->save();
    }

    /** @return array<int, PurchaseOrder> */
    private function livePurchaseOrders(PurchaseRequest $pr): array
    {
        return $pr->purchaseOrders()
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->withoutTrashed()
            ->get()
            ->all();
    }

    public function update(PurchaseOrder $po, array $data, ?User $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $by) {
            // Never trust the route-bound snapshot for the state guard. A
            // concurrent submit/approve/delete may have changed it after
            // binding; the row lock serializes this mutation with those
            // lifecycle actions and with line replacement.
            $locked = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($po->id);
            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft POs can be edited.');
            }
            if ($locked->request_for_quote_id !== null) {
                throw new BusinessRuleException('RFQ-generated purchase orders are commercially immutable. Resolve the RFQ before changing the award.');
            }
            if ($by !== null && ! $this->visibility->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to edit this purchase order.');
            }

            $isVatable = (bool) ($data['is_vatable'] ?? $locked->is_vatable);
            $sourcePr = $locked->purchase_request_id === null ? null : PurchaseRequest::query()
                ->lockForUpdate()
                ->with('items')
                ->find($locked->purchase_request_id);
            $lineData = is_array($data['items'] ?? null)
                ? $data['items']
                : $locked->items()->get()->map(static fn (PurchaseOrderItem $line): array => [
                    'item_id' => $line->item_id,
                    'purchase_request_item_id' => $line->purchase_request_item_id,
                    'description' => $line->description,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (string) $line->unit_price,
                ])->all();
            [$lines, $subtotal] = $this->normalizeLines($lineData, $sourcePr);
            if ($sourcePr !== null) {
                $this->assertPrLineQuantityCoverage($sourcePr, $lines, (int) $locked->id);
            }
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);
            $threshold = $this->businessPolicy->purchaseOrderVpThreshold();

            $locked->update([
                'date' => $data['date'] ?? $locked->date,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $locked->expected_delivery_date,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'is_vatable' => $isVatable,
                'requires_vp_approval' => (float) $total >= $threshold,
                'remarks' => $data['remarks'] ?? $locked->remarks,
                'incoterm' => array_key_exists('incoterm', $data)
                    ? $data['incoterm']
                    : $locked->incoterm?->value,
            ]);

            $locked->items()->forceDelete();
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $locked->id]));
            }

            if ($sourcePr !== null) {
                $this->syncConversionStatus($sourcePr);
            }

            if ($sourcePr?->department_id !== null) {
                // Recalculate under the same locked draft transaction. The
                // service clears prior acknowledgment fields itself.
                $this->budget->assess($locked, (int) $sourcePr->department_id, (string) $total);
            } else {
                // A changed draft must never retain a warning or finance
                // acknowledgment calculated for a prior amount.
                $locked->forceFill([
                    'budget_warning_level' => null,
                    'budget_warning_message' => null,
                    'budget_acknowledged_by' => null,
                    'budget_acknowledged_at' => null,
                ])->save();
            }

            return $this->show($locked->fresh());
        });
    }

    public function submit(PurchaseOrder $po, ?User $by = null): PurchaseOrder
    {
        if ($by !== null && ! $this->visibility->canManageDraft($by, $po)) {
            throw new ForbiddenActionException('You do not have permission to submit this purchase order.');
        }

        return DB::transaction(function () use ($po, $by) {
            // Lock and re-read before creating approval records so an update
            // cannot change the lines/amount between the state check and
            // submission.
            $locked = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($po->id);
            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft POs can be submitted.');
            }
            if ($by !== null && ! $this->visibility->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to submit this purchase order.');
            }
            // Vendor must be active to submit a PO.
            $this->assertVendorPurchasable((int) $locked->vendor_id);

            $this->approvals->submit($locked, 'purchase_order', (string) $locked->total_amount);
            $locked->forceFill(['status' => PurchaseOrderStatus::PendingApproval])->save();
            $fresh = $locked->fresh();
            app(OutboxService::class)->recordForChain(
                new PurchaseOrderSubmitted($fresh),
                $fresh,
                'p2p',
                'purchase_order',
                PurchaseOrderStatus::PendingApproval->value,
            );

            return $fresh;
        });
    }

    public function acknowledgeBudget(PurchaseOrder $po, User $by): PurchaseOrder
    {
        if (! $this->visibility->canAcknowledgeBudget($by, $po)) {
            throw new ForbiddenActionException('You do not have permission to acknowledge this purchase order budget warning.');
        }

        return $this->budget->acknowledge($po, $by);
    }

    public function approve(PurchaseOrder $po, User $by, ?string $remarks = null): PurchaseOrder
    {
        // Fast-path guard (authoritative re-check happens under the row lock
        // inside the transaction).
        if ($po->status !== PurchaseOrderStatus::PendingApproval) {
            throw new BusinessRuleException('PO is not in an approvable state.');
        }
        $this->budget->assertAcknowledged($po);
        // Vendor must be active to approve a PO.
        $this->assertVendorPurchasable((int) $po->vendor_id);
        // OGAMI-002 — segregation of duties: the approver must not be the user
        // who created the vendor on this PO (vendor-create vs PO-approve).
        $this->assertVendorSod($po, $by);

        // Budget enforcement (opt-in via budgeting.enforcement_mode; 'off' = no-op).
        // Resolve the department via the linked PR; skip when there's no link.
        $deptId = $po->purchaseRequest?->department_id
            ?? PurchaseRequest::find($po->purchase_request_id)?->department_id;

        // If this PO has a pending change response, budget enforcement must use
        // the PROPOSED total, not the original. The proposal was already
        // assessed in resolve(), so we re-check here to be defensive.
        $enforcementAmount = (string) $po->total_amount;
        if ($po->pending_change_response_id !== null) {
            $response = PurchaseOrderResponse::find($po->pending_change_response_id);
            if ($response) {
                // Recalculate proposed totals to get the enforcement amount
                $proposed = app(SupplierResponseService::class)
                    ->computeProposalTotals($response, $po);
                $enforcementAmount = $proposed['total_amount'];
            }
        }

        if ($deptId !== null) {
            $this->budget->enforce($deptId, $enforcementAmount);
        }

        // PPAP gate is controlled by the persisted quality.ppap_gate_enabled setting.
        // Block approval if any line item's vendor has a registered-but-unapproved
        // PPAP. Items never put under PPAP control pass through.
        if ($this->settings->requiredBool('quality.ppap_gate_enabled')
            && class_exists(PpapService::class)) {
            $ppap = app(PpapService::class);
            foreach ($po->items()->with('item:id,code,name')->get() as $line) {
                if ($line->item_id && ! $ppap->vendorHasActivePpap((int) $po->vendor_id, (int) $line->item_id)) {
                    // Name the item, never its primary key. This message reaches the
                    // browser, and `item #42` both violates the HashID rule and hands
                    // the caller a raw `items` PK it has no other way to observe.
                    $label = $line->item?->code ?? $line->description ?? 'unnamed item';
                    throw new BusinessRuleException(
                        "Vendor has no approved PPAP for item {$label}. Approve the PPAP submission before this PO."
                    );
                }
            }
        }

        $result = DB::transaction(function () use ($po, $by, $remarks) {
            // Lock-then-guard: re-read the authoritative row so a concurrent
            // approver holding a stale draft/pending instance cannot double-
            // evaluate isFullyApproved and duplicate the approval outbox event.
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->getKey());
            if ($locked->status !== PurchaseOrderStatus::PendingApproval) {
                throw new BusinessRuleException('PO is not in an approvable state.');
            }

            $this->approvals->approve($locked, $by, $remarks);
            $becameApproved = false;
            $hasAppliedProposal = false;

            if ($this->approvals->isFullyApproved($locked)) {
                // If this PO has a pending change response, apply it now before
                // moving to acknowledged. The proposal was already budget-checked
                // during resolve().
                if ($locked->pending_change_response_id !== null) {
                    $response = PurchaseOrderResponse::query()
                        ->lockForUpdate()
                        ->findOrFail($locked->pending_change_response_id);

                    // Apply the supplier's proposed changes to all lines
                    app(SupplierResponseService::class)->applyProposal($response, $locked);

                    // Set confirmed delivery if the response proposed one
                    if ($response->proposed_delivery_date !== null) {
                        $locked->confirmed_delivery_date = $response->proposed_delivery_date;
                    }

                    // Move to acknowledged (supplier already agreed to these terms)
                    $locked->forceFill([
                        'status' => $locked->receiptStatusOr(PurchaseOrderStatus::Acknowledged),
                        'pending_change_response_id' => null,
                        'approved_by' => $by->id,
                        'approved_at' => now(),
                    ])->save();

                    // Mark response as accepted
                    $response->forceFill(['status' => PurchaseOrderResponseStatus::Accepted])->save();

                    // Notify supplier that their proposed terms were approved
                    app(SupplierResponseService::class)->emailSupplierDecision($response->load('purchaseOrder'), 'accept');

                    $hasAppliedProposal = true;
                } else {
                    // Normal approval path
                    $locked->forceFill([
                        'status' => PurchaseOrderStatus::Approved,
                        'approved_by' => $by->id,
                        'approved_at' => now(),
                    ])->save();
                    $this->recordSupplierItemLinks($locked);
                    $becameApproved = true;
                }
            }

            $fresh = $locked->fresh();

            if ($becameApproved) {
                // Series C — Task C2. Domain event for chain listeners
                // (NotifyOnPurchaseOrderApproved + future SendPOToSupplier).
                // NOT recorded for pending-approval re-approval, so supplier
                // dispatch listeners don't trigger again.
                app(OutboxService::class)->recordForChain(
                    new PurchaseOrderApproved($fresh),
                    $fresh,
                    'p2p',
                    'purchase_order',
                    PurchaseOrderStatus::Approved->value,
                );
            }

            // Series C — Task C4. Stage chain progress with the approval
            // transaction; publication remains post-commit via the outbox.
            $this->broadcastChain($fresh, $by);

            return $fresh;
        });

        return $result;
    }

    /**
     * Record the vendor↔item relationship observed on an approved PO.
     *
     * A qualified (approved) link is only ever created through supplier-listing
     * review or explicit ASL entry. A PO is evidence of a purchase, not a
     * qualification, so when no link exists this records a `provisional` row
     * that purchasing can review — it no longer silently blesses a vendor for
     * every item it happened to ship (which bypassed the review workflow and,
     * with the old soft-delete/unique collision, could 500 on re-add).
     */
    private function recordSupplierItemLinks(PurchaseOrder $po): void
    {
        foreach ($po->items()->get() as $line) {
            if ($line->item_id === null) {
                continue;
            }

            $existing = ApprovedSupplier::query()
                ->where('item_id', $line->item_id)
                ->where('vendor_id', $po->vendor_id)
                ->first();

            if ($existing !== null) {
                // Never demote a qualified link; just refresh the price.
                $existing->update(['last_price' => $line->unit_price, 'last_price_at' => now()]);

                continue;
            }

            ApprovedSupplier::create([
                'item_id' => $line->item_id,
                'vendor_id' => $po->vendor_id,
                'qualification_status' => ApprovedSupplier::QUALIFICATION_PROVISIONAL,
                'last_price' => $line->unit_price,
                'last_price_at' => now(),
            ]);
        }
    }

    /** OGAMI-002 — permission that lets a PO approver bypass the vendor-creator SoD check. */
    public const VENDOR_SOD_OVERRIDE_PERMISSION = 'purchasing.po.sod_override';

    /**
     * OGAMI-002 — block a PO approver who is also the creator of the PO's vendor.
     *
     * This is a guard against a single user both onboarding a supplier and
     * approving spend to that supplier. ACTIVE: migration 0222 added
     * `vendors.created_by` and VendorService populates it, so the guard fires for
     * any vendor created after that migration. Legacy vendors with a null
     * `created_by` stay exempt (unknown creator → cannot self-approve). The
     * `purchasing.po.sod_override` permission is an explicit escape hatch
     * (system_admin always passes).
     */
    private function assertVendorSod(PurchaseOrder $po, User $by): void
    {
        // The rule lives in the policy so the PO detail's Approve button and
        // this guard can never disagree.
        if (! $this->visibility->vendorSodBlocks($po, $by)) {
            return;
        }

        // Named exception, not abort(403): a refusal must be visible to the
        // catch arms that treat "expected business failure" differently from
        // "infrastructure failure", and render through the shared 403 arm
        // without a Log::error per refusal. See ForbiddenActionException's
        // docblock for the two defects abort() already caused here.
        throw new ForbiddenActionException(
            'You cannot approve a purchase order to a vendor you created (segregation of duties).'
        );
    }

    private function assertVendorPurchasable(int $vendorId): void
    {
        $vendor = Vendor::withTrashed()->find($vendorId);
        if (! $vendor || ! $vendor->isPurchasable()) {
            $vendorName = $vendor?->name ?? "Vendor #{$vendorId}";
            throw new BusinessRuleException(
                "Vendor {$vendorName} is inactive. Reactivate it or choose another supplier."
            );
        }
    }

    public function reject(PurchaseOrder $po, User $by, string $reason): PurchaseOrder
    {
        $result = DB::transaction(function () use ($po, $by, $reason) {
            // Lock-then-guard: re-read so a stale instance cannot reject an
            // approval that concurrently committed.
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->getKey());
            if ($locked->status !== PurchaseOrderStatus::PendingApproval) {
                throw new BusinessRuleException('PO is not in an approvable state.');
            }

            $this->approvals->reject($locked, $by, $reason);

            // If this PO has a pending change response, it's a re-approval
            // rejection. Return the PO to sent with original terms intact, and
            // mark the response as rejected so supplier can respond again.
            // DO NOT cancel the dispatch (PO is still viable).
            if ($locked->pending_change_response_id !== null) {
                $response = PurchaseOrderResponse::query()
                    ->lockForUpdate()
                    ->find($locked->pending_change_response_id);
                if ($response) {
                    $response->forceFill([
                        'status' => PurchaseOrderResponseStatus::Rejected,
                        'resolution_notes' => $reason,
                    ])->save();
                    // Notify supplier their proposal was rejected
                    app(SupplierResponseService::class)->emailSupplierDecision($response->load('purchaseOrder'), 'reject');
                }
                $locked->forceFill([
                    'status' => $locked->receiptStatusOr(PurchaseOrderStatus::Sent),
                    'pending_change_response_id' => null,
                ])->save();
            } else {
                // Normal rejection: PO is cancelled and dispatch is killed
                $locked->forceFill(['status' => PurchaseOrderStatus::Cancelled])->save();
                $fresh = $locked->fresh();
                $this->supplierDispatches->cancelForPurchaseOrder(
                    $fresh,
                    'Purchase order was rejected; supplier dispatch is no longer actionable.',
                );
                $this->reopenSourcePrIfLastLink($fresh);
                // Rejection is a cancellation from the downstream chain's point
                // of view. Keep it on the same durable outbox path as an explicit
                // cancellation so future listeners cannot miss this transition.
                app(OutboxService::class)->recordForChain(
                    new PurchaseOrderCancelled($fresh),
                    $fresh,
                    'p2p',
                    'purchase_order',
                    PurchaseOrderStatus::Cancelled->value,
                );
            }

            $fresh = $locked->fresh();
            $this->broadcastChain($fresh, $by);

            return $fresh;
        });

        return $result;
    }

    public function markAsSent(PurchaseOrder $po, ?string $dispatchChannel = null, ?User $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $dispatchChannel, $by) {
            // The controller's route-bound model may be stale when an approval
            // or cancellation races the send request. Re-read and lock before
            // validating the transition or publishing the GRN trigger.
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($row->status !== PurchaseOrderStatus::Approved) {
                throw new BusinessRuleException('Only approved POs can be marked as sent.');
            }
            if ($by !== null && ! $this->visibility->canSend($by, $row)) {
                throw new ForbiddenActionException('You do not have permission to send this purchase order.');
            }

            $row->forceFill([
                'status' => PurchaseOrderStatus::Sent,
                'sent_to_supplier_at' => now(),
            ])->save();
            $fresh = $row->fresh();

            // Record the proof boundary atomically with the PO transition.
            // This does not send a document; it records that the operator
            // confirmed the external transmission before changing the PO to
            // `sent`.
            $this->supplierDispatches->confirmSent($fresh, $dispatchChannel);

            // Stage the expected GRN through the durable outbox. It is
            // recorded atomically with the sent transition and published after
            // commit; the listener remains idempotent on replay.
            app(OutboxService::class)->recordForChain(
                new PurchaseOrderSent($fresh),
                $fresh,
                'p2p',
                'purchase_order',
                PurchaseOrderStatus::Sent->value,
            );
            $this->broadcastChain($fresh, null);

            return $fresh;
        });
    }

    /**
     * Supplier acknowledgment: `Sent → Acknowledged`.
     *
     * This is deliberately NOT `markAsSent` — that records OGAMI transmitting
     * the PO. Acknowledgment records the supplier accepting a PO that was
     * already sent. The supplier's proposed date is stored as
     * `confirmed_delivery_date`; `expected_delivery_date` (OGAMI's required
     * date) is never written here, and the supplier note is appended rather
     * than replacing internal remarks.
     */
    public function acknowledgeBySupplier(
        PurchaseOrder $po,
        ?string $confirmedDate = null,
        ?string $notes = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($po, $confirmedDate, $notes): PurchaseOrder {
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($row->status !== PurchaseOrderStatus::Sent) {
                throw new BusinessRuleException('Only a sent purchase order can be acknowledged.');
            }

            if ($confirmedDate !== null && trim($confirmedDate) !== '') {
                $row->confirmed_delivery_date = $confirmedDate;
            }
            if ($notes !== null && trim($notes) !== '') {
                $row->remarks = trim(($row->remarks ? $row->remarks."\n" : '').'Supplier: '.trim($notes));
            }

            $row->status = PurchaseOrderStatus::Acknowledged;
            $row->save();

            $fresh = $row->fresh();
            $this->broadcastChain($fresh, null);

            return $fresh;
        });
    }

    public function cancel(PurchaseOrder $po, string $reason, ?User $by = null): PurchaseOrder
    {
        $fresh = DB::transaction(function () use ($po, $reason, $by) {
            // Route-bound models may be stale when receiving or closing races
            // cancellation. Re-read and lock the authoritative row before
            // evaluating guards or applying the terminal transition.
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if (in_array($row->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true)) {
                throw new BusinessRuleException('Cannot cancel a fully received or closed PO.');
            }
            // Cancelling an already-cancelled PO used to succeed. It is not
            // harmless: it appends a second "Cancelled: …" block to remarks and
            // records ANOTHER PurchaseOrderCancelled row on the p2p outbox, so a
            // double-submit published one logical cancellation twice to every
            // downstream chain listener.
            if ($row->status === PurchaseOrderStatus::Cancelled) {
                throw new BusinessRuleException('This purchase order is already cancelled.');
            }
            if ($by !== null && ! $this->visibility->canCancel($by, $row)) {
                throw new ForbiddenActionException('You do not have permission to cancel this purchase order.');
            }
            // Refuse cancellation if any non-cancelled bills are linked to this PO.
            // This includes bills linked via GRN (receivable) and direct service/expense bills.
            if ($row->bills()->whereNot('status', 'cancelled')->exists()) {
                throw new BusinessRuleException('Cannot cancel a PO that has linked bills. Cancel or void the bills first.');
            }

            // Refuse cancellation if any GRNs exist that represent real goods in motion.
            // Both Draft (auto-staged placeholder) and Rejected (supplier didn't ship) are exempt.
            // Goods in motion (pending_qc onward, or accepted) prevent cancellation.
            if ($row->goodsReceiptNotes()
                ->whereNotIn('status', [GrnStatus::Draft->value, GrnStatus::Rejected->value])
                ->exists()
            ) {
                throw new BusinessRuleException('Cannot cancel a PO with received goods.');
            }

            // If this PO has a pending change response, mark it as rejected
            // before cancelling the PO.
            if ($row->pending_change_response_id !== null) {
                $response = PurchaseOrderResponse::query()
                    ->lockForUpdate()
                    ->find($row->pending_change_response_id);
                if ($response) {
                    $response->forceFill(['status' => PurchaseOrderResponseStatus::Rejected])->save();
                }
                $row->pending_change_response_id = null;
            }

            // Single save → single audit row for one logical action.
            $row->fill(['remarks' => trim(($row->remarks ? $row->remarks."\n" : '').'Cancelled: '.$reason)]);
            $row->status = PurchaseOrderStatus::Cancelled;
            $row->save();

            // Purge any staged draft GRNs so a cancelled PO cannot be received against
            $draftGrns = $row->goodsReceiptNotes()
                ->where('status', GrnStatus::Draft->value)
                ->get();
            foreach ($draftGrns as $draftGrn) {
                $draftGrn->items()->delete();
                $draftGrn->delete();
            }

            $fresh = $row->fresh();
            $this->supplierDispatches->cancelForPurchaseOrder(
                $fresh,
                'Purchase order was cancelled: '.$reason,
            );
            $this->reopenSourcePrIfLastLink($fresh);
            app(OutboxService::class)->recordForChain(
                new PurchaseOrderCancelled($fresh),
                $fresh,
                'p2p',
                'purchase_order',
                PurchaseOrderStatus::Cancelled->value,
            );
            $this->broadcastChain($fresh, null);

            return $fresh;
        });

        return $fresh;
    }

    public function close(PurchaseOrder $po, ?User $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $by): PurchaseOrder {
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($row->status !== PurchaseOrderStatus::Received) {
                throw new BusinessRuleException('Only fully received POs can be closed.');
            }
            if ($by !== null && ! $this->visibility->canClose($by, $row)) {
                throw new ForbiddenActionException('You do not have permission to close this purchase order.');
            }
            $row->forceFill(['status' => PurchaseOrderStatus::Closed])->save();
            $fresh = $row->fresh();
            $this->broadcastChain($fresh, null);

            return $fresh;
        });
    }

    public function shortClose(PurchaseOrder $po, string $reason, ?User $by = null, bool $systemAction = false): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason, $by, $systemAction): PurchaseOrder {
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if (! $row->isShortClosable()) {
                throw new BusinessRuleException('Only a PO with received goods can be short-closed. Cancel a PO with no receipts instead.');
            }
            // Permission: creator OR company-wide approver tier (skip on systemAction)
            if ($by !== null && ! $systemAction) {
                $isCreator = (int) $row->created_by === (int) $by->id;
                $isApprover = $by->hasPermission('purchasing.po.approve');
                if (! ($isCreator || $isApprover)) {
                    throw new ForbiddenActionException('You do not have permission to close this purchase order.');
                }
            }

            // Refuse if any GRN is in pending_qc
            if ($row->goodsReceiptNotes()
                ->where('status', GrnStatus::PendingQc->value)
                ->exists()
            ) {
                throw new BusinessRuleException('Finish incoming QC before short-closing this PO.');
            }

            // Refuse if an open inbound shipment exists for this PO
            $openShipment = Shipment::query()
                ->where('purchase_order_id', $row->id)
                ->whereNotIn('status', [ShipmentStatus::Received->value, ShipmentStatus::Cancelled->value])
                ->first();
            if ($openShipment !== null) {
                throw new BusinessRuleException(
                    "Cannot short-close this PO: an open inbound shipment ({$openShipment->shipment_number}) exists. "
                    .'Receive or cancel the shipment first.'
                );
            }

            // Purge any staged draft GRNs
            $draftGrns = $row->goodsReceiptNotes()
                ->where('status', GrnStatus::Draft->value)
                ->get();
            foreach ($draftGrns as $draftGrn) {
                $draftGrn->items()->delete();
                $draftGrn->delete();
            }

            // Set status to Closed with short-close metadata
            $row->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'short_closed_at' => now(),
                'short_closed_by' => $by?->id,
                'short_close_reason' => $reason,
            ])->save();

            $fresh = $row->fresh();
            $this->broadcastChain($fresh, null);

            return $fresh;
        });
    }

    /** Series C — Task C4. Stage durable chain progress for the owning write. */
    private function broadcastChain(PurchaseOrder $po, ?User $actor): void
    {
        app(ChainBroadcaster::class)
            ->broadcastFor($po, $po->status?->value ?? '', $actor ?? auth()->user());
    }

    public function delete(PurchaseOrder $po, ?User $by = null): void
    {
        DB::transaction(function () use ($po, $by) {
            // Lock the authoritative row before the draft guard. This keeps a
            // stale delete from removing a PO after submit/approval won the
            // lifecycle race.
            $locked = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($po->id);
            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft POs can be deleted.');
            }
            if ($by !== null && ! $this->visibility->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to delete this purchase order.');
            }

            $prId = $locked->purchase_request_id;
            $locked->delete();
            if ($prId !== null) {
                $this->reopenSourcePrIfLastLinkFor($prId);
            }
        });
    }

    public function restore(PurchaseOrder $po, ?User $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $by): PurchaseOrder {
            $locked = PurchaseOrder::withTrashed()
                ->lockForUpdate()
                ->findOrFail($po->id);
            if (! $locked->trashed()) {
                throw new BusinessRuleException('Only deleted purchase orders can be restored.');
            }
            // Restore is draft management: only drafts can be deleted, so
            // canManageDraft is the ownership gate on the trashed row.
            if ($by !== null && ! $this->visibility->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to restore this purchase order.');
            }

            $locked->restore();

            return $locked->fresh();
        });
    }

    /**
     * When the PO being closed out was the LAST live PO sourced from a
     * `converted` PR, flip the PR back to `approved` so it can be converted
     * again (e.g. an auto-PO whose draft was cancelled, or a PO rejected in
     * review). The PR keeps its approval history — only its status returns.
     */
    private function reopenSourcePrIfLastLink(PurchaseOrder $po): void
    {
        if ($po->purchase_request_id === null) {
            return;
        }
        $this->reopenSourcePrIfLastLinkFor($po->purchase_request_id);
    }

    private function reopenSourcePrIfLastLinkFor(int $prId): void
    {
        // Cancellation, rejection, and deletion call this inside their
        // transaction. Lock the source PR so it cannot be reopened between
        // the converter's status check and PO creation.
        $pr = PurchaseRequest::query()
            ->lockForUpdate()
            ->find($prId);
        if (! $pr) {
            return;
        }

        // Recompute from live coverage. With partial conversion the PR can be
        // Approved/Partial/Converted, so a "was it converted" guard is wrong:
        // syncConversionStatus() moves it back to approved when the last live
        // PO disappears, and leaves remaining coverage partial otherwise.
        $this->syncConversionStatus($pr);
    }

    /**
     * BusinessRuleException rather than ValidationException keyed to `items`:
     * convertFromPr() reaches this builder from ConsolidatePurchaseOrders, a
     * queued listener that splits on BusinessRuleException — "expected, record a
     * manual-action outcome" — versus Throwable — "unexpected, rethrow".
     *
     * Note which way this moved. As bare RuntimeExceptions these four ESCAPED
     * that split and were handled as unexpected: the listener rethrew, the job
     * poisoned, and the PR stayed at `po_conversion_status = NotStarted` with no
     * note explaining why. Naming them BusinessRuleException does not preserve
     * the graceful arm, it puts them in it for the first time — a PR line
     * missing an item or a price now records a manual-conversion outcome and
     * notifies, which is right because no retry can supply the missing value.
     * ValidationException would have escaped the split again.
     *
     * @param  array<int, array>  $rows
     * @return array{0: array<int, array>, 1: string}
     */
    private function normalizeLines(array $rows, ?PurchaseRequest $sourcePr = null): array
    {
        $lines = [];
        $subtotal = '0';
        foreach ($rows as $r) {
            $itemId = HashIdFilter::decode($r['item_id'] ?? null, Item::class) ?? (int) ($r['item_id'] ?? 0);
            if (! $itemId) {
                throw new BusinessRuleException('Each PO line must reference an item.');
            }
            if (! array_key_exists('quantity', $r) || trim((string) $r['quantity']) === '') {
                throw new BusinessRuleException('Each PO line must include a quantity.');
            }
            if (! array_key_exists('unit_price', $r) || trim((string) $r['unit_price']) === '') {
                throw new BusinessRuleException('Each PO line must include an authoritative unit price.');
            }
            $qty = (string) $r['quantity'];
            $price = (string) $r['unit_price'];
            if (! preg_match('/^\d+(?:\.\d{1,3})?$/D', $qty) || Money::lte($qty, '0') || Money::lte($price, '0')) {
                throw new BusinessRuleException('Quantity must be > 0 and unit price must be > 0.');
            }

            $sourceLineId = $r['purchase_request_item_id'] ?? null;
            $sourceLineId = $sourceLineId === null || $sourceLineId === ''
                ? null
                : (HashIdFilter::decode($sourceLineId, PurchaseRequestItem::class) ?? (int) $sourceLineId);
            if ($sourcePr?->items->isNotEmpty() && $sourceLineId === null) {
                throw new BusinessRuleException('Each line on a purchase order linked to a purchase request must identify its source PR line.');
            }
            if ($sourceLineId !== null) {
                $sourceLine = $sourcePr?->items->firstWhere('id', $sourceLineId);
                if (! $sourceLine || (int) $sourceLine->item_id !== $itemId) {
                    throw new BusinessRuleException('Each purchase-request source line must belong to the linked PR and selected item.');
                }
            }

            $total = Money::add(
                Money::mul($qty, $price),
                (string) ($r['rfq_line_freight_amount'] ?? '0'),
                (string) ($r['rfq_line_other_charges'] ?? '0'),
            );
            $lines[] = [
                'item_id' => $itemId,
                'purchase_request_item_id' => $sourceLineId,
                'description' => $r['description'],
                'quantity' => $qty,
                'unit' => $r['unit'] ?? null,
                'unit_price' => $price,
                'rfq_award_id' => $r['rfq_award_id'] ?? null,
                'supplier_quote_version_id' => $r['supplier_quote_version_id'] ?? null,
                'rfq_line_vat_amount' => array_key_exists('rfq_line_vat_amount', $r) ? (string) $r['rfq_line_vat_amount'] : null,
                'rfq_line_freight_amount' => array_key_exists('rfq_line_freight_amount', $r) ? (string) $r['rfq_line_freight_amount'] : null,
                'rfq_line_other_charges' => array_key_exists('rfq_line_other_charges', $r) ? (string) $r['rfq_line_other_charges'] : null,
                'total' => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }

        return [$lines, $subtotal];
    }
}
