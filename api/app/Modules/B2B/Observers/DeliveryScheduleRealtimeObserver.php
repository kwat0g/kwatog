<?php

declare(strict_types=1);

namespace App\Modules\B2B\Observers;

use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Services\DeliveryScheduleRealtimeService;

/** Captures schedule writes from portal services, review services, and other Eloquent paths. */
final class DeliveryScheduleRealtimeObserver
{
    /** @var list<string> */
    private const FRESHNESS_FIELDS = [
        'customer_id',
        'vendor_id',
        'purchase_order_id',
        'month',
        'planned_delivery_date',
        'status',
        'lines',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
        'cancelled_at',
        'cancel_reason',
        'cancelled_by_portal_user_id',
    ];

    public function created(DeliverySchedule $schedule): void
    {
        $this->stage($schedule, 'created');
    }

    public function updated(DeliverySchedule $schedule): void
    {
        if (! $schedule->wasChanged(self::FRESHNESS_FIELDS)) {
            return;
        }

        $this->stage($schedule, $schedule->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(DeliverySchedule $schedule): void
    {
        $this->stage($schedule, 'deleted');
    }

    private function stage(DeliverySchedule $schedule, string $action): void
    {
        app(DeliveryScheduleRealtimeService::class)->stage($schedule, $action);
    }
}
