<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Services\CustomerComplaintRealtimeService;

final class Complaint8DReportRealtimeObserver
{
    public function created(Complaint8DReport $report): void
    {
        app(CustomerComplaintRealtimeService::class)->stageForRecord($report, 'updated');
    }

    public function updated(Complaint8DReport $report): void
    {
        app(CustomerComplaintRealtimeService::class)->stageForRecord($report, 'updated');
    }

    public function deleted(Complaint8DReport $report): void
    {
        app(CustomerComplaintRealtimeService::class)->stageForRecord($report, 'updated');
    }
}
