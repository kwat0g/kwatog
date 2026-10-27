<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Explicit repair actions without repeating the original manufacturing run. */
class WorkOrderReworkService
{
    public function start(WorkOrder $wo, NonConformanceReport $ncr, User $by, ?int $machineId = null, ?int $moldId = null, array $execution = []): NonConformanceReport
    {
        return DB::transaction(function () use ($wo, $ncr, $by, $machineId, $moldId, $execution) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $ncr = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->id);
            $this->assertRepair($wo, $ncr);
            if ($wo->status->value !== 'completed' || $ncr->rework_started_at) {
                throw new BusinessRuleException('Finish original production before starting repair; repair can only start once.');
            }
            $slot = app(WorkOrderAssignmentService::class)->schedule($wo, $ncr->id);
            if (! $slot) {
                throw new BusinessRuleException('No repair slot approved. Auto schedule before starting.');
            }
            if (($machineId !== null && $machineId !== $slot->machine_id) || ($moldId !== null && $moldId !== $slot->mold_id)) {
                throw new BusinessRuleException('Repair equipment differs from the reviewed NCR slot.');
            }
            $machineId = $slot->machine_id;
            $moldId = $slot->mold_id;
            if ($machineId !== null) {
                $machine = Machine::query()->lockForUpdate()->findOrFail($machineId);
                $mold = Mold::query()->lockForUpdate()->findOrFail($moldId);
                app(ProductionResourceService::class)->acquire($wo, $machine, $mold, $ncr->id);
            } else {
                app(ProductionResourceService::class)->assertExecutionSlot($wo, ncrId: $ncr->id);
            }
            if (array_key_exists('expected_crew_id', $execution) && app(WorkOrderAssignmentService::class)->currentCrew($wo, $ncr->id)?->id !== $execution['expected_crew_id']) {
                throw new BusinessRuleException('Repair crew changed. Review the current repair crew.');
            }
            app(WorkOrderAssignmentService::class)->assertReadyForStart($wo, $ncr->id, true, $by, $execution['presence_confirmed_employee_ids'] ?? []);
            $ncr->forceFill(['rework_started_at' => now(), 'rework_started_by' => $by->id,
                'rework_machine_id' => $machineId, 'rework_mold_id' => $moldId])->save();

            return $ncr;
        });
    }

    public function reviewSlot(WorkOrder $wo, NonConformanceReport $ncr, Carbon $start, Carbon $end, User $by, string $reason, ?int $machineId = null, ?int $moldId = null, ?array $review = null): void
    {
        DB::transaction(function () use ($wo, $ncr, $start, $end, $by, $reason, $machineId, $moldId, $review) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $ncr = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->id);
            $this->assertRepair($wo, $ncr);
            if ($wo->status->value !== 'completed' || $ncr->rework_started_at) {
                throw new BusinessRuleException('Review repair timing after original production ends and before repair starts.');
            }
            app(ProductionResourceService::class)->assertDeadlineReview($wo, $end, $by, $review ?? [], $ncr->id);
            $crews = app(WorkOrderAssignmentService::class);
            if ($review !== null && app(ProductionResourceService::class)->reviewedSlotReplay($wo, $review, $by->id, $ncr->id, $machineId, $moldId)) {
                if (isset($review['employee_ids'])) {
                    $crew = $crews->currentCrew($wo, $ncr->id);
                    if (! $crew || $crew->assigned_by !== $by->id || $crew->recorder_employee_id !== $review['recorder_employee_id']
                        || $crew->members->pluck('employee_id')->sort()->values()->all() !== collect($review['employee_ids'])->sort()->values()->all()) {
                        throw new BusinessRuleException('Repair crew changed. Refresh the suggestion before approving.');
                    }
                }

                return;
            }
            $holiday = false;
            for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
                $holiday = $holiday || $crews->isHoliday($day);
            }
            if ($holiday && (empty($review['holiday_authorized']) || ! $by->hasPermission('production.work_orders.lifecycle'))) {
                throw new BusinessRuleException('Holiday production requires explicit Production Management authorization for this interval.');
            }
            if (isset($review['employee_ids'])) {
                if (($machineId === null) !== ($moldId === null)) {
                    throw new BusinessRuleException('Select both machine and mold, or use manual repair.');
                }
                if ($machineId !== null) {
                    app(ProductionResourceService::class)->assertReservation($wo, Machine::findOrFail($machineId), Mold::findOrFail($moldId), $start, $end, ncrId: $ncr->id,
                        allowLate: $wo->planned_end && $end->gt($wo->planned_end));
                }
                $crews->assign($wo, $review + ['rework_ncr_id' => $ncr->id, 'minimum_workers' => count($review['employee_ids']),
                    'maximum_workers' => count($review['employee_ids']), '_reviewed_slot' => new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end]),
                    '_allow_holiday' => $holiday], $by);
            }
            app(ProductionResourceService::class)->reviewSlot($wo, $start, $end, $by->id, $reason, $ncr->id, $machineId, $moldId, $holiday, $review ?? []);
        }, 3);
    }

    public function record(WorkOrder $wo, NonConformanceReport $ncr, array $data, User $by, ?string $idempotencyKey = null): WorkOrderOutput
    {
        return app(WorkOrderOutputService::class)->record($wo, $data, $by->id, $idempotencyKey, $ncr->id);
    }

    public function finish(WorkOrder $wo, NonConformanceReport $ncr, User $by): NonConformanceReport
    {
        return DB::transaction(function () use ($wo, $ncr, $by) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $ncr = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->id);
            $this->assertRepair($wo, $ncr);
            if (! $ncr->rework_started_at || $ncr->rework_finished_at) {
                throw new BusinessRuleException('Start repair before finishing; finished repair cannot be reopened.');
            }
            $count = $wo->outputs()->where('rework_ncr_id', $ncr->id)->sum(DB::raw('good_count + reject_count'));
            if ((int) $count !== (int) $ncr->confirmed_rework_quantity) {
                throw new BusinessRuleException('Record the full confirmed repair quantity before finishing repair.');
            }
            $ncr->forceFill(['rework_finished_at' => now(), 'rework_finished_by' => $by->id])->save();
            if ($wo->usesFinishFirstQc()) {
                $outputs = $wo->outputs()->where('rework_ncr_id', $ncr->id)->where('good_count', '>', 0)->orderBy('id')->lockForUpdate()->get();
                foreach ($outputs as $output) {
                    app(InspectionService::class)->createForOutput($output, $wo, $by);
                }
            }
            if ($ncr->rework_machine_id) {
                $machine = Machine::query()->lockForUpdate()->findOrFail($ncr->rework_machine_id);
                $mold = Mold::query()->lockForUpdate()->findOrFail($ncr->rework_mold_id);
                app(ProductionResourceService::class)->release($wo, $machine, $mold, $ncr->id);

            } else {
                app(ProductionResourceService::class)->releaseReservation($wo, $ncr->id);
            }
            $this->notifyProduction($wo, $ncr, 'awaiting_qc');

            return $ncr;
        });
    }

    public function assertRepair(WorkOrder $wo, NonConformanceReport $ncr): void
    {
        if (in_array($wo->status->value, ['closed', 'cancelled'], true) || $ncr->status->isTerminal()
            || (int) $ncr->rework_work_order_id !== $wo->id || $ncr->disposition?->value !== 'rework'
            || ! $ncr->sorting_approved_at || (int) $ncr->confirmed_rework_quantity < 1
            || (int) $ncr->inspection?->entity_id !== $wo->id || $ncr->inspection?->entity_type?->value !== 'work_order') {
            throw new BusinessRuleException('This NCR has no active inline repair for this work order.');
        }
    }

    public function notifyProduction(WorkOrder $wo, ?NonConformanceReport $ncr, string $state): void
    {
        $audience = User::query()->where('is_active', true)->where(fn ($q) => $q->where('id', $wo->created_by)
            ->orWhereHas('role', fn ($role) => $role->where('slug', 'production_manager')))->get()
            ->filter(fn (User $user) => $user->hasPermission('production.work_orders.view'));
        $crewUsers = $wo->crews()->with('members.employee.user')->whereNull('ended_at')->get()->flatMap(fn ($crew) => $crew->members->pluck('employee.user'))->filter();
        $crewUsers = $crewUsers->filter(fn (User $user) => $user->is_active);
        $audience = $audience->unique('id');
        $message = match ($state) {
            'qc_failed' => 'Quality inspection failed. Stop affected production and review the linked defect.',
            'required' => "{$ncr->confirmed_rework_quantity} pieces require repair. {$ncr->defect_description}",
            'awaiting_qc' => 'Repair production is finished. Fresh quality approval is required.',
            default => 'Production, QC and defect handling are resolved. Work order closed automatically.',
        };
        $title = match ($state) {
            'qc_failed' => "WO {$wo->wo_number} — Quality inspection failed",
            'required' => "WO {$wo->wo_number} — Rework required",
            'awaiting_qc' => "WO {$wo->wo_number} — Rework awaiting quality inspection",
            default => "WO {$wo->wo_number} — Rework completed",
        };
        app(NotificationService::class)->sendForSubject($wo, $audience, 'production.rework.'.$state, [
            'title' => $title, 'message' => $message,
            'link_to' => "/production/work-orders/{$wo->hash_id}", 'entity_type' => 'work_order', 'entity_id' => $wo->hash_id,
        ], 'wo-rework-'.$state.'-'.($ncr?->id ?? $wo->id), emailEnabled: false);
        app(NotificationService::class)->sendForSubject($wo, $crewUsers, 'production.rework.'.$state, [
            'title' => $title, 'message' => $message,
            'link_to' => '/self-service/work-orders/'.$wo->hash_id, 'entity_type' => 'work_order', 'entity_id' => $wo->hash_id,
        ], 'wo-crew-'.$state.'-'.($ncr?->id ?? $wo->id), emailEnabled: false);
    }
}
