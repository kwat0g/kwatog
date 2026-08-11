<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Jobs;

use App\Common\Services\SettingsService;
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
use Throwable;

/**
 * ADV8 — Maintenance Automation.
 * Execution primitive for the durable daily request that materialises
 * preventive WOs from schedules AND evaluates predictive-maintenance
 * thresholds for all machines.
 *
 * Runs:
 *   - All active hours/days schedules whose next_due_at <= now without an open WO.
 *   - All active machine-hour schedules whose running_hours_total >= interval_value.
 *   - All active mold-shot schedules at >= 100% of threshold without an open WO.
 *   - Predictive maintenance evaluation: condition readings exceeding thresholds
 *     that trigger corrective WOs.
 *
 * The system "user" attribution uses the first user in the configured
 * automation-actor roles; it aborts when no eligible actor exists.
 */
class GeneratePreventiveMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 120;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = self::TIMEOUT_SECONDS;

    public function handle(
        MaintenanceScheduleService $schedules,
        MaintenanceWorkOrderService $workOrders,
        PredictiveMaintenanceService $predictive,
        SettingsService $settings,
    ): void {
        $roles = array_values(array_filter((array) $settings->get('system.automation.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $systemUser = $roles === [] ? null : User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        if (! $systemUser) {
            throw new \RuntimeException('GeneratePreventiveMaintenanceJob: no configured automation actor found.');
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
        $threshold = $settings->requiredFloat('maintenance.mold_schedule.trigger_threshold_pct', 0, 100);
        foreach ($schedules->moldShotSchedulesAtOrAboveThreshold($threshold) as $schedule) {
            $workOrders->create([], $systemUser, $schedule);
        }

        // 4. Predictive maintenance — condition-based corrective WOs
        $triggeredCount = $predictive->evaluateAllMachines($systemUser);
        if ($triggeredCount > 0) {
            Log::info("GeneratePreventiveMaintenanceJob: predictive triggers created {$triggeredCount} corrective WOs.");
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GeneratePreventiveMaintenanceJob failed permanently.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
