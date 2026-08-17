<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly ApprovalService $approvals,
        private readonly BudgetEnforcementService $budget,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = PurchaseRequest::query()->with([
            'requester:id,name,role_id',
            'department:id,name,code',
            'items.item:id,code,name,unit_of_measure',
            'approvalRecords',
        ]);

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['status']))   $q->where('status', $filters['status']);
        if (! empty($filters['priority'])) $q->where('priority', $filters['priority']);
        if (isset($filters['is_urgent']) && $filters['is_urgent'] !== '') {
            $q->where('is_urgent', filter_var($filters['is_urgent'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['is_auto_generated']) && $filters['is_auto_generated'] !== '') {
            $q->where('is_auto_generated', filter_var($filters['is_auto_generated'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('pr_number', 'ilike', '%'.$filters['search'].'%');
        }

        // Row-level filtering. Admin and any user with purchasing.pr.approve
        // (e.g. department_head, purchasing_officer) see all PRs so their
        // approval queue is complete. Everyone else sees only their own.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $canApprove = $user->hasPermission('purchasing.pr.approve');
            if (! $isAdmin && ! $canApprove) {
                $q->where('requested_by', $user->id);
            }
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseRequest $pr): PurchaseRequest
    {
        return $pr->load([
            'requester:id,name,role_id',
            'department',
            'items.item',
            'approvalRecords.approver:id,name',
            'purchaseOrders:id,po_number,status,vendor_id,total_amount,purchase_request_id,is_auto_generated',
            'purchaseOrders.vendor:id,name',
            'purchaseOrders.bills:id,bill_number,total_amount,status,purchase_order_id',
            'purchaseOrders.goodsReceiptNotes:id,grn_number,status,purchase_order_id',
        ]);
    }

    /**
     * ADV6 — When auto-generating a PR (from MRP / low stock), pre-fill
     * the preferred supplier from approved_suppliers for each line item.
     */
    public function create(array $data, User $by): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $by) {
            $isAuto = (bool) ($data['is_auto_generated'] ?? false);

            $pr = PurchaseRequest::create([
                'pr_number'            => $this->sequences->generate('pr'),
                'requested_by'         => $by->id,
                'department_id'        => $data['department_id'] ?? $by->employee?->department_id ?? null,
                'template_id'          => $data['template_id'] ?? null,
                'date'                 => $data['date'] ?? now()->toDateString(),
                'reason'               => $data['reason'] ?? null,
                'priority'             => $data['priority'] ?? (string) $this->settings->get('purchasing.purchase_request.default_priority', ''),
                'is_auto_generated'    => $isAuto,
                'auto_generated_reason'=> $data['auto_generated_reason'] ?? null,
                'is_urgent'            => (bool) ($data['is_urgent'] ?? false),
                'urgency_reason'       => $data['urgency_reason'] ?? null,
            ]);
            // status is non-fillable; service-only.
            $pr->forceFill(['status' => PurchaseRequestStatus::Draft])->save();

            foreach (($data['items'] ?? []) as $row) {
                $itemId = ! empty($row['item_id'])
                    ? (HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'])
                    : null;
                // Catalog lines inherit description / unit / price from the Item
                // record when the client didn't supply them (same source of truth
                // as AutoReplenishmentService) — ad-hoc lines stay free-form.
                $item = $itemId ? Item::find($itemId) : null;

                // ADV6 — Pre-fill the preferred supplier when creating an auto-generated PR.
                $suggestedVendorId = null;
                if ($isAuto && $itemId) {
                    $preferred = ApprovedSupplier::where('item_id', $itemId)
                        ->where('is_preferred', true)
                        ->first();
                    $suggestedVendorId = $preferred?->vendor_id;
                }

                PurchaseRequestItem::create([
                    'purchase_request_id'  => $pr->id,
                    'item_id'              => $itemId,
                    'description'          => trim((string) ($row['description'] ?? '')) !== ''
                        ? (string) $row['description']
                        : ($item?->description !== null && trim((string) $item->description) !== ''
                            ? (string) $item->description
                            : ($item?->name ?? '')),
                    'quantity'             => $row['quantity'],
                    'unit'                 => trim((string) ($row['unit'] ?? '')) !== ''
                        ? (string) $row['unit']
                        : ($item?->unit_of_measure ?? null),
                    'estimated_unit_price' => ($row['estimated_unit_price'] ?? null) !== null
                        && trim((string) $row['estimated_unit_price']) !== ''
                        ? $row['estimated_unit_price']
                        : ($item ? (string) $item->standard_cost : null),
                    'purpose'              => $row['purpose'] ?? null,
                    // ADV6 — store suggested vendor ID on the item for UI hint
                    'suggested_vendor_id'  => $suggestedVendorId,
                ]);
            }

            return $this->show($pr);
        });
    }

    public function update(PurchaseRequest $pr, array $data): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessRuleException('Only draft PRs can be edited.');
        }
        return DB::transaction(function () use ($pr, $data) {
            $pr->update([
                'reason'    => $data['reason']   ?? $pr->reason,
                'priority'  => $data['priority'] ?? $pr->priority,
                'date'      => $data['date']     ?? $pr->date,
            ]);
            if (isset($data['items'])) {
                $pr->items()->forceDelete();
                foreach ($data['items'] as $row) {
                    $itemId = ! empty($row['item_id'])
                        ? (HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'])
                        : null;
                    $item = $itemId ? Item::find($itemId) : null;
                    PurchaseRequestItem::create([
                        'purchase_request_id'  => $pr->id,
                        'item_id'              => $itemId,
                        'description'          => trim((string) ($row['description'] ?? '')) !== ''
                            ? (string) $row['description']
                            : ($item?->description !== null && trim((string) $item->description) !== ''
                                ? (string) $item->description
                                : ($item?->name ?? '')),
                        'quantity'             => $row['quantity'],
                        'unit'                 => trim((string) ($row['unit'] ?? '')) !== ''
                            ? (string) $row['unit']
                            : ($item?->unit_of_measure ?? null),
                        'estimated_unit_price' => ($row['estimated_unit_price'] ?? null) !== null
                            && trim((string) $row['estimated_unit_price']) !== ''
                            ? $row['estimated_unit_price']
                            : ($item ? (string) $item->standard_cost : null),
                        'purpose'              => $row['purpose'] ?? null,
                    ]);
                }
            }
            return $this->show($pr->fresh());
        });
    }

    /**
     * ADV6 — On submit:
     * - Auto-approve small PRs (< ₱5,000) when requestor is a dept head or above.
     * - Urgent PRs skip the Department Head step.
     * - Pre-fill preferred supplier from approved_suppliers during submit.
     */
    public function submit(PurchaseRequest $pr): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessRuleException('Only draft PRs can be submitted.');
        }
        return DB::transaction(function () use ($pr) {
            $total = (string) $pr->totalEstimatedAmount();

            if ($pr->department_id) {
                $this->budget->assess($pr, (int) $pr->department_id, $total);
            }

            // ADV6 — Pre-fill preferred suppliers on items before submission.
            $this->prefillSupplierOnItems($pr);

            // ADV6 — Urgency escalation: urgent PRs may skip the Dept Head step,
            // which submitUrgent() does by rewriting that step's record after
            // submission, and only under the purchasing.urgent_skip_limit cap.
            // That cap is unrelated to ApprovalService's own amount gating,
            // which reads the per-step `threshold` key in the `steps` JSON (not
            // the unread `workflow_definitions.amount_threshold` column) and
            // reads no setting at all.
            if ($pr->is_urgent) {
                $this->submitUrgent($pr, $total);
            } else {
                $this->approvals->submit($pr, 'purchase_request', $total);
            }

            $pr->forceFill([
                'status'       => PurchaseRequestStatus::Pending,
                'submitted_at' => now(),
            ])->save();

            $fresh = $pr->fresh();

            // ADV6 — Auto-approve small PRs (< ₱5,000) when requestor is a dept head.
            $requester = $fresh->requester;
            $isDeptHead = $requester && $requester->employee &&
                $requester->employee->is_department_head;
            $autoApproveThreshold = $this->settings->requiredFloat('approval.pr.dept_head_auto_approve_threshold', 0);
            if ($total < $autoApproveThreshold && $isDeptHead) {
                // Auto-approve all pending steps in order.
                while ($this->approvals->nextStep($fresh)) {
                    $this->approvals->approve($fresh, $requester, 'Auto-approved: amount below configured department-head threshold.');
                }
                if ($this->approvals->isFullyApproved($fresh)) {
                    $fresh->forceFill([
                        'status'               => PurchaseRequestStatus::Approved,
                        'approved_at'          => now(),
                        'po_conversion_status' => PurchaseRequestConversionStatus::Pending,
                        'po_conversion_note'  => null,
                        'po_conversion_at'    => now(),
                    ])->save();
                    $fresh = $fresh->fresh();
                    app(OutboxService::class)->recordForChain(
                        new PurchaseRequestApproved($fresh),
                        $fresh,
                        'p2p',
                        'purchase_request',
                        PurchaseRequestStatus::Approved->value,
                    );
                }
            }

            return $fresh;
        });
    }

    /**
     * Submit an urgent PR — skip the first workflow step (Department Head)
     * so it goes directly to later approvers.
     *
     * OGAMI-013 — The Dept Head skip is now gated behind a value cap
     * (the persisted purchasing.urgent_skip_limit setting). A high-value "urgent" PR can no
     * longer bypass its department head with only a free-text reason; over the
     * cap, the full chain applies. A '0' cap disables skipping entirely. When a
     * skip IS performed, the urgency_reason is stamped onto the skipped record
     * for the audit trail.
     */
    private function submitUrgent(PurchaseRequest $pr, string $total): void
    {
        $this->approvals->submit($pr, 'purchase_request', $total);

        // Resolve the cap. '0' disables skipping; any positive value is the
        // inclusive ceiling under which the Dept Head step may be skipped.
        $limit = $this->settings->requiredFloat('purchasing.urgent_skip_limit', 0);
        $maySkip = $limit > 0 && Money::lte($total, $limit);

        if (! $maySkip) {
            // Over the cap (or skipping disabled): keep the full chain. The PR
            // is still flagged urgent for prioritization, but no step is removed.
            return;
        }

        // Find the first pending step and skip it (Dept Head role).
        $first = $this->approvals->records($pr)
            ->where('action', 'pending')
            ->orderBy('step_order')
            ->first();

        if ($first && $first->role_slug === 'department_head') {
            $reason = trim((string) ($pr->urgency_reason ?? ''));
            $note = 'Skipped — urgent PR escalation'
                . ($reason !== '' ? " (reason: {$reason})" : '');

            $first->update([
                'action'   => 'skipped',
                'remarks'  => $note,
                'acted_at' => now(),
            ]);
        }
    }

    /**
     * Pre-fill suggested_vendor_id on PR items that don't already have one
     * by looking up the preferred approved supplier for each item.
     */
    private function prefillSupplierOnItems(PurchaseRequest $pr): void
    {
        $pr->loadMissing('items.item');
        foreach ($pr->items as $item) {
            if ($item->item_id && ! $item->suggested_vendor_id) {
                $preferred = ApprovedSupplier::where('item_id', $item->item_id)
                    ->where('is_preferred', true)
                    ->first();
                if ($preferred) {
                    $item->update(['suggested_vendor_id' => $preferred->vendor_id]);
                }
            }
        }
    }

    public function acknowledgeBudget(PurchaseRequest $pr, User $by): PurchaseRequest
    {
        return $this->budget->acknowledge($pr, $by);
    }

    public function approve(PurchaseRequest $pr, User $by, ?string $remarks = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $by, $remarks) {
            // Lock-then-guard: re-read the authoritative row so a concurrent
            // approval (or cancel) holding a stale pending instance cannot slip
            // past the guard — which would double-evaluate isFullyApproved and
            // duplicate the approval outbox event.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if ($locked->status !== PurchaseRequestStatus::Pending) {
                throw new BusinessRuleException('Only pending PRs can be approved.');
            }
            $this->budget->assertAcknowledged($locked);

            $this->approvals->approve($locked, $by, $remarks);
            $becameApproved = false;
            if ($this->approvals->isFullyApproved($locked)) {
                $locked->forceFill([
                    'status'               => PurchaseRequestStatus::Approved,
                    'approved_at'          => now(),
                    'po_conversion_status' => PurchaseRequestConversionStatus::Pending,
                    'po_conversion_note'  => null,
                    'po_conversion_at'    => now(),
                ])->save();
                $becameApproved = true;
            }
            $fresh = $locked->fresh();
            if ($becameApproved) {
                app(OutboxService::class)->recordForChain(
                    new PurchaseRequestApproved($fresh),
                    $fresh,
                    'p2p',
                    'purchase_request',
                    PurchaseRequestStatus::Approved->value,
                );
            }
            return $fresh;
        });
    }

    /**
     * ADV6 — Bulk approve multiple PRs at once.
     * Only PRs in 'pending' status will be approved; others are skipped.
     */
    public function bulkApprove(array $ids, User $by, ?string $remarks = null): array
    {
        $results = [];
        foreach ($ids as $id) {
            try {
                $pr = PurchaseRequest::findOrFail($id);
                $result = $this->approve($pr, $by, $remarks);
                $results[] = [
                    'id'      => $result->hash_id,
                    'status'  => 'approved',
                    'message' => null,
                ];
            } catch (RuntimeException $e) {
                $results[] = [
                    'id'      => PurchaseRequest::find($id)?->hash_id ?? (string) $id,
                    'status'  => 'skipped',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    public function reject(PurchaseRequest $pr, User $by, string $reason): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $by, $reason) {
            // Lock-then-guard: re-read so a stale pending instance cannot reject
            // an approval that concurrently committed.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if ($locked->status !== PurchaseRequestStatus::Pending) {
                throw new BusinessRuleException('Only pending PRs can be rejected.');
            }
            $this->approvals->reject($locked, $by, $reason);
            $locked->forceFill(['status' => PurchaseRequestStatus::Rejected])->save();
            return $locked->fresh();
        });
    }

    public function cancel(PurchaseRequest $pr): PurchaseRequest
    {
        return DB::transaction(function () use ($pr) {
            // Lock-then-guard: without it, cancel could race a concurrent
            // approval and cancel an already-approved PR.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if (! in_array($locked->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)) {
                throw new BusinessRuleException('Cannot cancel a PR in this status.');
            }
            $locked->forceFill(['status' => PurchaseRequestStatus::Cancelled])->save();
            return $locked->fresh();
        });
    }

    public function delete(PurchaseRequest $pr): void
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessRuleException('Only draft PRs can be deleted.');
        }
        $pr->delete();
    }
}
