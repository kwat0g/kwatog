<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ActivityEvent;
use App\Common\Services\ActivityFeedService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ProductionResourceService
{
    public const START_GRACE_MINUTES = 15;

    public function durationMinutes(WorkOrder $wo, Mold $mold, ?int $quantity = null): int
    {
        if ((int) $mold->output_rate_per_hour < 1) {
            throw new BusinessRuleException('Mold output rate is missing. PPC must configure production capacity.');
        }

        return max(30, (int) ceil(($quantity ?? max(0, (int) $wo->quantity_target - (int) $wo->quantity_good)) / (int) $mold->output_rate_per_hour * 60 + (int) $mold->setup_time_minutes));
    }

    public function reservationMinutes(WorkOrder $wo, Mold $mold, ?int $quantity = null): int
    {
        return max(120, $this->durationMinutes($wo, $mold, $quantity) + 15);
    }

    /** Read-only execution window; attendance and resource ownership remain separate gates. */
    public function executionWindow(WorkOrder $wo, Machine $machine, Mold $mold, bool $allowLate = false): array
    {
        $slot = app(WorkOrderAssignmentService::class)->schedule($wo);
        if (! $slot || ! $slot->is_confirmed || $slot->status?->value !== 'confirmed') {
            throw new BusinessRuleException('No approved schedule. Reschedule before starting.');
        }
        if ((int) $slot->machine_id !== (int) $machine->id || (int) $slot->mold_id !== (int) $mold->id) {
            throw new BusinessRuleException('Equipment differs from the approved slot. Review assignment before starting.');
        }
        $start = now();
        $required = $this->durationMinutes($wo, $mold);
        $extending = $wo->status?->value === 'in_progress';
        if (! $extending && $slot->scheduled_start->copy()->addMinutes($required)->gt($slot->scheduled_end)) {
            throw new BusinessRuleException('Approved slot is too short. Reschedule before starting.');
        }
        $early = ! $extending && $start->lt($slot->scheduled_start);
        $late = ! $extending && ! $early && ($start->gt($slot->scheduled_start->copy()->addMinutes(self::START_GRACE_MINUTES))
            || $start->copy()->addMinutes($required)->gt($slot->scheduled_end));
        $end = $extending ? $start->copy()->addMinutes($required + 15)->max($slot->scheduled_end->copy()->addMinutes(15))
            : ($early ? $start->copy()->addMinutes($this->reservationMinutes($wo, $mold))->min($slot->scheduled_end)
                : ($start->copy()->addMinutes($required)->lte($slot->scheduled_end)
                ? $slot->scheduled_end->copy() : $start->copy()->addMinutes($required + 15)));
        $this->assertCanAcquire($wo, $machine, $mold);
        $this->assertReservation($wo, $machine, $mold, $start, $end, $slot->id, forExecution: true, allowLate: $allowLate);

        $allowHoliday = ! $extending && ! $early && ! $late || (app(WorkOrderAssignmentService::class)->holidayAuthorized($slot)
            && $start->gte($slot->scheduled_start->copy()->startOfDay())
            && $end->lte($slot->scheduled_end->copy()->endOfDay()));

        return ['allow_holiday' => $allowHoliday, 'mode' => $extending ? 'extend' : ($early ? 'early' : ($late ? 'late' : 'scheduled')),
            'slot' => $slot, 'window' => new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end])];
    }

    public function extendRun(WorkOrder $wo, User $actor, array $data): void
    {
        abort_unless($actor->is_active && $actor->hasPermission('production.work_orders.lifecycle'), 403);
        if ($wo->status?->value !== 'in_progress') {
            throw new BusinessRuleException('Extend only running production. Use Start or Resume for other work orders.');
        }
        $crews = app(WorkOrderAssignmentService::class);
        $slot = $crews->schedule($wo);
        if (! $slot || $slot->id !== $data['expected_schedule_id']
            || ! $slot->updated_at->equalTo(Carbon::parse($data['expected_schedule_updated_at']))
            || $crews->currentCrew($wo)?->id !== $data['expected_crew_id']) {
            throw new BusinessRuleException('Schedule or crew changed. Reopen Extend run to review it.');
        }
        $end = Carbon::parse($data['scheduled_end'])->timezone(config('app.timezone'));
        if ($end->lte($slot->scheduled_end) || $end->lte(now()) || $end->gt(now()->addDay())) {
            throw new BusinessRuleException('Extension must finish after the current reservation and within 24 hours.');
        }
        $machine = Machine::lockForUpdate()->findOrFail($wo->machine_id);
        $mold = Mold::lockForUpdate()->findOrFail($wo->mold_id);
        $allowLate = $this->assertDeadlineReview($wo, $end, $actor, $data);
        $this->assertCanAcquire($wo, $machine, $mold);
        $this->assertReservation($wo, $machine, $mold, now(), $end, $slot->id, forExecution: true, allowLate: $allowLate);
        $window = new ProductionSchedule(['scheduled_start' => now(), 'scheduled_end' => $end]);
        $holiday = $crews->holidayAuthorized($slot) && now()->gte($slot->scheduled_start->copy()->startOfDay())
            && $end->lte($slot->scheduled_end->copy()->endOfDay());
        $crews->assertScheduledCrew($wo, $window, allowHoliday: $holiday);
        $this->releaseReservation($wo);
        $actual = ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => $window->scheduled_start, 'scheduled_end' => $end, 'priority_order' => $slot->priority_order,
            'status' => 'confirmed', 'is_confirmed' => true, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'reserves_capacity' => true]);
        $crews->assertReadyForStart($wo, starting: true, actor: $actor,
            confirmedIds: $data['presence_confirmed_employee_ids'] ?? [], allowHoliday: $holiday);
        app(ActivityFeedService::class)->record('production.schedule', 'run_extended', $wo,
            'Running production extended for '.$wo->wo_number.'.', ['schedule_id' => $actual->hash_id,
                'approved_start' => $slot->scheduled_start->toIso8601String(), 'approved_end' => $slot->scheduled_end->toIso8601String(),
                'scheduled_start' => $actual->scheduled_start->toIso8601String(), 'scheduled_end' => $end->toIso8601String(),
                'reason' => trim($data['reason']), 'crew_id' => $crews->currentCrew($wo)?->hash_id,
                'holiday_authorized' => $holiday, 'production_deadline' => $wo->planned_end?->toIso8601String(),
                'late_production_authorized' => $allowLate, 'late_production_reason' => $allowLate ? trim($data['late_production_reason']) : null],
            actorUserId: $actor->id);
    }

    private function prepareExecution(WorkOrder $wo, Machine $machine, Mold $mold, User $actor, array $execution): void
    {
        abort_unless($actor->is_active && $actor->hasPermission('production.work_orders.lifecycle'), 403);
        $preview = $this->executionWindow($wo, $machine, $mold);
        $slot = $preview['slot'];
        if (array_key_exists('expected_schedule_id', $execution)
            && ($slot->id !== $execution['expected_schedule_id']
                || ! $slot->updated_at->equalTo(Carbon::parse($execution['expected_schedule_updated_at'] ?? '1970-01-01')))) {
            throw new BusinessRuleException('Schedule changed. Reopen Start to review it.');
        }
        if ($preview['mode'] !== 'scheduled' && (! isset($execution['expected_schedule_id'], $execution['expected_schedule_updated_at']))) {
            throw new BusinessRuleException('Review the current schedule in Start before starting early or late.');
        }
        if ($preview['mode'] === 'late' && (($execution['late_start_authorized'] ?? false) !== true
            || ! $actor->is_active || ! $actor->hasPermission('production.work_orders.lifecycle'))) {
            throw new BusinessRuleException('Start late only after acknowledging the production delay, or reschedule.');
        }
        $crews = app(WorkOrderAssignmentService::class);
        if ($preview['mode'] === 'scheduled') {
            $crews->assertReadyForStart($wo, starting: true, actor: $actor,
                confirmedIds: $execution['presence_confirmed_employee_ids'] ?? []);

            return;
        }
        // Preserve approved timing; the actual run gets its own conflict-protected reservation.
        $this->releaseReservation($wo);
        $actual = ProductionSchedule::create(['work_order_id' => $wo->id,
            'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => $preview['window']->scheduled_start, 'scheduled_end' => $preview['window']->scheduled_end,
            'priority_order' => $slot->priority_order, 'status' => 'confirmed', 'is_confirmed' => true,
            'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'reserves_capacity' => true]);
        app(ActivityFeedService::class)->record(type: 'production.schedule', action: 'execution_deviation', subject: $wo,
            summary: ($preview['mode'] === 'early' ? 'Early' : 'Late').' production started for '.$wo->wo_number.'.',
            detail: ['mode' => $preview['mode'], 'approved_schedule_id' => $slot->hash_id,
                'approved_start' => $slot->scheduled_start->toIso8601String(), 'approved_end' => $slot->scheduled_end->toIso8601String(),
                'schedule_id' => $actual->hash_id, 'scheduled_start' => $actual->scheduled_start->toIso8601String(),
                'scheduled_end' => $actual->scheduled_end->toIso8601String(), 'late_start_authorized' => $preview['mode'] === 'late',
                'holiday_authorized' => $preview['allow_holiday'], 'production_deadline' => $wo->planned_end?->toIso8601String(),
                'late_production_authorized' => $wo->planned_end && $actual->scheduled_end->gt($wo->planned_end),
                'crew_id' => $crews->currentCrew($wo)?->hash_id], actorUserId: $actor->id);
        // Presence attestations must reference the capacity reservation used by output access.
        $crews->assertReadyForStart($wo, starting: true, actor: $actor,
            confirmedIds: $execution['presence_confirmed_employee_ids'] ?? [], requireVerifiedPresence: $preview['mode'] === 'early', allowHoliday: $preview['allow_holiday']);
    }

    public function assertCompatibility(WorkOrder $wo, Machine $machine, Mold $mold, ?int $ncrId = null): void
    {
        if ((int) $mold->product_id !== (int) $wo->product_id) {
            throw new BusinessRuleException('The selected mold is not configured for this work-order product.');
        }
        if ($machine->trashed() || $mold->trashed() || ! $mold->compatibleMachines()->whereKey($machine->id)->exists()) {
            throw new BusinessRuleException('Machine and mold are not compatible or are archived. PPC must review the assignment.');
        }
        if (($machine->status?->value === 'running' && ! $machine->current_work_order_id) || ($mold->status?->value === 'in_use' && ! $mold->current_work_order_id)) {
            throw new BusinessRuleException('Running or installed equipment has no verified WO owner. Production management must review physical ownership before scheduling.');
        }
        if (! in_array($machine->status?->value, ['idle', 'running'], true) || ! in_array($mold->status?->value, ['available', 'in_use'], true)) {
            throw new BusinessRuleException('Assigned equipment is unavailable. Maintenance must restore it before production.');
        }
        foreach ([$machine, $mold] as $resource) {
            if (! $resource->current_work_order_id
                || ((int) $resource->current_work_order_id === (int) $wo->id && $resource->current_rework_ncr_id === $ncrId)) {
                continue;
            }
            $ownerSlot = ProductionSchedule::query()->where('work_order_id', $resource->current_work_order_id)
                ->where('rework_ncr_id', $resource->current_rework_ncr_id)->where('reserves_capacity', true)
                ->where($resource instanceof Machine ? 'machine_id' : 'mold_id', $resource->id)
                ->where('is_confirmed', true)->where('scheduled_start', '<=', now())
                ->whereIn('status', ['confirmed', 'executed'])->latest('id')->first();
            if (! $ownerSlot || $ownerSlot->scheduled_end->lte(now())) {
                throw new BusinessRuleException('Equipment is occupied by overdue or unscheduled work. Production management must review actual release before PPC schedules another WO.');
            }
        }
    }

    public function assertMoldLife(Mold $mold, int $quantity, ?int $woId = null, ?int $ncrId = null): void
    {
        $booked = 0;
        $contexts = [];
        foreach (ProductionSchedule::query()->where('mold_id', $mold->id)->where('reserves_capacity', true)->with('workOrder')->get()->unique(fn ($s) => $s->work_order_id.':'.$s->rework_ncr_id) as $schedule) {
            $contexts[$schedule->work_order_id.':'.$schedule->rework_ncr_id] = true;
            if ((int) $schedule->work_order_id === $woId && $schedule->rework_ncr_id === $ncrId) {
                continue;
            }
            if ($schedule->rework_ncr_id) {
                $ncr = NonConformanceReport::find($schedule->rework_ncr_id);
                $done = $schedule->workOrder->outputs()->where('rework_ncr_id', $schedule->rework_ncr_id)->sum(DB::raw('good_count + reject_count'));
                $booked += max(0, (int) $ncr?->confirmed_rework_quantity - (int) $done);
            } elseif (in_array($schedule->workOrder?->status?->value, ['planned', 'confirmed', 'in_progress', 'paused'], true)) {
                $booked += max(0, (int) $schedule->workOrder->quantity_target - (int) $schedule->workOrder->quantity_good);
            }
        }
        if ($mold->current_work_order_id && ! isset($contexts[$mold->current_work_order_id.':'.$mold->current_rework_ncr_id])
            && ! ((int) $mold->current_work_order_id === $woId && $mold->current_rework_ncr_id === $ncrId)) {
            $owner = WorkOrder::withTrashed()->findOrFail($mold->current_work_order_id);
            $booked += $this->remainingQuantity($owner, $mold->current_rework_ncr_id);
        }
        // Conservatively count pieces as estimated cycles until historical counter units are verified.
        if ((int) $mold->current_shot_count + $booked + $quantity > (int) $mold->max_shots_before_maintenance
            || ((int) $mold->lifetime_max_shots > 0 && (int) $mold->lifetime_total_shots + $booked + $quantity > (int) $mold->lifetime_max_shots)) {
            throw new BusinessRuleException('Mold remaining maintenance or lifetime capacity cannot cover this WO and existing reservations. Maintenance/PPC must review.');
        }
    }

    public function assertReservation(WorkOrder $wo, Machine $machine, Mold $mold, Carbon $start, Carbon $end, ?int $scheduleId = null, ?int $ncrId = null, bool $forExecution = false, bool $allowLate = false): void
    {
        $this->assertCompatibility($wo, $machine, $mold, $ncrId);
        $this->assertActiveEquipmentAssignmentsHaveSlots($wo, $machine, $mold, $forExecution);
        if (! $start->lt($end)) {
            throw new BusinessRuleException('Production slot must have positive duration.');
        }
        $maintenanceWindows = MaintenanceWindow::confirmed()->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('maintainable_type', 'machine')->where('maintainable_id', $machine->id))
            ->orWhere(fn ($query) => $query->where('maintainable_type', 'mold')->where('maintainable_id', $mold->id)))->get();
        foreach ($maintenanceWindows as $window) {
            if ($window->planned_start_at->lt($end) && $window->planned_end_at->gt($start)) {
                throw new BusinessRuleException('Equipment is reserved for preventive maintenance during this interval.');
            }
        }
        $this->assertDeadline($wo, $end, $scheduleId, $allowLate);
        if ($start->copy()->addMinutes($this->durationMinutes($wo, $mold, $this->remainingQuantity($wo, $ncrId)))->gt($end)) {
            throw new BusinessRuleException('Production slot is too short at the current mold rate. PPC must review remaining timing.');
        }
        $hours = (float) $machine->available_hours_per_day;
        if ($hours <= 0) {
            throw new BusinessRuleException('Machine daily capacity is missing. PPC must configure available hours.');
        }
        $rows = ProductionSchedule::query()->where('reserves_capacity', true)->where('id', '!=', $scheduleId ?? 0)
            ->where(fn ($q) => $q->where('work_order_id', '!=', $wo->id)->orWhere('rework_ncr_id', '!=', $ncrId)->orWhere(fn ($q) => $ncrId === null ? $q->whereNotNull('rework_ncr_id') : $q->whereNull('rework_ncr_id')))
            ->with('workOrder')->get();
        foreach ($rows as $row) {
            if ($row->scheduled_start->lt($end) && $row->scheduled_end->gt($start)
                && ((int) $row->machine_id === (int) $machine->id || (int) $row->mold_id === (int) $mold->id)) {
                throw new BusinessRuleException('Equipment overlaps work order '.($row->workOrder?->wo_number ?? $row->work_order_id).' from '.$row->scheduled_start->toIso8601String().' to '.$row->scheduled_end->toIso8601String().'. PPC must review.');
            }
        }
        for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
            $next = $day->copy()->addDay();
            $minutes = max(0, $start->max($day)->diffInMinutes($end->min($next), true));
            foreach ($rows->where('machine_id', $machine->id) as $row) {
                if ($row->scheduled_start->lt($next) && $row->scheduled_end->gt($day)) {
                    $minutes += $row->scheduled_start->max($day)->diffInMinutes($row->scheduled_end->min($next), true);
                }
            }
            foreach ($maintenanceWindows->where('maintainable_type', 'machine') as $window) {
                if ($window->planned_start_at->lt($next) && $window->planned_end_at->gt($day)) {
                    $minutes += $window->planned_start_at->max($day)->diffInMinutes($window->planned_end_at->min($next), true);
                }
            }
            if ($minutes > $hours * 60) {
                throw new BusinessRuleException('Machine daily capacity exceeded on '.$day->toDateString().'. PPC must review.');
            }
        }
        $this->assertMoldLife($mold, $this->remainingQuantity($wo, $ncrId), $wo->id, $ncrId);
    }

    private function assertActiveEquipmentAssignmentsHaveSlots(WorkOrder $wo, Machine $machine, Mold $mold, bool $forExecution): void
    {
        $activeStatuses = $forExecution ? ['in_progress'] : ['confirmed', 'in_progress'];
        $activeWorkOrders = WorkOrder::query()
            ->whereKeyNot($wo->id)
            ->where(fn ($query) => $query
                ->whereIn('status', $activeStatuses)
                ->orWhere(fn ($query) => $query->where('status', 'paused')->whereNull('production_released_at')))
            ->where(fn ($query) => $query->where('machine_id', $machine->id)->orWhere('mold_id', $mold->id))
            ->orderBy('id')
            ->get(['id', 'wo_number', 'machine_id', 'mold_id']);

        foreach ($activeWorkOrders as $activeWorkOrder) {
            $hasApprovedSlot = $activeWorkOrder->machine_id !== null && $activeWorkOrder->mold_id !== null
                && ProductionSchedule::query()
                    ->where('work_order_id', $activeWorkOrder->id)
                    ->whereNull('rework_ncr_id')
                    ->where('reserves_capacity', true)
                    ->where('is_confirmed', true)
                    ->whereIn('status', ['confirmed', 'executed'])
                    ->where('machine_id', $activeWorkOrder->machine_id)
                    ->where('mold_id', $activeWorkOrder->mold_id)
                    ->exists();

            if (! $hasApprovedSlot) {
                throw new BusinessRuleException(
                    "Equipment is assigned to active work order {$activeWorkOrder->wo_number} without an approved production slot. Production management must reconcile the existing assignment before confirming this work order."
                );
            }
        }
    }

    public function remainingQuantity(WorkOrder $wo, ?int $ncrId = null): int
    {
        if ($ncrId === null) {
            return max(0, (int) $wo->quantity_target - (int) $wo->quantity_good);
        }
        $ncr = NonConformanceReport::findOrFail($ncrId);
        $done = $wo->outputs()->where('rework_ncr_id', $ncrId)->sum(DB::raw('good_count + reject_count'));

        return max(0, (int) $ncr->confirmed_rework_quantity - (int) $done);
    }

    public function assertExecutionSlot(WorkOrder $wo, ?Machine $machine = null, ?Mold $mold = null, ?int $ncrId = null): ProductionSchedule
    {
        $slot = app(WorkOrderAssignmentService::class)->schedule($wo, $ncrId);
        if (! $slot || ! $slot->is_confirmed || $slot->status?->value !== 'confirmed') {
            throw new BusinessRuleException($ncrId === null
                ? 'No approved production slot remains. PPC or production management must review a new slot in Review production slot before start or resume.'
                : 'No repair slot approved. Auto schedule before starting.');
        }
        $now = now();
        if ($now->lt($slot->scheduled_start)) {
            throw new BusinessRuleException(($ncrId === null ? 'Production' : 'Rework').' starts at '
                .$slot->scheduled_start->copy()->timezone(config('app.timezone'))->format('M j, Y · g:i A').' ('.config('app.timezone').').');
        }
        if ($now->gte($slot->scheduled_end)) {
            throw new BusinessRuleException($ncrId === null
                ? 'Production slot expired. Review production slot before starting.'
                : 'Repair slot expired. Auto schedule a new slot.');
        }
        if ((int) $slot->machine_id !== (int) $machine?->id || (int) $slot->mold_id !== (int) $mold?->id) {
            throw new BusinessRuleException('Equipment differs from the approved slot. Review assignment before starting.');
        }
        $this->assertDeadline($wo, $slot->scheduled_end, $slot->id);
        if ($machine && $mold) {
            $required = $this->durationMinutes($wo, $mold, $this->remainingQuantity($wo, $ncrId));
            $available = (int) floor(now()->diffInMinutes($slot->scheduled_end, true));
            if (now()->copy()->addMinutes($required)->gt($slot->scheduled_end)) {
                $recovery = $ncrId === null
                    ? 'Click Reschedule to choose a new slot.'
                    : 'Auto schedule a new repair slot.';
                throw new BusinessRuleException('Not enough scheduled time: '
                    .$required.($required === 1 ? ' minute needed, ' : ' minutes needed, ')
                    .$available.($available === 1 ? ' minute left. ' : ' minutes left. ').$recovery);
            }
            $this->assertReservation($wo, $machine, $mold, $slot->scheduled_start, $slot->scheduled_end, $slot->id, $ncrId, true);

        }

        return $slot;
    }

    public function assertDeadlineReview(WorkOrder $wo, Carbon $end, User $actor, array $review, ?int $ncrId = null): bool
    {
        if (! $wo->planned_end || $end->lte($wo->planned_end)) {
            return false;
        }
        if ($ncrId === null && ! in_array($wo->status?->value, ['paused', 'in_progress'], true)) {
            throw new BusinessRuleException('Finish by the production deadline. PPC must review the deadline and delivery impact first.');
        }
        if (($review['late_production_authorized'] ?? false) !== true || ! $actor->hasPermission('production.work_orders.lifecycle')
            || trim((string) ($review['late_production_reason'] ?? '')) === '') {
            throw new BusinessRuleException('Past production deadline. Production Management must approve late production and explain the delivery impact.');
        }

        return true;
    }

    private function assertDeadline(WorkOrder $wo, Carbon $end, ?int $scheduleId = null, bool $allowLate = false): void
    {
        if (! $wo->planned_end || $end->lte($wo->planned_end) || $allowLate) {
            return;
        }
        $slot = $scheduleId ? ProductionSchedule::find($scheduleId) : null;
        if ($slot && (int) $slot->work_order_id === (int) $wo->id && $slot->scheduled_end->equalTo($end)
            && ActivityEvent::where('type', 'production.schedule')->whereIn('action', ['reviewed', 'run_extended', 'execution_deviation'])->where('subject_id', $wo->id)
                ->where('detail->schedule_id', $slot->hash_id)->where('detail->late_production_authorized', true)
                ->where('actor_user_id', $slot->confirmed_by)
                ->where('detail->production_deadline', $wo->planned_end->toIso8601String())
                ->where('detail->scheduled_start', $slot->scheduled_start->toIso8601String())
                ->where('detail->scheduled_end', $end->toIso8601String())->exists()) {
            return;
        }
        throw new BusinessRuleException('New finish exceeds the production deadline. Explicit late-production approval is required for paused work or a run extension; otherwise ask PPC to review the deadline.');
    }

    public function reviewSlot(WorkOrder $wo, Carbon $start, Carbon $end, int $reviewedBy, string $reason, ?int $ncrId = null, ?int $machineId = null, ?int $moldId = null, bool $allowHoliday = false, array $deadlineReview = []): ProductionSchedule
    {
        if (trim($reason) === '' || ! $start->lt($end) || $end->lte(now())) {
            throw new BusinessRuleException('Review a positive, unexpired slot and enter a scheduling reason.');
        }
        if (($machineId === null) !== ($moldId === null) || ($ncrId === null && $machineId === null)) {
            throw new BusinessRuleException('Production and machine repair require both machine and mold.');
        }
        $allowLate = $this->assertDeadlineReview($wo, $end, User::findOrFail($reviewedBy), $deadlineReview, $ncrId);
        $machine = $machineId ? Machine::withTrashed()->lockForUpdate()->findOrFail($machineId) : null;
        $mold = $moldId ? Mold::withTrashed()->lockForUpdate()->findOrFail($moldId) : null;
        if ($machine && $mold) {
            $this->assertReservation($wo, $machine, $mold, $start, $end, null, $ncrId, allowLate: $allowLate);

        }
        app(WorkOrderAssignmentService::class)->assertScheduledCrew($wo, new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end]), $ncrId, $allowHoliday);
        $this->releaseReservation($wo, $ncrId);
        $slot = ProductionSchedule::create(['work_order_id' => $wo->id, 'rework_ncr_id' => $ncrId,
            'machine_id' => $machineId, 'mold_id' => $moldId, 'scheduled_start' => $start, 'scheduled_end' => $end,
            'priority_order' => $wo->priority ?? 0, 'status' => 'confirmed', 'is_confirmed' => true,
            'confirmed_by' => $reviewedBy, 'confirmed_at' => now(), 'reserves_capacity' => true]);

        app(ActivityFeedService::class)->record(type: 'production.schedule', action: 'reviewed', subject: $wo,
            summary: 'Production slot reviewed for '.$wo->wo_number.'.', detail: ['schedule_id' => $slot->hash_id,
                'holiday_authorized' => $allowHoliday, 'rework_ncr_id' => $ncrId, 'scheduled_start' => $start->toIso8601String(), 'scheduled_end' => $end->toIso8601String(), 'reason' => trim($reason),
                'production_deadline' => $wo->planned_end?->toIso8601String(), 'late_production_authorized' => $allowLate,
                'late_production_reason' => $allowLate ? trim($deadlineReview['late_production_reason']) : null],
            actorUserId: $reviewedBy);

        return $slot;
    }

    public function reviewedSlotReplay(WorkOrder $wo, array $data, int $reviewedBy, ?int $ncrId = null, ?int $machineId = null, ?int $moldId = null): bool
    {
        $slot = app(WorkOrderAssignmentService::class)->schedule($wo, $ncrId);
        if (! $slot && ($data['expected_schedule_id'] ?? null) === null && ($data['expected_schedule_updated_at'] ?? null) === null) {
            return false;
        }
        if ($slot?->id === ($data['expected_schedule_id'] ?? null)
            && $slot?->updated_at->equalTo(Carbon::parse($data['expected_schedule_updated_at']))) {
            return false;
        }
        if ($slot && $slot->confirmed_by === $reviewedBy && $slot->is_confirmed
            && ActivityEvent::where('type', 'production.schedule')->where('action', 'reviewed')
                ->where('subject_id', $wo->id)->where('actor_user_id', $reviewedBy)
                ->where('detail->schedule_id', $slot->hash_id)->where('detail->reason', trim($data['reason']))->exists()
            && $slot->scheduled_start->equalTo(Carbon::parse($data['scheduled_start']))
            && $slot->scheduled_end->equalTo(Carbon::parse($data['scheduled_end']))
            && (int) $slot->machine_id === (int) $machineId && (int) $slot->mold_id === (int) $moldId) {
            return true;
        }
        throw new BusinessRuleException('Production slot changed. Refresh and review the current assignment before approving replacement timing.');
    }

    public function releaseReservation(WorkOrder $wo, ?int $ncrId = null): void
    {
        ProductionSchedule::query()->where('work_order_id', $wo->id)->where('rework_ncr_id', $ncrId)->where('reserves_capacity', true)->update(['reserves_capacity' => false]);
    }

    public function assertCanAcquire(WorkOrder $wo, Machine $machine, Mold $mold, ?int $ncrId = null): void
    {
        $this->assertCompatibility($wo, $machine, $mold, $ncrId);
        foreach ([$machine, $mold] as $resource) {
            if ($resource->current_work_order_id === null && in_array($resource->status?->value, ['running', 'in_use'], true)) {
                throw new BusinessRuleException('Equipment runtime owner is missing. Production management must reconcile actual ownership before starting another context.');
            }
            if ($resource->current_work_order_id !== null && ((int) $resource->current_work_order_id !== (int) $wo->id || $resource->current_rework_ncr_id !== $ncrId)) {
                throw new BusinessRuleException('Equipment is occupied by WO '.(WorkOrder::withTrashed()->find($resource->current_work_order_id)?->wo_number ?? $resource->current_work_order_id).'. Production management must complete or explicitly release that work first.');
            }
        }
        $this->assertMoldLife($mold, $this->remainingQuantity($wo, $ncrId), $wo->id, $ncrId);
    }

    public function acquire(WorkOrder $wo, Machine $machine, Mold $mold, ?int $ncrId = null, ?User $actor = null, array $execution = []): void
    {
        $this->assertCanAcquire($wo, $machine, $mold, $ncrId);
        if ($actor && $ncrId === null) {
            $this->prepareExecution($wo, $machine, $mold, $actor, $execution);
        } else {
            $this->assertExecutionSlot($wo, $machine, $mold, $ncrId);
        }
        $machine->update(['status' => 'running', 'current_work_order_id' => $wo->id, 'current_rework_ncr_id' => $ncrId]);
        $mold->update(['status' => 'in_use', 'current_work_order_id' => $wo->id, 'current_rework_ncr_id' => $ncrId]);
    }

    public function release(WorkOrder $wo, ?Machine $machine, ?Mold $mold, ?int $ncrId = null): void
    {
        foreach (array_filter([$machine, $mold]) as $resource) {
            if ((int) $resource->current_work_order_id === (int) $wo->id && $resource->current_rework_ncr_id === $ncrId) {
                $data = ['current_work_order_id' => null, 'current_rework_ncr_id' => null];
                if ($resource instanceof Machine && $resource->status?->value === 'running') {
                    $data['status'] = 'idle';
                }
                if ($resource instanceof Mold && $resource->status?->value === 'in_use') {
                    $data['status'] = 'available';
                }
                $resource->update($data);
            }
        }
        $this->releaseReservation($wo, $ncrId);
    }
}
