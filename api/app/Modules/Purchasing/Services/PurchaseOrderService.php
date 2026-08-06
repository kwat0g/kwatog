<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ApprovalService;
use App\Common\Services\BusinessPolicyService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ApprovalService $approvals,
        private readonly BusinessPolicyService $businessPolicy,
        private readonly BudgetEnforcementService $budget,
        private readonly TaxPolicyService $taxPolicy,
        private readonly SettingsService $settings,
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
        ]);
        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status']))   $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (isset($filters['requires_vp_approval']) && $filters['requires_vp_approval'] !== '') {
            $q->where('requires_vp_approval', filter_var($filters['requires_vp_approval'], FILTER_VALIDATE_BOOLEAN));
        }
        if (filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->where('expected_delivery_date', '<', now()->toDateString())
                ->whereIn('status', [
                    PurchaseOrderStatus::Approved->value,
                    PurchaseOrderStatus::Sent->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ]);
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('po_number', 'ilike', '%'.$filters['search'].'%');
        }

        // Row-level filtering. Admin and Purchasing approvers see everything.
        // Department Head sees POs for their department via the linked PR.
        // Everyone else sees only POs they created.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $canApprove = $user->hasPermission('purchasing.po.approve');
            if (! $isAdmin && ! $canApprove) {
                $creatorId = $user->id;
                if ($roleSlug === 'department_head') {
                    $deptId = \App\Modules\HR\Models\Employee::query()
                        ->whereKey($user->employee_id)
                        ->value('department_id');
                    $q->where(function ($qq) use ($creatorId, $deptId) {
                        $qq->where('created_by', $creatorId);
                        if ($deptId) {
                            $qq->orWhereHas('purchaseRequest', fn ($pr) => $pr->where('department_id', $deptId));
                        }
                    });
                } else {
                    $q->where('created_by', $creatorId);
                }
            }
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseOrder $po): PurchaseOrder
    {
        return $po->load([
            'vendor', 'purchaseRequest:id,pr_number',
            'items.item:id,code,name,unit_of_measure',
            'approvalRecords.approver:id,name',
            'goodsReceiptNotes:id,grn_number,received_date,status,purchase_order_id',
            'bills:id,bill_number,total_amount,balance,status,purchase_order_id',
            'creator:id,name,role_id', 'approver:id,name,role_id',
        ]);
    }

    /** Create an ad-hoc PO directly. */
    public function create(array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $by) {
            $vendorId = HashIdFilter::decode($data['vendor_id'], Vendor::class)
                ?? (int) $data['vendor_id'];
            $isVatable = (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered());

            [$lines, $subtotal] = $this->normalizeLines($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->vatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);
            $threshold = $this->businessPolicy->purchaseOrderVpThreshold();

            $deptId = $this->resolveDepartmentId($data);

            $po = PurchaseOrder::create([
                'po_number'            => $this->sequences->generate('purchase_order'),
                'vendor_id'            => $vendorId,
                'purchase_request_id'  => isset($data['purchase_request_id']) && is_int($data['purchase_request_id'])
                    ? $data['purchase_request_id']
                    : null,
                'date'                 => $data['date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal'             => $subtotal,
                'vat_amount'           => $vat,
                'total_amount'         => $total,
                'is_vatable'           => $isVatable,
                'requires_vp_approval' => (float) $total >= $threshold,
                'created_by'           => $by->id,
                'remarks'              => $data['remarks'] ?? null,
            ]);
            // status is non-fillable; service-only.
            $po->forceFill(['status' => PurchaseOrderStatus::Draft])->save();
            if ($deptId) {
                $this->budget->assess($po, $deptId, (float) $total);
            }
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $po->id]));
            }
            return $this->show($po);
        });
    }

    /** Convert an approved PR into one or more POs (grouped by vendor). */
    public function convertFromPr(PurchaseRequest $pr, array $vendorMap, User $by): array
    {
        if ($pr->status !== PurchaseRequestStatus::Approved) {
            throw new BusinessRuleException('Only approved PRs can be converted to POs.');
        }
        // vendorMap: { pr_item_id => vendor_id }
        return DB::transaction(function () use ($pr, $vendorMap, $by) {
            $byVendor = [];
            foreach ($pr->items as $line) {
                $vendorId = $vendorMap[$line->id] ?? null;
                if (! $vendorId) {
                    throw new BusinessRuleException("PR line {$line->id} has no vendor assignment.");
                }
                $byVendor[$vendorId][] = $line;
            }
            $created = [];
            foreach ($byVendor as $vendorId => $lines) {
                $itemPayload = [];
                foreach ($lines as $line) {
                    $unitPrice = $line->estimated_unit_price;
                    if ($unitPrice === null || (float) $unitPrice <= 0) {
                        throw new BusinessRuleException("PR line {$line->id} has no authoritative unit price.");
                    }
                    $itemPayload[] = [
                        'item_id'                  => $line->item_id,
                        'purchase_request_item_id' => $line->id,
                        'description'              => $line->description,
                        'quantity'                 => (string) $line->quantity,
                        'unit'                     => $line->unit,
                        'unit_price'               => (string) $unitPrice,
                    ];
                }
                $po = $this->create([
                    'vendor_id'           => $vendorId,
                    'date'                => now()->toDateString(),
                    'is_vatable'          => $this->taxPolicy->isVatRegistered(),
                    'remarks'             => "Auto-converted from PR {$pr->pr_number}",
                    'items'               => $itemPayload,
                    'purchase_request_id' => $pr->id,
                ], $by);
                $created[] = $po;
            }
            $pr->forceFill(['status' => PurchaseRequestStatus::Converted])->save();
            return $created;
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new BusinessRuleException('Only draft POs can be edited.');
        }
        return DB::transaction(function () use ($po, $data) {
            $isVatable = (bool) ($data['is_vatable'] ?? $po->is_vatable);
            [$lines, $subtotal] = $this->normalizeLines($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->vatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);
            $threshold = $this->businessPolicy->purchaseOrderVpThreshold();

            $po->update([
                'date'                 => $data['date'] ?? $po->date,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $po->expected_delivery_date,
                'subtotal'             => $subtotal,
                'vat_amount'           => $vat,
                'total_amount'         => $total,
                'is_vatable'           => $isVatable,
                'requires_vp_approval' => (float) $total >= $threshold,
                'remarks'              => $data['remarks'] ?? $po->remarks,
            ]);

            $po->items()->forceDelete();
            foreach ($lines as $row) {
                PurchaseOrderItem::create(array_merge($row, ['purchase_order_id' => $po->id]));
            }
            return $this->show($po->fresh());
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new BusinessRuleException('Only draft POs can be submitted.');
        }
        return DB::transaction(function () use ($po) {
            $this->approvals->submit($po, 'purchase_order', (float) $po->total_amount);
            $po->forceFill(['status' => PurchaseOrderStatus::PendingApproval])->save();
            return $po->fresh();
        });
    }

    public function acknowledgeBudget(PurchaseOrder $po, User $by): PurchaseOrder
    {
        return $this->budget->acknowledge($po, $by);
    }

    public function approve(PurchaseOrder $po, User $by, ?string $remarks = null): PurchaseOrder
    {
        if (! in_array($po->status, [PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Draft], true)) {
            throw new BusinessRuleException('PO is not in an approvable state.');
        }
        $this->budget->assertAcknowledged($po);
        // OGAMI-002 — segregation of duties: the approver must not be the user
        // who created the vendor on this PO (vendor-create vs PO-approve).
        $this->assertVendorSod($po, $by);

        // Budget enforcement (opt-in via budgeting.enforcement_mode; 'off' = no-op).
        // Resolve the department via the linked PR; skip when there's no link.
        $deptId = $po->purchaseRequest?->department_id
            ?? PurchaseRequest::find($po->purchase_request_id)?->department_id;
        if ($deptId !== null) {
            $this->budget->enforce($deptId, (float) $po->total_amount);
        }

        // PPAP gate is controlled by the persisted quality.ppap_gate_enabled setting.
        // Block approval if any line item's vendor has a registered-but-unapproved
        // PPAP. Items never put under PPAP control pass through.
        if ($this->settings->requiredBool('quality.ppap_gate_enabled')
            && class_exists(\App\Modules\Quality\Services\PpapService::class)) {
            $ppap = app(\App\Modules\Quality\Services\PpapService::class);
            foreach ($po->items()->get() as $line) {
                if ($line->item_id && ! $ppap->vendorHasActivePpap((int) $po->vendor_id, (int) $line->item_id)) {
                    throw new BusinessRuleException(
                        "Vendor has no approved PPAP for item #{$line->item_id}. Approve the PPAP submission before this PO."
                    );
                }
            }
        }

        $result = DB::transaction(function () use ($po, $by, $remarks) {
            $this->approvals->approve($po, $by, $remarks);
            $becameApproved = false;
            if ($this->approvals->isFullyApproved($po)) {
                $po->forceFill([
                    'status'      => PurchaseOrderStatus::Approved,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ])->save();
                // Update last_price on approved_suppliers per line.
                foreach ($po->items()->get() as $line) {
                    ApprovedSupplier::query()->updateOrCreate(
                        ['item_id' => $line->item_id, 'vendor_id' => $po->vendor_id],
                        ['last_price' => $line->unit_price, 'last_price_at' => now()]
                    );
                }
                $becameApproved = true;
            }
            $fresh = $po->fresh();
            if ($becameApproved) {
                // Series C — Task C2. Domain event for chain listeners
                // (NotifyOnPurchaseOrderApproved + future SendPOToSupplier).
                DB::afterCommit(fn () =>
                    event(new \App\Modules\Purchasing\Events\PurchaseOrderApproved($fresh))
                );
            }
            return $fresh;
        });

        // Series C — Task C4. Real-time chain progress.
        $this->broadcastChain($result, $by);
        return $result;
    }

    /** OGAMI-002 — permission that lets a PO approver bypass the vendor-creator SoD check. */
    private const VENDOR_SOD_OVERRIDE_PERMISSION = 'purchasing.po.sod_override';

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
        // Gracefully skip when the schema does not record who created a vendor.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('vendors', 'created_by')) {
            return;
        }

        $vendorCreatorId = Vendor::query()
            ->whereKey($po->vendor_id)
            ->value('created_by');

        if ($vendorCreatorId === null) {
            return; // unknown maker — guard cannot fire.
        }
        if ((int) $vendorCreatorId !== (int) $by->id) {
            return; // different user — allowed.
        }
        if ($by->hasPermission(self::VENDOR_SOD_OVERRIDE_PERMISSION)) {
            return; // explicit override.
        }

        abort(403, 'You cannot approve a purchase order to a vendor you created (segregation of duties).');
    }

    public function reject(PurchaseOrder $po, User $by, string $reason): PurchaseOrder
    {
        $result = DB::transaction(function () use ($po, $by, $reason) {
            $this->approvals->reject($po, $by, $reason);
            $po->update(['status' => PurchaseOrderStatus::Cancelled]);
            return $po->fresh();
        });
        $this->broadcastChain($result, $by);
        return $result;
    }

    public function markAsSent(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Approved) {
            throw new BusinessRuleException('Only approved POs can be marked as sent.');
        }
        $po->forceFill(['status' => PurchaseOrderStatus::Sent, 'sent_to_supplier_at' => now()])->save();
        $fresh = $po->fresh();
        $this->broadcastChain($fresh, null);
        return $fresh;
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true)) {
            throw new BusinessRuleException('Cannot cancel a fully received or closed PO.');
        }
        if ($po->goodsReceiptNotes()->exists()) {
            throw new BusinessRuleException('Cannot cancel a PO with GRNs.');
        }
        $fresh = DB::transaction(function () use ($po, $reason) {
            // Single save → single audit row for one logical action.
            $po->fill(['remarks' => trim(($po->remarks ? $po->remarks."\n" : '').'Cancelled: '.$reason)]);
            $po->status = PurchaseOrderStatus::Cancelled;
            $po->save();
            $fresh = $po->fresh();
            DB::afterCommit(fn () =>
                event(new \App\Modules\Purchasing\Events\PurchaseOrderCancelled($fresh))
            );
            return $fresh;
        });
        $this->broadcastChain($fresh, null);
        return $fresh;
    }

    public function close(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Received) {
            throw new BusinessRuleException('Only fully received POs can be closed.');
        }
        $po->forceFill(['status' => PurchaseOrderStatus::Closed])->save();
        $fresh = $po->fresh();
        $this->broadcastChain($fresh, null);
        return $fresh;
    }

    /** Series C — Task C4. */
    private function broadcastChain(PurchaseOrder $po, ?User $actor): void
    {
        app(\App\Common\Services\ChainBroadcaster::class)
            ->broadcastFor($po, $po->status?->value ?? '', $actor ?? auth()->user());
    }

    public function delete(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new BusinessRuleException('Only draft POs can be deleted.');
        }
        $po->delete();
    }

    /**
     * @param array<int, array> $rows
     * @return array{0: array<int, array>, 1: string}
     */
    private function normalizeLines(array $rows): array
    {
        $lines = [];
        $subtotal = '0';
        foreach ($rows as $r) {
            $itemId = HashIdFilter::decode($r['item_id'] ?? null, Item::class) ?? (int) ($r['item_id'] ?? 0);
            if (! $itemId) {
                throw new RuntimeException('Each PO line must reference an item.');
            }
            if (! array_key_exists('quantity', $r) || trim((string) $r['quantity']) === '') {
                throw new RuntimeException('Each PO line must include a quantity.');
            }
            if (! array_key_exists('unit_price', $r) || trim((string) $r['unit_price']) === '') {
                throw new RuntimeException('Each PO line must include an authoritative unit price.');
            }
            $qty   = (string) $r['quantity'];
            $price = (string) $r['unit_price'];
            if (Money::lte($qty, '0') || Money::lt($price, '0')) {
                throw new RuntimeException('Quantity must be > 0, unit price must be ≥ 0.');
            }
            $total = Money::mul($qty, $price);
            $lines[] = [
                'item_id'                  => $itemId,
                'purchase_request_item_id' => $r['purchase_request_item_id'] ?? null,
                'description'              => $r['description'],
                'quantity'                 => $qty,
                'unit'                     => $r['unit'] ?? null,
                'unit_price'               => $price,
                'total'                    => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }
        return [$lines, $subtotal];
    }
}
