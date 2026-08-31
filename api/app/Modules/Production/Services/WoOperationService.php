<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Production\Enums\ProductionLogEvent;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionLog;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Common\Exceptions\BusinessRuleException;

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

            foreach ($routing->operations as $routingOp) {
                WoOperation::query()->firstOrCreate(
                    [
                        'work_order_id'        => $wo->id,
                        'routing_operation_id' => $routingOp->id,
                    ],
                    [
                        'sequence'      => $routingOp->sequence,
                        'operation_name'=> $routingOp->operation_name,
                        'machine_id'    => $routingOp->machine_id,
                        'mold_id'       => $routingOp->mold_id,
                        'qty_planned'   => $wo->quantity_target,
                        'status'        => WoOperationStatus::Pending,
                    ],
                );
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
                'status'      => WoOperationStatus::Setup,
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
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Pending, WoOperationStatus::Setup], 'start');
            $this->assertParentInProgress($locked);
            $this->assertPreviousCompleted($locked);

            $locked->update([
                'status'      => WoOperationStatus::InProgress,
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
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::Paused], 'resume');
            $this->assertParentInProgress($locked);

            $locked->update([
                'status'      => WoOperationStatus::InProgress,
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
            // Lock-then-guard: accumulate on the authoritative row. Without the
            // lock, two concurrent output records both read the old qty_completed
            // and one record's output is lost (P32/P33).
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::InProgress], 'record output');
            $this->assertParentInProgress($locked);

            $updates = [
                'qty_completed' => bcadd((string) $locked->qty_completed, (string) $qty, 4),
                'qty_scrapped'  => bcadd((string) $locked->qty_scrapped, (string) $scrap, 4),
            ];

            if ($scrapReason !== null) {
                $updates['scrap_reason'] = $scrapReason;
            }

            $locked->update($updates);

            $this->log($locked, null, ProductionLogEvent::RecordOutput, $qty);

            if ($scrap > 0) {
                $this->log($locked, null, ProductionLogEvent::RecordScrap, $scrap, $scrapReason);
            }
        });
    }

    /**
     * Complete an operation.
     *
     * Transition: InProgress → Completed
     *
     * If the routing operation has qc_required = true, a QC trigger
     * is logged (actual event integration comes in a later task).
     */
    public function completeOperation(WoOperation $op): void
    {
        $this->assertStatus($op, [WoOperationStatus::InProgress], 'complete');

        DB::transaction(function () use ($op) {
            $locked = WoOperation::query()->lockForUpdate()->findOrFail($op->getKey());
            $this->assertStatus($locked, [WoOperationStatus::InProgress], 'complete');
            $this->assertParentInProgress($locked);

            $locked->update([
                'status'     => WoOperationStatus::Completed,
                'actual_end' => Carbon::now(),
            ]);

            $this->log($locked, null, ProductionLogEvent::EndProduction);

            // QC trigger — routing operation may require quality check after completion.
            if ($locked->routingOperation && $locked->routingOperation->qc_required) {
                Log::info('WoOperation QC trigger: operation requires quality check', [
                    'wo_operation_id' => $locked->id,
                    'work_order_id'   => $locked->work_order_id,
                    'operation_name'  => $locked->operation_name,
                ]);
            }
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
                'notes'  => $reason,
            ]);

            $this->log($locked, $operator, ProductionLogEvent::Skip, notes: $reason);
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
     * @param  WoOperation            $op
     * @param  WoOperationStatus[]    $allowed
     * @param  string                 $action   Human-readable action name for the error
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
            'operator_id'     => $operator?->id ?? $op->operator_id,
            'event_type'      => $event,
            'qty_value'       => $qtyValue,
            'downtime_reason' => $downtimeReason,
            'notes'           => $notes,
            'recorded_at'     => Carbon::now(),
        ]);
    }
}
