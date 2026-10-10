<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
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
            abort_if($locked->vendor_id !== null, 410, 'Supplier delivery plans are retired and read-only.');

            if (! $this->isSubmitted($locked->status)) {
                throw new BusinessRuleException('Only submitted delivery schedules can be acknowledged.');
            }

            $locked->update([
                'status' => DeliveryScheduleStatus::Acknowledged->value,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'reject_reason' => null,
            ]);

            $this->notifyPortal($locked);
            return $locked->fresh();
        });
    }

    public function reject(DeliverySchedule $schedule, string $reason, User $by): DeliverySchedule
    {
        return DB::transaction(function () use ($schedule, $reason, $by): DeliverySchedule {
            $locked = DeliverySchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            abort_if($locked->vendor_id !== null, 410, 'Supplier delivery plans are retired and read-only.');

            if (! $this->isSubmitted($locked->status)) {
                throw new BusinessRuleException('Only submitted delivery schedules can be rejected.');
            }

            $locked->update([
                'status' => DeliveryScheduleStatus::Rejected->value,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'reject_reason' => $reason,
            ]);

            $this->notifyPortal($locked);
            return $locked->fresh();
        });
    }

    private function notifyPortal(DeliverySchedule $schedule): void
    {
        $realm = $schedule->vendor_id !== null ? 'supplier' : 'customer';
        $recipients = $realm === 'supplier'
            ? SupplierPortalUser::where('vendor_id', $schedule->vendor_id)->where('is_active', true)->get()
            : CustomerPortalUser::where('customer_id', $schedule->customer_id)->where('is_active', true)->get();
        $rejected = $schedule->status === DeliveryScheduleStatus::Rejected;
        app(NotificationService::class)->sendPortalInApp($recipients, 'delivery_schedule.'.$schedule->status->value, [
            'title' => 'Delivery schedule '.($rejected ? 'rejected' : 'acknowledged'),
            'message' => $rejected ? 'Review the rejection reason and submit a revised schedule. '.$schedule->reject_reason : 'Your schedule was acknowledged. Review it before arranging delivery.',
            'link_to' => '/portal/'.$realm.'/delivery-schedules', 'entity_type' => 'delivery_schedule', 'entity_id' => $schedule->hash_id,
        ], 'schedule:'.$schedule->id.':'.$schedule->status->value);
    }

    private function isSubmitted(mixed $status): bool
    {
        return $status instanceof DeliveryScheduleStatus
            ? $status->value === DeliveryScheduleStatus::Submitted->value
            : (string) $status === DeliveryScheduleStatus::Submitted->value;
    }
}
