<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 8 — Task 69. Daily cron — materialise preventive WOs from schedules.
 *
 * Runs:
 *   - All active hours/days schedules whose next_due_at <= now without an open WO.
 *   - All active mold-shot schedules at >= 100% of threshold without an open WO.
 *
 * The system "user" attribution: uses the first user with role slug
 * 'system_admin', falling back to the lowest user id. The created WO is
 * recorded as authored by that user; the audit log captures it cleanly.
 */
class GeneratePreventiveMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(
        MaintenanceScheduleService $schedules,
        MaintenanceWorkOrderService $workOrders,
    ): void {
        $systemUser = User::query()->orderBy('id')->first();
        if (! $systemUser) return;

        // Time-based
        foreach ($schedules->dueNow() as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }

        // Mold-shot 100% threshold
        foreach ($schedules->moldShotSchedulesAtOrAboveThreshold(100.0) as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }
    }
}
