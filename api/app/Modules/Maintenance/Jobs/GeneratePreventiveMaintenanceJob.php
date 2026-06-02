<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ADV8 — Maintenance Automation.
 * Daily cron that materialises preventive WOs from schedules AND evaluates
 * predictive-maintenance thresholds for all machines.
 *
 * Runs:
 *   - All active hours/days schedules whose next_due_at <= now without an open WO.
 *   - All active machine-hour schedules whose running_hours_total >= interval_value.
 *   - All active mold-shot schedules at >= 100% of threshold without an open WO.
 *   - Predictive maintenance evaluation: condition readings exceeding thresholds
 *     that trigger corrective WOs.
 *
 * The system "user" attribution: uses the first user with role slug
 * 'system_admin', falling back to the lowest user id.
 */
class GeneratePreventiveMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(
        MaintenanceScheduleService $schedules,
        MaintenanceWorkOrderService $workOrders,
        PredictiveMaintenanceService $predictive,
    ): void {
        $systemUser = User::query()->orderBy('id')->first();
        if (! $systemUser) {
            Log::warning('GeneratePreventiveMaintenanceJob: no system user found; aborting.');
            return;
        }

        // 1. Time-based (calendar hours / days)
        foreach ($schedules->dueNow() as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }

        // 2. Machine running-hours based
        foreach ($schedules->machineHourSchedulesAtOrAboveThreshold() as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }

        // 3. Mold-shot 100% threshold
        foreach ($schedules->moldShotSchedulesAtOrAboveThreshold(100.0) as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }

        // 4. Predictive maintenance — condition-based corrective WOs
        $triggeredCount = $predictive->evaluateAllMachines($systemUser);
        if ($triggeredCount > 0) {
            Log::info("GeneratePreventiveMaintenanceJob: predictive triggers created {$triggeredCount} corrective WOs.");
        }
    }
}
