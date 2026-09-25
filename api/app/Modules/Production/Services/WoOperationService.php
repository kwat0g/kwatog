<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Enums\ProductionLogEvent;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionLog;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Services\InspectionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint P10 — Task 11. WO Operation lifecycle service.
 *
 * Manages the lifecycle of individual operations within a work order,
 * from setup through production to completion. Each state transition
 * is recorded as a ProductionLog entry for full traceability.
 *
 * Lifecycle per operation:
 *   pending → setup → in_progress → (paused ↔ in_progress)* → completed
 *                                                              │
 *   pending/setup ─────────────────→ in_progress ──────────────┘
 *                                                              │
 *   pending/setup/in_progress/paused ─────────────────────→ skipped
 *   (completed and skipped are NOT skippable — see skipOperation)
 */
class WoOperationService
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly WorkOrderMaterialUsageService $materialUsage,
    ) {}

    /**
     * Generate WO operations from the product's active routing.
     *
     * If no active routing exists for the WO's product, this is a no-op
     * (work orders without routings are valid — simple single-step production).
     */
    public function generateFromRouting(WorkOrder $wo): void
    {
        DB::transaction(function () use ($wo) {
            $routing = ProductRouting::query()
                ->where('product_id', $wo->product_id)
                ->where('is_active', true)
                ->with('operations')
                ->first();

            if (! $routing || $routing->operations->isEmpty()) {
                return;
            }

            $plannedWindows = $this->plannedWindows($wo, $routing->operations);
            foreach ($routing->operations as $routingOp) {
                $operation = WoOperation::query()->firstOrCreate(
                    [
                        'work_order_id' => $wo->id,
                        'routing_operation_id' => $routingOp->id,
                    ],
                    [
                        'sequence' => $routingOp->sequence,
                        'operation_name' => $routingOp->operation_name,
                        'machine_id' => $routingOp->machine_id,
                        'mold_id' => $routingOp->mold_id,
                        'qty_planned' => $wo->quantity_target,
                        'status' => WoOperationStatus::Pending,
                        'planned_start' => $plannedWindows[$routingOp->id]['start'] ?? null,
                        'planned_end' => $plannedWindows[$routingOp->id]['end'] ?? null,
                    ],
                );

                // Never rewrite an operation that has started, but repair
                // legacy pending rows that were generated before scheduling
                // windows were populated.
                if ($operation->status === WoOperationStatus::Pending
                    && isset($plannedWindows[$routingOp->id])) {
                    $operation->update([
                        'planned_start' => $plannedWindows[$routingOp->id]['start'],
                        'planned_end' => $plannedWindows[$routingOp->id]['end'],
                    ]);
                }
            }
        });
    }

    /**
     * Start the setup phase for an operation.
     *
     * Transition: Pending → Setup
     */
    public function startSetup(WoOperation $op, Employee $operator): void
    {
        $this->assertStatus($op, [WoOperationStatus::Pending], 'start setup');

        DB::transaction(function () use ($op, $operator) {
            // Lock-then-guard: re-read the authoritative row so a concurrent
            // transition holding a stale model cannot double-advance it.
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Pending], 'start setup');
            $this->assertParentInProgress($locked);

            $locked->update([
                'status' => WoOperationStatus::Setup,
                'setup_start' => Carbon::now(),
                'operator_id' => $operator->id,
            ]);

            $this->log($locked, $operator, ProductionLogEvent::StartSetup);
        });
    }

    /**
     * End the setup phase for an operation.
     *
     * Transition: Setup → Setup (with setup_end recorded; still needs startOperation)
     */
    public function endSetup(WoOperation $op): void
    {
        $this->assertStatus($op, [WoOperationStatus::Setup], 'end setup');

        DB::transaction(function () use ($op) {
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Setup], 'end setup');
            $this->assertParentInProgress($locked);

            $locked->update([
                'setup_end' => Carbon::now(),
            ]);

            $this->log($locked, null, ProductionLogEvent::EndSetup);
        });
    }

    /**
     * Start production on an operation.
     *
     * Transition: Pending|Setup → InProgress
     *
     * Validates that all preceding operations (lower sequence, same WO)
     * are either Completed or Skipped — enforcing sequential execution.
     */
    public function startOperation(WoOperation $op, Employee $operator): void
    {
        $this->assertStatus($op, [WoOperationStatus::Pending, WoOperationStatus::Setup], 'start');
        $this->assertPreviousCompleted($op);

        DB::transaction(function () use ($op, $operator) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($op->work_order_id);
            $this->assertMaterialCoverageForOperation($workOrder);

            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Pending, WoOperationStatus::Setup], 'start');
            $this->assertParentInProgress($locked);
            $this->assertPreviousCompleted($locked);

            $locked->update([
                'status' => WoOperationStatus::InProgress,
                'actual_start' => Carbon::now(),
                'operator_id' => $operator->id,
            ]);

            $this->log($locked, $operator, ProductionLogEvent::StartProduction);
        });
    }

    /**
     * Pause an in-progress operation.
     *
     * Transition: InProgress → Paused
     */
    public function pauseOperation(WoOperation $op): void
    {
        $this->assertStatus($op, [WoOperationStatus::InProgress], 'pause');

        DB::transaction(function () use ($op) {
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::InProgress], 'pause');
            $this->assertParentInProgress($locked);

            $locked->update([
                'status' => WoOperationStatus::Paused,
            ]);

            $this->log($locked, null, ProductionLogEvent::Pause);
        });
    }

    /**
     * Resume a paused operation.
     *
     * Transition: Paused → InProgress
     */
    public function resumeOperation(WoOperation $op, Employee $operator): void
    {
        $this->assertStatus($op, [WoOperationStatus::Paused], 'resume');

        DB::transaction(function () use ($op, $operator) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($op->work_order_id);
            $this->assertMaterialCoverageForOperation($workOrder);

            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Paused], 'resume');
            $this->assertParentInProgress($locked);

            $locked->update([
                'status' => WoOperationStatus::InProgress,
                'operator_id' => $operator->id,
            ]);

            $this->log($locked, $operator, ProductionLogEvent::Resume);
        });
    }

    /**
     * Record production output (good quantity) and optional scrap.
     *
     * Only allowed when the operation is InProgress.
     */
    public function recordOutput(WoOperation $op, float $qty, float $scrap = 0, ?string $scrapReason = null): void
    {
        $this->assertStatus($op, [WoOperationStatus::InProgress], 'record output');

        DB::transaction(function () use ($op, $qty, $scrap, $scrapReason) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($op->work_order_id);

            // Lock-then-guard: accumulate on the authoritative row. Without the
            // lock, two concurrent output records both read the old qty_completed
            // and one record's output is lost (P32/P33).
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::InProgress], 'record output');
            $this->assertParentInProgress($locked);

            $this->materialUsage->assertProductionCoverage(
                $workOrder,
                pendingOperationId: (int) $locked->id,
                pendingOperationUnits: number_format($qty + $scrap, 4, '.', ''),
            );

            $previousOp = WoOperation::query()
                ->where('work_order_id', $locked->work_order_id)
                ->where('sequence', '<', $locked->sequence)
                ->where('status', '!=', WoOperationStatus::Skipped->value)
                ->orderByDesc('sequence')
                ->first();

            $newCompleted = bcadd((string) $locked->qty_completed, (string) $qty, 4);
            if ($previousOp !== null && $previousOp->status === WoOperationStatus::Completed) {
                if (bccomp($newCompleted, (string) $previousOp->qty_completed, 4) > 0) {
                    throw new BusinessRuleException(
                        "Cannot record output of {$qty} (total {$newCompleted}) exceeding previous operation {$previousOp->sequence} completed quantity of {$previousOp->qty_completed}."
                    );
                }
            }

            $updates = [
                'qty_completed' => $newCompleted,
                'qty_scrapped' => bcadd((string) $locked->qty_scrapped, (string) $scrap, 4),
            ];

            if ($scrapReason !== null) {
                $updates['scrap_reason'] = $scrapReason;
            }

            $locked->update($updates);

            $this->log($locked, null, ProductionLogEvent::RecordOutput, $qty);

            if ($scrap > 0) {
                $this->log($locked, null, ProductionLogEvent::RecordScrap, $scrap, $scrapReason);
            }

            ProductionDashboardService::forgetCache();
        });
    }

    /**
     * Complete an operation.
     *
     * Transition: InProgress → Completed
     *
     * If the routing operation has qc_required = true, an idempotent in-process
     * inspection is created or reused before the operation completes.
     */
    public function completeOperation(WoOperation $op): void
    {
        $this->assertStatus($op, [WoOperationStatus::InProgress], 'complete');

        DB::transaction(function () use ($op) {
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::InProgress], 'complete');
            $this->assertParentInProgress($locked);
            $this->assertFinalOperationReconciled($locked);

            $workOrder = $locked->load('workOrder.creator')->workOrder;
            if ($locked->routingOperation?->qc_required) {
                $creator = $workOrder->creator;
                if (! $creator) {
                    throw new BusinessRuleException(
                        "Work order {$workOrder->wo_number} has no creator to attribute the required in-process QC inspection."
                    );
                }

                $this->inspections->create([
                    'stage' => InspectionStage::InProcess->value,
                    'product_id' => (int) $workOrder->product_id,
                    'batch_quantity' => max(1, (int) ($locked->qty_completed ?: $workOrder->quantity_target)),
                    'entity_type' => InspectionEntityType::WorkOrder->value,
                    'entity_id' => $workOrder->id,
                    'notes' => "Required by operation {$locked->operation_name} completion.",
                ], $creator);
            }

            $locked->update([
                'status' => WoOperationStatus::Completed,
                'actual_end' => Carbon::now(),
            ]);

            $this->log($locked, null, ProductionLogEvent::EndProduction);

            ProductionDashboardService::forgetCache();
        });
    }

    /**
     * Skip an operation with a reason.
     *
     * Deliberately permissive about the source state — skipping a routing step
     * that turns out not to be needed is legitimate from Pending, Setup,
     * InProgress or Paused. It is NOT legitimate from Completed: that overwrote
     * a finished operation's status while leaving its qty_completed and
     * actual_end in place, producing a row asserting both that N parts were
     * completed and that the operation never ran. Skipped is refused too, so a
     * repeat call cannot append a second skip log for one logical action.
     */
    public function skipOperation(WoOperation $op, string $reason, Employee $operator): void
    {
        $this->assertSkippable($op);

        DB::transaction(function () use ($op, $reason, $operator) {
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertSkippable($locked);
            $this->assertParentInProgress($locked);
            $locked->update([
                'status' => WoOperationStatus::Skipped,
                'notes' => $reason,
            ]);

            $this->log($locked, $operator, ProductionLogEvent::Skip, notes: $reason);

            ProductionDashboardService::forgetCache();
        });
    }

    /** A completed operation's record must not be overwritten by a skip. */
    private function assertSkippable(WoOperation $op): void
    {
        $status = $op->status instanceof WoOperationStatus
            ? $op->status
            : WoOperationStatus::tryFrom((string) $op->status);

        if (in_array($status, [WoOperationStatus::Completed, WoOperationStatus::Skipped], true)) {
            throw new BusinessRuleException(
                "Cannot skip an operation that is already '{$status->value}'."
            );
        }
    }

    /**
     * Get machine schedule for a date range, grouped by machine_id.
     *
     * Returns operations that have a machine assigned and fall within
     * the given time window (based on planned_start).
     */
    public function getScheduleByMachine(Carbon $from, Carbon $to): Collection
    {
        return WoOperation::query()
            ->whereNotNull('machine_id')
            ->whereBetween('planned_start', [$from, $to])
            ->with(['workOrder.product:id,part_number,name', 'machine', 'operator:id,first_name,last_name'])
            ->orderBy('planned_start')
            ->get()
            ->groupBy('machine_id');
    }

    /* ─── Private helpers ──────────────────────────────────────── */

    /**
     * Assert that the operation is in one of the allowed statuses.
     *
     * @param  WoOperationStatus[]  $allowed
     * @param  string  $action  Human-readable action name for the error
     *
     * @throws BusinessRuleException
     */
    private function assertStatus(WoOperation $op, array $allowed, string $action): void
    {
        if (! in_array($op->status, $allowed, true)) {
            $allowedLabels = implode(', ', array_map(fn (WoOperationStatus $s) => $s->value, $allowed));
            throw new BusinessRuleException(
                "Cannot {$action}: operation is '{$op->status->value}', must be one of [{$allowedLabels}]."
            );
        }
    }

    /**
     * Assert that all preceding operations (lower sequence, same WO)
     * are Completed or Skipped.
     *
     * @throws BusinessRuleException
     */
    private function assertPreviousCompleted(WoOperation $op): void
    {
        $blocking = WoOperation::query()
            ->where('work_order_id', $op->work_order_id)
            ->where('sequence', '<', $op->sequence)
            ->whereNotIn('status', [
                WoOperationStatus::Completed->value,
                WoOperationStatus::Skipped->value,
            ])
            ->exists();

        if ($blocking) {
            throw new BusinessRuleException(
                'Cannot start operation: previous operations are not yet completed or skipped.'
            );
        }
    }

    private function assertParentInProgress(WoOperation $op): void
    {
        $workOrder = WorkOrder::query()
            ->lockForUpdate()
            ->find($op->work_order_id);

        if (! $workOrder) {
            throw new BusinessRuleException('Cannot execute an operation without its parent work order.');
        }

        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            throw new BusinessRuleException(
                "Cannot execute an operation while the parent work order is '{$workOrder->status?->value}'."
            );
        }
    }

    private function assertMaterialCoverageForOperation(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            throw new BusinessRuleException(
                "Cannot execute an operation while the parent work order is '{$workOrder->status?->value}'."
            );
        }

        $this->materialUsage->assertProductionCoverage($workOrder);
    }

    /**
     * Final operation quantities are an operation ledger, while finished-good
     * output is the canonical WO ledger. Refuse to complete the final step when
     * those ledgers diverge instead of silently reporting two production totals.
     */
    private function assertFinalOperationReconciled(WoOperation $op): void
    {
        $hasLaterOperation = WoOperation::query()
            ->where('work_order_id', $op->work_order_id)
            ->where('sequence', '>', $op->sequence)
            ->where('status', '!=', WoOperationStatus::Skipped->value)
            ->exists();
        if ($hasLaterOperation) {
            return;
        }

        $totals = WorkOrderOutput::query()
            ->where('work_order_id', $op->work_order_id)
            ->selectRaw('COALESCE(SUM(good_count), 0) AS good, COALESCE(SUM(reject_count), 0) AS reject')
            ->first();

        if (bccomp((string) $op->qty_completed, (string) ($totals->good ?? 0), 4) !== 0
            || bccomp((string) $op->qty_scrapped, (string) ($totals->reject ?? 0), 4) !== 0) {
            throw new BusinessRuleException(
                'The final operation output does not reconcile with the work-order output ledger. Record or correct the canonical output before completing the operation.'
            );
        }
    }

    /** @return array<int, array{start: Carbon, end: Carbon}> */
    private function plannedWindows(WorkOrder $wo, Collection $operations): array
    {
        if (! $wo->planned_start || ! $wo->planned_end) {
            return [];
        }

        $start = $wo->planned_start instanceof Carbon
            ? $wo->planned_start->copy()
            : Carbon::parse($wo->planned_start);
        $end = $wo->planned_end instanceof Carbon
            ? $wo->planned_end->copy()
            : Carbon::parse($wo->planned_end);
        $windowMinutes = max(0, $start->diffInMinutes($end, true));
        $weights = $operations->mapWithKeys(function ($operation): array {
            $weight = (float) $operation->setup_time_minutes + (float) $operation->cycle_time_minutes;

            return [$operation->id => max(0.0001, $weight)];
        });
        $totalWeight = (float) $weights->sum();
        $cursor = $start->copy();
        $windows = [];

        foreach ($operations as $operation) {
            $duration = $operation === $operations->last()
                ? $end->diffInMinutes($cursor, true)
                : (int) round($windowMinutes * ((float) $weights[$operation->id] / $totalWeight));
            $operationEnd = $operation === $operations->last()
                ? $end->copy()
                : $cursor->copy()->addMinutes(max(0, $duration));
            $windows[$operation->id] = ['start' => $cursor->copy(), 'end' => $operationEnd];
            $cursor = $operationEnd;
        }

        return $windows;
    }

    /**
     * Create a production log entry.
     */
    private function log(
        WoOperation $op,
        ?Employee $operator,
        ProductionLogEvent $event,
        ?float $qtyValue = null,
        ?string $downtimeReason = null,
        ?string $notes = null,
    ): void {
        ProductionLog::create([
            'wo_operation_id' => $op->id,
            'operator_id' => $operator?->id ?? $op->operator_id,
            'event_type' => $event,
            'qty_value' => $qtyValue,
            'downtime_reason' => $downtimeReason,
            'notes' => $notes,
            'recorded_at' => Carbon::now(),
        ]);
    }
}
