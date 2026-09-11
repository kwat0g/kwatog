<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
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
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
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
        private readonly PurchaseRequestAccessPolicy $access,
        private readonly VendorSourcingService $sourcing,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = PurchaseRequest::query()->with([
            'requester:id,name,role_id',
            'department:id,name,code',
            'items.item:id,code,name,unit_of_measure',
            'items.suggestedVendor:id,name',
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

        $this->access->visibleTo($q, $user);

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(PurchaseRequest $pr): PurchaseRequest
    {
        return $pr->load([
            'requester:id,name,role_id',
            'department',
            'items.item',
            'items.suggestedVendor:id,name',
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
            $priority = $this->priorityValue(
                $data['priority'] ?? $this->settings->get('purchasing.purchase_request.default_priority', ''),
            );

            // Department attribution is a validated input, not a verbatim copy
            // (PU-06). A department head creates for their own department; a
            // purchasing officer (central requisition desk) records the
            // requesting department explicitly; automation runs plant-wide.
            // Everything else falls back to the creator's own department.
            $requestedDepartmentId = isset($data['department_id']) && $data['department_id'] !== null
                ? (int) $data['department_id']
                : null;
            if ($requestedDepartmentId !== null && ! $isAuto) {
                $creatorDepartmentId = $by->employee?->department_id !== null
                    ? (int) $by->employee->department_id
                    : null;
                $isCentralDesk = $by->hasPermission('purchasing.po.create');
                $isExecutive = $by->hasPermission('purchasing.pr.create') && $by->employee === null;
                if ($creatorDepartmentId !== null && ! $isCentralDesk
                    && $requestedDepartmentId !== $creatorDepartmentId) {
                    throw new ForbiddenActionException(
                        'You can only raise purchase requests for your own department.',
                    );
                }
                if ($creatorDepartmentId === null && ! $isCentralDesk && ! $isExecutive) {
                    throw new ForbiddenActionException(
                        'You cannot attribute a purchase request to a department.',
                    );
                }
            }

            $pr = PurchaseRequest::create([
                'pr_number'            => $this->sequences->generate('pr'),
                'requested_by'         => $by->id,
                'department_id'        => $requestedDepartmentId ?? $by->employee?->department_id ?? null,
                'template_id'          => $data['template_id'] ?? null,
                'date'                 => $data['date'] ?? now()->toDateString(),
                'reason'               => $data['reason'] ?? null,
                'priority'             => $priority,
                'is_auto_generated'    => $isAuto,
                'auto_generated_reason'=> $data['auto_generated_reason'] ?? null,
                // Priority is the public and automation-facing urgency
                // contract. Keep the legacy flag in sync for existing UI and
                // filters while retaining support for internal callers that
                // explicitly set is_urgent.
                'is_urgent'            => (bool) ($data['is_urgent'] ?? $this->isUrgentPriority($priority)),
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

                // ADV6 — Pre-fill the best-known supplier when creating an
                // auto-generated PR. The resolver widens this from
                // "preferred approved supplier only" to preferred → any
                // qualified supplier → listing → PO history, so an item with an
                // approved-but-not-preferred supplier no longer arrives
                // vendor-less and dead-ends the auto-converter.
                $suggestedVendorId = null;
                if ($isAuto && $itemId) {
                    $suggestedVendorId = $this->sourcing->suggestVendorId($itemId);
                }

                $estimate = ($row['estimated_unit_price'] ?? null) !== null
                    && trim((string) $row['estimated_unit_price']) !== ''
                    ? $row['estimated_unit_price']
                    : null;
                if ($estimate === null && $itemId && $suggestedVendorId) {
                    $estimate = $this->sourcing->priceFor($itemId, (int) $suggestedVendorId);
                }
                if ($estimate === null && $item) {
                    $estimate = (string) $item->standard_cost;
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
                    'estimated_unit_price' => $estimate,
                    'purpose'              => $row['purpose'] ?? null,
                    // ADV6 — store suggested vendor ID on the item for UI hint
                    'suggested_vendor_id'  => $suggestedVendorId,
                ]);
            }

            return $this->show($pr);
        });
    }

    public function update(PurchaseRequest $pr, array $data, ?User $by = null): PurchaseRequest
    {
        if ($by !== null && ! $this->access->canManageDraft($by, $pr)) {
            throw new ForbiddenActionException('You do not have permission to edit this purchase request.');
        }
        if ($by !== null && array_key_exists('department_id', $data)
            && ! $this->access->canAssignDepartment($by, $pr, $data['department_id'] !== null ? (int) $data['department_id'] : null)) {
            throw new ForbiddenActionException('You cannot assign this purchase request to that department.');
        }
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessRuleException('Only draft PRs can be edited.');
        }
        return DB::transaction(function () use ($pr, $data, $by) {
            // Lock-then-guard, same shape as submit()/approve()/reject()/cancel().
            // The pre-transaction checks above are a fast, cheap refusal; this is
            // the authoritative one. Without it an editor holding a draft instance
            // that submit() has since advanced would replace every line item AFTER
            // totalEstimatedAmount() had been fed to the budget gate and the
            // approval threshold, so the chain would be approving an amount the
            // lines no longer produce.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if ($locked->status !== PurchaseRequestStatus::Draft) {
                throw new BusinessRuleException('Only draft PRs can be edited.');
            }
            if ($by !== null && ! $this->access->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to edit this purchase request.');
            }
            if ($by !== null && array_key_exists('department_id', $data)
                && ! $this->access->canAssignDepartment($by, $locked, $data['department_id'] !== null ? (int) $data['department_id'] : null)) {
                throw new ForbiddenActionException('You cannot assign this purchase request to that department.');
            }

            $locked->update([
                'reason'    => $data['reason']   ?? $locked->reason,
                'priority'  => array_key_exists('priority', $data)
                    ? $this->priorityValue($data['priority'])
                    : $locked->priority,
                ...array_key_exists('priority', $data)
                    ? ['is_urgent' => $this->isUrgentPriority($this->priorityValue($data['priority']))]
                    : [],
                'date'      => $data['date']     ?? $locked->date,
                ...array_key_exists('department_id', $data)
                    ? ['department_id' => $data['department_id']]
                    : [],
            ]);
            if (isset($data['items'])) {
                // Replacing every line used to discard suggested_vendor_id. A
                // vendor assigned through the API (or prefilled on a prior
                // submit) vanished on any edit, so the line came back
                // vendor-less at conversion. Carry it forward by item.
                $preservedVendors = $locked->items()
                    ->whereNotNull('item_id')
                    ->get(['item_id', 'suggested_vendor_id'])
                    ->mapWithKeys(static fn ($row): array => [(int) $row->item_id => $row->suggested_vendor_id])
                    ->filter()
                    ->all();

                $locked->items()->forceDelete();
                foreach ($data['items'] as $row) {
                    $itemId = ! empty($row['item_id'])
                        ? (HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'])
                        : null;
                    $item = $itemId ? Item::find($itemId) : null;
                    PurchaseRequestItem::create([
                        'purchase_request_id'  => $locked->id,
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
                        'suggested_vendor_id'  => $itemId !== null ? ($preservedVendors[$itemId] ?? null) : null,
                    ]);
                }
            }
            return $this->show($locked->fresh());
        });
    }

    /**
     * ADV6 — On submit:
     * - Pre-fill preferred supplier from approved_suppliers during submit.
     *
     * 2026-09-10 — the two legacy submit-time behaviours were removed with the
     * chain redesign that made them unreachable:
     * - "Auto-approve small PRs when the requestor is a dept head" read
     *   `is_department_head`, an attribute that existed on no model or table
     *   (audit PU-03) — it never fired, and had it, the loop would have called
     *   approve() as the submitter and tripped the self-approval guard.
     * - "Urgent PRs skip the Department Head step" targeted a step the PR
     *   workflow no longer contains (Finance → VP ≥ ₱50k). Urgency remains a
     *   prioritization flag and notification trigger only.
     */
    public function submit(PurchaseRequest $pr, ?User $by = null): PurchaseRequest
    {
        if ($by !== null && ! $this->access->canManageDraft($by, $pr)) {
            throw new ForbiddenActionException('You do not have permission to submit this purchase request.');
        }

        return DB::transaction(function () use ($pr, $by) {
            // The status check must use the locked row. Otherwise two retries
            // can both pass a stale draft check and recreate approval records.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if ($locked->status !== PurchaseRequestStatus::Draft) {
                throw new BusinessRuleException('Only draft PRs can be submitted.');
            }
            if ($by !== null && ! $this->access->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to submit this purchase request.');
            }

            $locked->loadMissing(['requester.employee']);
            $departmentId = $locked->department_id ?? $locked->requester?->employee?->department_id;

            // Generated PRs must have an accountable department before the
            // budget gate. Keeping them as drafts makes the missing ownership
            // visible to an operator instead of silently bypassing enforcement.
            if ($locked->is_auto_generated && $departmentId === null) {
                throw new BusinessRuleException(
                    'This automatically generated purchase request needs an owning department before submission.',
                );
            }
            if ($locked->department_id === null && $departmentId !== null) {
                $locked->forceFill(['department_id' => (int) $departmentId])->save();
            }

            // ADV6 — Pre-fill suppliers/prices BEFORE the total is computed, so
            // the approval amount and budget gate see the real figures rather
            // than a zero estimate the prefill is about to replace.
            $this->prefillSupplierOnItems($locked);

            $total = $locked->totalEstimatedAmount();

            if ($departmentId !== null) {
                $this->budget->assess($locked, (int) $departmentId, $total);
            }

            // Priority is the public/automation-facing urgency contract. The
            // legacy flag remains supported for internal callers and is
            // normalised before the workflow records are created.
            $isUrgent = (bool) $locked->is_urgent || $this->isUrgentPriority($this->priorityValue($locked->priority));
            if ($isUrgent && ! $locked->is_urgent) {
                $locked->forceFill(['is_urgent' => true])->save();
            }

            $this->approvals->submit($locked, 'purchase_request', $total);

            $locked->forceFill([
                'status'       => PurchaseRequestStatus::Pending,
                'submitted_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * Pre-fill suggested_vendor_id and a missing estimated price on PR items.
     *
     * Delegates to VendorSourcingService so submit-time suggestions match what
     * the conversion modal shows and what the auto-converter resolves. Before
     * this, submit looked only for a *preferred* approved supplier and left the
     * price to item.standard_cost, so an item with a real (non-preferred)
     * supplier or a quoted listing was silently unsourceable.
     */
    private function prefillSupplierOnItems(PurchaseRequest $pr): void
    {
        $pr->loadMissing('items.item');
        foreach ($pr->items as $item) {
            $changes = [];

            if ($item->item_id && ! $item->suggested_vendor_id) {
                $vendorId = $this->sourcing->suggestVendorId((int) $item->item_id);
                if ($vendorId) {
                    $changes['suggested_vendor_id'] = $vendorId;
                }
            }

            if ($item->item_id
                && ($item->estimated_unit_price === null || Money::lte((string) $item->estimated_unit_price, Money::zero()))) {
                $vendorId = $changes['suggested_vendor_id'] ?? $item->suggested_vendor_id;
                $price = $vendorId ? $this->sourcing->priceFor((int) $item->item_id, (int) $vendorId) : null;
                if ($price === null && $item->item) {
                    $price = (string) $item->item->standard_cost;
                }
                if ($price !== null && Money::gt($price, Money::zero())) {
                    $changes['estimated_unit_price'] = $price;
                }
            }

            if ($changes !== []) {
                $item->update($changes);
            }
        }
    }

    public function acknowledgeBudget(PurchaseRequest $pr, User $by): PurchaseRequest
    {
        if (! $this->access->canAcknowledgeBudget($by, $pr)) {
            throw new ForbiddenActionException('You do not have permission to acknowledge this purchase request budget warning.');
        }

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
            $this->assertMayDecide($by, $locked, 'approve');
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
     * Refuse only what ApprovalService cannot explain for itself.
     *
     * `if (! $this->access->canApprove(...)) throw new ForbiddenActionException(
     * 'You are not authorized to approve this purchase request.')` used to stand
     * here, and it flattened five different refusals into one sentence:
     * no permission, not pending, self-submitted, wrong role for the step, wrong
     * department. Two of those five are ApprovalService's, and it states them
     * precisely — "You cannot act on a record you submitted." and "Only users
     * with role 'department_head' can approve this step." — but the boolean ran
     * first, so an approver was told only that they were "not authorized" and
     * could not tell segregation of duties from being the wrong role, i.e. could
     * not tell "ask someone else" from "you are not the one for this step".
     * ApprovalRefusalRenderingTest pins those two sentences over HTTP.
     *
     * So this asserts the two rules the shared service genuinely cannot know —
     * the module permission and the department scope on the department_head step
     * — and lets step-role match, self-submission and "nothing pending" fall
     * through to ApprovalService, which owns their wording. `canApprove()` stays
     * as strict as it was; it answers a different question (should the SPA render
     * the button?) where one boolean is the right shape.
     *
     * @param 'approve'|'reject' $action
     */
    private function assertMayDecide(User $by, PurchaseRequest $pr, string $action): void
    {
        // Defence in depth: the route already carries
        // `permission:purchasing.pr.approve`, but bulkApprove and any future
        // internal caller reach this service without passing that middleware.
        if (! $by->hasPermission('purchasing.pr.approve')) {
            throw new ForbiddenActionException("You do not have permission to {$action} purchase requests.");
        }

        if (! $this->access->respectsDepartmentScope($by, $pr)) {
            throw new ForbiddenActionException("You can only {$action} purchase requests from your own department.");
        }
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
            $this->assertMayDecide($by, $locked, 'reject');
            $this->approvals->reject($locked, $by, $reason);
            $locked->forceFill(['status' => PurchaseRequestStatus::Rejected])->save();
            return $locked->fresh();
        });
    }

    public function cancel(PurchaseRequest $pr, ?User $by = null): PurchaseRequest
    {
        if ($by !== null && ! $this->access->canCancel($by, $pr)) {
            throw new ForbiddenActionException('You do not have permission to cancel this purchase request.');
        }

        return DB::transaction(function () use ($pr, $by) {
            // Lock-then-guard: without it, cancel could race a concurrent
            // approval and cancel an already-approved PR.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if (! in_array($locked->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)) {
                throw new BusinessRuleException('Cannot cancel a PR in this status.');
            }
            if ($by !== null && ! $this->access->canCancel($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to cancel this purchase request.');
            }
            $locked->forceFill(['status' => PurchaseRequestStatus::Cancelled])->save();
            return $locked->fresh();
        });
    }

    public function delete(PurchaseRequest $pr, ?User $by = null): void
    {
        if ($by !== null && ! $this->access->canManageDraft($by, $pr)) {
            throw new ForbiddenActionException('You do not have permission to delete this purchase request.');
        }
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessRuleException('Only draft PRs can be deleted.');
        }

        DB::transaction(function () use ($pr, $by): void {
            // Lock-then-guard, same shape as submit()/approve()/reject()/cancel().
            // A caller holding a draft instance that submit() has since advanced
            // would otherwise soft-delete a *pending* request, leaving its
            // is_current approval records pointing at a row no list query returns
            // — approvers keep a badge count for a PR nobody can open.
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->getKey());
            if ($locked->status !== PurchaseRequestStatus::Draft) {
                throw new BusinessRuleException('Only draft PRs can be deleted.');
            }
            if ($by !== null && ! $this->access->canManageDraft($by, $locked)) {
                throw new ForbiddenActionException('You do not have permission to delete this purchase request.');
            }
            $locked->delete();
        });
    }

    private function priorityValue(mixed $priority): string
    {
        return $priority instanceof PurchaseRequestPriority
            ? $priority->value
            : (string) $priority;
    }

    private function isUrgentPriority(string $priority): bool
    {
        return in_array($priority, [
            PurchaseRequestPriority::Urgent->value,
            PurchaseRequestPriority::Critical->value,
        ], true);
    }

    // moneySetting() was removed 2026-09-10 with its two consumers: the
    // dept-head auto-approve (PU-03, dead attribute) and the urgent-skip
    // (a step the PR workflow no longer contains).
}
