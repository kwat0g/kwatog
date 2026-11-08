<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\CustomerComplaintRealtimeService;

final class CustomerComplaintRealtimeObserver
{
    public function created(CustomerComplaint $complaint): void
    {
        app(CustomerComplaintRealtimeService::class)->stageForRecord($complaint, 'created');
    }

    public function updated(CustomerComplaint $complaint): void
    {
        $action = $complaint->wasChanged('status') || $complaint->wasChanged('ncr_handoff_status')
            ? 'status_changed'
            : 'updated';
        app(CustomerComplaintRealtimeService::class)->stageForRecord($complaint, $action);
    }

    public function deleted(CustomerComplaint $complaint): void
    {
        app(CustomerComplaintRealtimeService::class)->stageForRecord($complaint, 'deleted');
    }
}
