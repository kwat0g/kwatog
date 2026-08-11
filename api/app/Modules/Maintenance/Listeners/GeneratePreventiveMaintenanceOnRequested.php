<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Executes the durable preventive/predictive-maintenance request. */
class GeneratePreventiveMaintenanceOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = GeneratePreventiveMaintenanceJob::TIMEOUT_SECONDS;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(PreventiveMaintenanceGenerationRequested $event): array
    {
        return [
            (new WithoutOverlapping('maintenance:preventive-generation'))
                ->releaseAfter(30)
                ->expireAfter(GeneratePreventiveMaintenanceJob::TIMEOUT_SECONDS + 300),
        ];
    }

    public function handle(PreventiveMaintenanceGenerationRequested $event): void
    {
        // Keep the existing job as the execution primitive. The durable event
        // owns initiation/recovery; this adapter avoids duplicating the
        // maintenance generation rules in a second code path.
        app()->call([new GeneratePreventiveMaintenanceJob, 'handle']);

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'preventive_maintenance_generated',
            "Preventive and predictive maintenance sweep {$event->requestId} completed.",
        );
    }

    public function failed(PreventiveMaintenanceGenerationRequested $event, Throwable $exception): void
    {
        Log::error('GeneratePreventiveMaintenanceOnRequested failed permanently.', [
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
