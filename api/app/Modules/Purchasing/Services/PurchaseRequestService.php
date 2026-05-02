<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
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
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = PurchaseRequest::query()->with([
            'requester:id,name,role_id',
            'department:id,name,code',
            'items.item:id,code,name,unit_of_measure',
        ]);

        if (! empty($filters['status']))   $q->where('status', $filters['status']);
        if (! empty($filters['priority'])) $q->where('priority', $filters['priority']);
        if (isset($filters['is_auto_generated']) && $filters['is_auto_generated'] !== '') {
            $q->where('is_auto_generated', filter_var($filters['is_auto_generated'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('pr_number', 'ilike', '%'.$filters['search'].'%');
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
            'approvalRecords',
            'purchaseOrders:id,po_number,status,vendor_id,total_amount,purchase_request_id',
            'purchaseOrders.vendor:id,name',
        ]);
    }

    public function create(array $data, User $by): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $by) {
            $pr = PurchaseRequest::create([
                'pr_number'         => $this->sequences->generate('pr'),
                'requested_by'      => $by->id,
                'department_id'     => $data['department_id'] ?? $by->employee?->department_id ?? null,
                'date'              => $data['date'] ?? now()->toDateString(),
                'reason'            => $data['reason'] ?? null,
                'priority'          => $data['priority'] ?? PurchaseRequestPriority::Normal->value,
                'status'            => PurchaseRequestStatus::Draft,
                'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
            ]);
            foreach (($data['items'] ?? []) as $row) {
                $itemId = ! empty($row['item_id'])
                    ? (HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'])
                    : null;
                PurchaseRequestItem::create([
                    'purchase_request_id'  => $pr->id,
                    'item_id'              => $itemId,
                    'description'          => $row['description'],
                    'quantity'             => $row['quantity'],
                    'unit'                 => $row['unit'] ?? null,
                    'estimated_unit_price' => $row['estimated_unit_price'] ?? null,
                    'purpose'              => $row['purpose'] ?? null,
                ]);
            }
            return $this->show($pr);
        });
    }

    public function update(PurchaseRequest $pr, array $data): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new RuntimeException('Only draft PRs can be edited.');
        }
        return DB::transaction(function () use ($pr, $data) {
            $pr->update([
                'reason'    => $data['reason']   ?? $pr->reason,
                'priority'  => $data['priority'] ?? $pr->priority,
                'date'      => $data['date']     ?? $pr->date,
            ]);
            if (isset($data['items'])) {
                $pr->items()->delete();
                foreach ($data['items'] as $row) {
                    $itemId = ! empty($row['item_id'])
                        ? (HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'])
                        : null;
                    PurchaseRequestItem::create([
                        'purchase_request_id'  => $pr->id,
                        'item_id'              => $itemId,
                        'description'          => $row['description'],
                        'quantity'             => $row['quantity'],
                        'unit'                 => $row['unit'] ?? null,
                        'estimated_unit_price' => $row['estimated_unit_price'] ?? null,
                        'purpose'              => $row['purpose'] ?? null,
                    ]);
                }
            }
            return $this->show($pr->fresh());
        });
    }

    public function submit(PurchaseRequest $pr): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new RuntimeException('Only draft PRs can be submitted.');
        }
        return DB::transaction(function () use ($pr) {
            $total = (float) $pr->totalEstimatedAmount();
            $this->approvals->submit($pr, 'purchase_request', $total);
            $pr->update([
                'status'       => PurchaseRequestStatus::Pending,
                'submitted_at' => now(),
            ]);
            return $pr->fresh();
        });
    }

    public function approve(PurchaseRequest $pr, User $by, ?string $remarks = null): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Pending) {
            throw new RuntimeException('Only pending PRs can be approved.');
        }
        return DB::transaction(function () use ($pr, $by, $remarks) {
            $this->approvals->approve($pr, $by, $remarks);
            if ($this->approvals->isFullyApproved($pr)) {
                $pr->update([
                    'status'      => PurchaseRequestStatus::Approved,
                    'approved_at' => now(),
                ]);
            }
            return $pr->fresh();
        });
    }

    public function reject(PurchaseRequest $pr, User $by, string $reason): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Pending) {
            throw new RuntimeException('Only pending PRs can be rejected.');
        }
        return DB::transaction(function () use ($pr, $by, $reason) {
            $this->approvals->reject($pr, $by, $reason);
            $pr->update(['status' => PurchaseRequestStatus::Rejected]);
            return $pr->fresh();
        });
    }

    public function cancel(PurchaseRequest $pr): PurchaseRequest
    {
        if (! in_array($pr->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)) {
            throw new RuntimeException('Cannot cancel a PR in this status.');
        }
        $pr->update(['status' => PurchaseRequestStatus::Cancelled]);
        return $pr->fresh();
    }

    public function delete(PurchaseRequest $pr): void
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            throw new RuntimeException('Only draft PRs can be deleted.');
        }
        $pr->delete();
    }
}
