<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Services\AlertEngineService;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;

/** Coordinates MRP demand, finite-capacity scheduling, and operator alerts. */
class MrpAutomationService
{
    public function __construct(
        private readonly MrpEngineService $engine,
        private readonly CapacityPlanningService $planner,
        private readonly AlertEngineService $alerts,
    ) {}

    /**
     * @param  list<int>|null  $salesOrderIds  Null means every active SO.
     */
    public function run(
        ?array $salesOrderIds,
        MrpRunTrigger $trigger,
        ?int $userId,
        string $reason,
    ): MrpRun {
        $run = $this->engine->runForActiveSalesOrders($trigger, $userId, $salesOrderIds);
        $evaluatedIds = collect((array) ($run->summary['per_sales_order'] ?? []))
            ->filter(static fn ($row): bool => is_array($row) && ! isset($row['error']))
            ->pluck('so_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();
        $scopeIds = $evaluatedIds;

        $scheduling = ['scheduled' => [], 'conflicts' => []];
        if ($scopeIds !== []) {
            $plannedIds = WorkOrder::query()
                ->whereIn('sales_order_id', $scopeIds)
                ->whereNotNull('mrp_plan_id')
                ->where('status', WorkOrderStatus::Planned->value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($plannedIds !== []) {
                $scheduling = $this->planner->run($plannedIds);
            }
        }

        $summary = (array) ($run->summary ?? []);
        $summary['trigger_reason'] = $reason;
        $summary['scheduling'] = $scheduling;
        $run->forceFill(['summary' => $summary])->save();

        $this->raiseAlerts($run->fresh(), $scheduling);

        return $run->fresh();
    }

    /** @param array{scheduled: list<array>, conflicts: list<array>} $scheduling */
    private function raiseAlerts(MrpRun $run, array $scheduling): void
    {
        $planningErrors = collect((array) ($run->summary['per_sales_order'] ?? []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['error']))
            ->values();

        if ($planningErrors->isNotEmpty()) {
            $this->alerts->raise(
                AlertType::MrpDataError,
                AlertSeverity::Warning,
                'MRP data requires correction',
                $planningErrors->count().' sales order(s) could not be planned because of BOM or demand data errors.',
                $run,
                ['run_id' => $run->id, 'errors' => $planningErrors->all()],
            );
        }

        if ((int) $run->shortages_found > 0) {
            $this->alerts->raise(
                AlertType::MrpShortage,
                AlertSeverity::Warning,
                'MRP material shortages require action',
                "MRP run {$run->id} found {$run->shortages_found} material shortage line(s).",
                $run,
                ['run_id' => $run->id, 'shortages_found' => (int) $run->shortages_found],
            );
        }

        if ($scheduling['conflicts'] !== []) {
            $this->alerts->raise(
                AlertType::MrpScheduleConflict,
                AlertSeverity::Warning,
                'MRP work orders need scheduling review',
                count($scheduling['conflicts']).' MRP work order(s) could not be placed on available capacity.',
                $run,
                ['run_id' => $run->id, 'conflicts' => $scheduling['conflicts']],
            );
        }

        if ($run->status === MrpRunStatus::Failed) {
            $this->alerts->raise(
                AlertType::MrpRunFailed,
                AlertSeverity::Critical,
                'Automatic MRP run failed',
                $run->error_message ?: "MRP run {$run->id} failed without an error message.",
                $run,
                ['run_id' => $run->id],
            );
        }
    }
}
