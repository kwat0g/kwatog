<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\B2B\Models\DeliverySchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeliveryScheduleReviewService
{
    /**
     * @param array{source?: string, status?: string, month?: string, search?: string, per_page?: int} $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = DeliverySchedule::query()
            ->with(['customer:id,name', 'vendor:id,name', 'purchaseOrder:id,po_number', 'reviewer:id,name']);

        if (! empty($filters['source'])) {
            $query->source($filters['source']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereHas('customer', fn ($c) => $c->where('name', 'ilike', $term))
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'ilike', $term));
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }

    public function show(DeliverySchedule $schedule): DeliverySchedule
    {
        return $schedule->load(['customer:id,name', 'vendor:id,name', 'purchaseOrder:id,po_number', 'reviewer:id,name']);
    }

    public function acknowledge(DeliverySchedule $schedule, User $by): DeliverySchedule
    {
        return DB::transaction(function () use ($schedule, $by): DeliverySchedule {
            $locked = DeliverySchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! $this->isSubmitted($locked->status)) {
                throw new BusinessRuleException('Only submitted delivery schedules can be acknowledged.');
            }

            $locked->update([
                'status' => DeliveryScheduleStatus::Acknowledged->value,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'reject_reason' => null,
            ]);

            return $locked->fresh();
        });
    }

    public function reject(DeliverySchedule $schedule, string $reason, User $by): DeliverySchedule
    {
        return DB::transaction(function () use ($schedule, $reason, $by): DeliverySchedule {
            $locked = DeliverySchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! $this->isSubmitted($locked->status)) {
                throw new BusinessRuleException('Only submitted delivery schedules can be rejected.');
            }

            $locked->update([
                'status' => DeliveryScheduleStatus::Rejected->value,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'reject_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    private function isSubmitted(mixed $status): bool
    {
        return $status instanceof DeliveryScheduleStatus
            ? $status->value === DeliveryScheduleStatus::Submitted->value
            : (string) $status === DeliveryScheduleStatus::Submitted->value;
    }
}
