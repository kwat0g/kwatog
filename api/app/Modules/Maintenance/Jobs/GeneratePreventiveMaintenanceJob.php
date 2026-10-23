<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Jobs;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MachineHoursService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADV8 — Maintenance Automation.
 * Execution primitive for the durable daily request that materialises
 * preventive WOs from schedules AND evaluates predictive-maintenance
 * thresholds for all machines.
 *
 * Runs:
 *   - Active day schedules whose next_due_at <= now without an open WO.
 *   - All active machine-hour schedules whose runtime since the persisted
 *     baseline reaches interval_value.
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

    public function __construct()
    {
        // Lane: automation — daily bulk sweep.
        $this->onQueue('automation');
    }

    public function handle(
        MaintenanceScheduleService $schedules,
        MachineHoursService $machineHours,
        MaintenanceWorkOrderService $workOrders,
        PredictiveMaintenanceService $predictive,
        SettingsService $settings,
    ): void {
        if (! $settings->requiredBool('modules.maintenance')) {
            return;
        }
        $roles = array_values(array_filter((array) $settings->get('maintenance.automation.actor_roles', ['maintenance_supervisor']), static fn ($role): bool => is_string($role) && $role !== ''));
        $systemUser = null;
        foreach ($roles as $role) {
            $systemUser = User::query()->whereHas('role', fn ($query) => $query->where('slug', $role))
                ->where('is_active', true)->orderBy('id')->first();
            if ($systemUser !== null) {
                break;
            }
        }
        if (! $systemUser) {
            throw new \RuntimeException('GeneratePreventiveMaintenanceJob: no configured automation actor found.');
        }

        // The queue may run later than the scheduler's 01:30 recompute. Make
        // current runtime an invariant of this job as well as a cron ordering.
        $machineHours->recompute();

        // 1. Time-based (calendar hours / days)
        // 2. Machine running-hours based; 3. Mold-shot threshold
        $threshold = $settings->requiredFloat('maintenance.mold_schedule.trigger_threshold_pct', 0, 100);
        foreach ([$schedules->dueNow(), $schedules->machineHourSchedulesAtOrAboveThreshold(), $schedules->moldShotSchedulesAtOrAboveThreshold($threshold)] as $group) {
            foreach ($group as $schedule) {
                try {
                    $workOrders->create([], $systemUser, $schedule);
                } catch (ValidationException $exception) {
                    if (! isset($exception->errors()['schedule_id'])) {
                        throw $exception;
                    }
                } catch (ModelNotFoundException $exception) {
                    if ($exception->getModel() !== \App\Modules\Maintenance\Models\MaintenanceSchedule::class) {
                        throw $exception;
                    }
                }
            }
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
