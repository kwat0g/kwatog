<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ActivityEvent;
use App\Common\Services\ActivityFeedService;
use App\Common\Services\NotificationService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Services\EmployeeAvailabilityService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkOrderAssignmentService
{
    public function __construct(private readonly EmployeeAvailabilityService $availability) {}

    public function internal(User $actor): void
    {
        abort_unless($actor->is_active && $actor->employee_id
            && ! in_array($actor->role?->slug, ['customer', 'supplier', 'customer_portal', 'supplier_portal'], true)
            && Employee::query()->whereKey($actor->employee_id)->where('status', 'active')->exists(), 403);
    }

    public function canAssign(User $actor, WorkOrder $wo): bool
    {
        return $actor->is_active && $actor->hasPermission('production.wo.assign')
            && ($actor->role?->slug !== 'ppc_head' || in_array($wo->status->value, ['planned', 'confirmed'], true));
    }

    public function currentCrew(WorkOrder $wo, ?int $ncrId = null): ?WorkOrderCrew
    {
        return $wo->crews()->where('rework_ncr_id', $ncrId)->whereNull('ended_at')->with('members.employee.user.role', 'members.employee.department')->first();
    }

    public function productionEmployees(): Builder
    {
        return Employee::query()->where('status', 'active')->whereHas('department',
            fn ($q) => $q->where('code', 'PROD')->where('is_active', true))
            ->whereHas('user', fn ($q) => $q->where('is_active', true)
                ->whereHas('role', fn ($role) => $role->where('slug', 'employee')))
            ->with('user.role', 'department');
    }

    public function schedule(WorkOrder $wo, ?int $ncrId = null): ?ProductionSchedule
    {
        return $wo->schedules()->where('rework_ncr_id', $ncrId)->where('reserves_capacity', true)
            ->whereIn('status', ['pending', 'confirmed', 'executed'])->latest('id')->first();
    }

    public function holidayAuthorized(?ProductionSchedule $slot): bool
    {
        return $slot?->exists && ActivityEvent::where('type', 'production.schedule')
            ->whereIn('action', ['reviewed', 'run_extended', 'execution_deviation'])->where('subject_id', $slot->work_order_id)
            ->where('detail->schedule_id', $slot->hash_id)->where('detail->holiday_authorized', true)->exists();
    }

    private function assertWorkerAvailable(Employee $employee, WorkOrder $wo, ?ProductionSchedule $slot, ?int $ncrId, bool $runtime = false): void
    {
        $others = $employee->relationLoaded('productionCrews')
            ? $employee->productionCrews->filter(fn ($crew) => (int) $crew->work_order_id !== (int) $wo->id
                || $crew->rework_ncr_id !== $ncrId)
            : WorkOrderCrew::query()->whereNull('ended_at')
                ->whereHas('members', fn ($q) => $q->where('employee_id', $employee->id))
                ->where(fn ($q) => $q->where('work_order_id', '!=', $wo->id)
                    ->orWhere(fn ($q) => $ncrId === null ? $q->whereNotNull('rework_ncr_id')
                        : $q->whereNull('rework_ncr_id')->orWhere('rework_ncr_id', '!=', $ncrId)))
                ->with('workOrder', 'ncr')->get();
        foreach ($others as $other) {
            $otherWo = $other->workOrder;
            if (! $otherWo) {
                continue;
            }
            $executing = $other->rework_ncr_id
                ? ($other->ncr?->rework_started_at && ! $other->ncr?->rework_finished_at)
                : (in_array($otherWo->status->value, ['in_progress', 'paused'], true) && ! $otherWo->production_released_at);
            if ($runtime && $executing) {
                throw new BusinessRuleException($employee->full_name.' still has runtime responsibility for '.$otherWo->wo_number.'. Production management must release or finish that work.');
            }
            $otherSlot = $employee->relationLoaded('productionCrews')
                ? $this->loadedReservation($otherWo, $other->rework_ncr_id) : $this->schedule($otherWo, $other->rework_ncr_id);
            if ($executing && (! $otherSlot || $otherSlot->scheduled_end->lte(now()))) {
                throw new BusinessRuleException($employee->full_name.' is executing overdue or unscheduled '.$otherWo->wo_number.'. Production management must review actual release.');
            }
            if (! $otherSlot && (($slot && in_array($otherWo->status->value, ['planned', 'confirmed'], true)) || $executing)) {
                throw new BusinessRuleException($employee->full_name.' has no authoritative slot for '.$otherWo->wo_number.'. PPC must repair its schedule.');
            }
            if ($slot && $otherSlot && $slot->scheduled_start->lt($otherSlot->scheduled_end) && $otherSlot->scheduled_start->lt($slot->scheduled_end)) {
                throw new BusinessRuleException($employee->full_name.' overlaps '.$otherWo->wo_number.' ('.$otherSlot->scheduled_start->toIso8601String().' to '.$otherSlot->scheduled_end->toIso8601String().'). PPC must review assignments.');
            }
        }
    }

    public function shiftInterval(Employee $employee, Carbon $at, bool $allowHoliday = false): ?array
    {
        return $this->availability->shiftInterval($employee, $at, $allowHoliday);
    }

    public function isHoliday(Carbon $date, ?Collection $holidays = null): bool
    {
        return $this->availability->isHoliday($date, $holidays);
    }

    private function shiftCovers(Employee $employee, Carbon $start, Carbon $end, bool $allowHoliday = false): bool
    {
        if (! $allowHoliday) {
            for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
                if ($this->isHoliday($day, $employee->getRelationValue('productionHolidays'))) {
                    return false;
                }
            }
        }
        $interval = $this->shiftInterval($employee, $start, $allowHoliday);

        return $interval !== null && $interval['end']->gte($end);
    }

    private function attendanceFor(Employee $employee, string $workDate): ?Attendance
    {
        return $this->availability->attendanceFor($employee, $workDate);
    }

    private function clockedIn(?Attendance $attendance, Carbon $at): bool
    {
        return $this->availability->clockedIn($attendance, $at);
    }

    public function presence(Employee $employee, Carbon $at): array
    {
        return $this->availability->presence($employee, $at);
    }

    private function leaveBlocker(Employee $employee, string $workDate, Carbon $end): ?string
    {
        return $this->availability->leaveBlocker($employee, $workDate, $end);
    }

    public function scheduleAttendance(Employee $employee, ProductionSchedule $slot, ?string $blocker = null): array
    {
        $interval = $this->shiftInterval($employee, $slot->scheduled_start, true);
        $workDate = $interval['work_date'] ?? $slot->scheduled_start->toDateString();
        if ($blocker !== null || ! $interval) {
            return ['state' => 'blocked', 'reason' => $blocker ?? 'Shift does not cover this time.', 'work_date' => $workDate];
        }
        if ($interval['start']->gt(now())) {
            return ['state' => 'upcoming', 'reason' => 'Shift has not started. Attendance checked at Start.', 'work_date' => $workDate];
        }

        return $this->presence($employee, now());
    }

    public function assertPresence(WorkOrder $wo, Collection $employees, ?User $actor, array $confirmedIds = [], ?int $ncrId = null): void
    {
        if (array_diff($confirmedIds, $employees->pluck('id')->all())) {
            throw new BusinessRuleException('Presence confirmation contains a worker outside this crew.');
        }
        $unknown = [];
        foreach ($employees as $employee) {
            $presence = $this->presence($employee, now());
            if ($presence['state'] === 'blocked') {
                throw new BusinessRuleException($employee->full_name.': '.$presence['reason'].' Change workers or ask HR to correct attendance.');
            }
            if ($presence['state'] === 'unknown') {
                $unknown[] = $employee->id;
            }
        }
        if ($unknown !== [] || $confirmedIds !== []) {
            abort_unless($actor?->is_active && $actor->hasPermission('production.work_orders.lifecycle'), 403);
        }
        if (array_diff($unknown, $confirmedIds)) {
            throw new BusinessRuleException('Production management must confirm physical presence for workers without attendance before this action.');
        }
        if ($unknown !== []) {
            app(ActivityFeedService::class)->record('production', 'presence_confirmed', $wo,
                'Physical presence confirmed for production.', ['employee_ids' => $unknown,
                    'crew_id' => $this->currentCrew($wo, $ncrId)?->id, 'schedule_id' => $this->schedule($wo, $ncrId)?->id,
                    'checked_at' => now()->toIso8601String()], actorUserId: $actor->id);
        }
    }

    public function eligibleWorkers(WorkOrder $wo, Carbon $start, Carbon $end, ?int $ncrId = null): Collection
    {
        $slot = new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end]);

        return $this->loadWorkerContext($this->productionEmployees()->orderBy('employee_no')->orderBy('id')->get(), $slot)
            ->filter(fn ($employee) => $this->workerBlocker($employee, $wo, $slot, $ncrId) === null)->values();
    }

    private function workerBlocker(Employee $employee, WorkOrder $wo, ?ProductionSchedule $slot, ?int $ncrId, bool $checkHr = true, bool $runtime = false, bool $allowHoliday = false): ?string
    {
        try {
            $this->assertWorkerAvailable($employee, $wo, $slot, $ncrId, $runtime);
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }
        if (! $checkHr || ! $slot) {
            return null;
        }

        $start = $slot->scheduled_start->copy()->timezone(config('app.timezone'));
        $shiftDate = $this->shiftInterval($employee, $start, $allowHoliday)['work_date'] ?? $start->toDateString();
        $end = $slot->scheduled_end->copy()->timezone(config('app.timezone'));
        $leave = $this->leaveBlocker($employee, $shiftDate, $end);
        if ($leave) {
            return $leave;
        }
        if (! $this->shiftCovers($employee, $slot->scheduled_start, $slot->scheduled_end, $allowHoliday)) {
            $assignment = app(ShiftAssignmentService::class)->assignmentFor($employee, $start);

            return ! $assignment || ! is_array($assignment->working_days) || $assignment->working_days === []
                ? 'HR working days are missing. Ask HR to set a work calendar.'
                : 'HR shift hours or working days do not cover this schedule. Overtime hours alone do not extend shift coverage.';
        }

        $interval = $this->shiftInterval($employee, $slot->scheduled_start, $allowHoliday);
        if ($interval) {
            if ($interval['start']->lte(now()) && $interval['end']->gt(now())) {
                $presence = $this->presence($employee, now());
                if ($presence['state'] === 'blocked') {
                    return $presence['reason'];
                }
            }
            $attendance = $this->attendanceFor($employee, $interval['work_date']);
            if ($attendance?->trashed()) {
                return 'Attendance is archived. Ask HR to restore and review it.';
            }
            if (in_array($attendance?->status?->value, ['absent', 'on_leave'], true)
                || ($attendance?->time_out && $attendance->time_out->lt($slot->scheduled_end) && ($attendance->time_out->gt(now()) || ! $this->clockedIn($attendance, now())))) {
                return 'HR recorded absence, leave or clock-out for this shift.';
            }
        }

        return null;
    }

    private function loadedReservation(WorkOrder $wo, ?int $ncrId): ?ProductionSchedule
    {
        return $wo->schedules->where('rework_ncr_id', $ncrId)->where('reserves_capacity', true)
            ->filter(fn ($schedule) => in_array($schedule->status->value, ['pending', 'confirmed', 'executed'], true))->sortByDesc('id')->first();
    }

    private function loadWorkerContext(Collection $employees, ?ProductionSchedule $slot = null): Collection
    {
        $start = now()->min($slot?->scheduled_start ?? now())->subDay()->toDateString();
        $end = now()->max($slot?->scheduled_end ?? now())->toDateString();
        $employees->load(['user.role', 'shiftAssignments.shift',
            'attendances' => fn ($query) => $query->whereBetween('date', [$start, $end]), 'attendances.punches',
            'approvedLeaves' => fn ($query) => $query->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start),
            'productionCrews.workOrder.schedules', 'productionCrews.ncr']);
        $holidays = Holiday::all();
        foreach ($employees as $employee) {
            $employee->setRelation('productionHolidays', $holidays);
        }

        return $employees;
    }

    public function workerOptions(
        WorkOrder $wo,
        ?ProductionSchedule $slot,
        ?int $ncrId = null,
        bool $checkHr = true,
        bool $runtime = false,
        ?Builder $employeesQuery = null,
        bool $allowHoliday = false,
    ): Collection {
        return $this->loadWorkerContext(($employeesQuery ?? $this->productionEmployees())->orderBy('employee_no')->orderBy('id')->get(), $slot)
            ->map(function ($employee) use ($wo, $slot, $ncrId, $checkHr, $runtime, $allowHoliday) {
                $reason = $this->workerBlocker($employee, $wo, $slot, $ncrId, $checkHr, $runtime, $allowHoliday);

                return $this->employeeOption($employee, $slot?->scheduled_start) + [
                    'can_assign' => $reason === null,
                    'blocked_reason' => $reason, 'presence' => $this->presence($employee, now()),
                    'schedule_attendance' => $slot ? $this->scheduleAttendance($employee, $slot, $reason) : null,
                ];
            })->values();
    }

    public function suggest(WorkOrder $wo, ProductionSchedule $schedule, ?int $ncrId = null, array $staffingLimits = []): array
    {
        $minimumWorkers = (int) ($staffingLimits['minimum_workers'] ?? $wo->minimum_workers ?? 1);
        $maximumWorkers = array_key_exists('maximum_workers', $staffingLimits)
            ? $staffingLimits['maximum_workers'] : $wo->maximum_workers;
        $maximumWorkers ??= 4;
        if ($minimumWorkers < 1 || $minimumWorkers > 4 || $maximumWorkers > 4 || $maximumWorkers < $minimumWorkers) {
            throw new BusinessRuleException('Minimum and maximum workers must be between 1 and 4; maximum must be at least minimum.');
        }
        $current = $this->currentCrew($wo, $ncrId);
        $eligible = $this->eligibleWorkers($wo, $schedule->scheduled_start, $schedule->scheduled_end, $ncrId);
        $workerOptions = $this->workerOptions($wo, $schedule, $ncrId);
        $immediate = $schedule->scheduled_start->lte(now()->copy()->addMinute());
        $eligible = $eligible->sortBy(function ($employee) use ($schedule, $immediate) {
            $minutes = 0;
            $crews = $employee->productionCrews;
            foreach ($crews as $crew) {
                $slot = $crew->workOrder ? $this->loadedReservation($crew->workOrder, $crew->rework_ncr_id) : null;
                if ($slot && $slot->scheduled_start->lt($schedule->scheduled_end->copy()->addDays(30)) && $slot->scheduled_end->gt($schedule->scheduled_start)) {
                    $minutes += $slot->scheduled_start->diffInMinutes($slot->scheduled_end, true);
                }
            }

            $unverified = $immediate && $this->presence($employee, now())['state'] !== 'present';

            return sprintf('%d-%012d-%s-%012d', $unverified ? 1 : 0, $minutes, $employee->employee_no, $employee->id);
        })->values();
        $selected = collect();
        $recorder = null;
        if ($current && $current->members->count() >= $minimumWorkers
            && ($maximumWorkers === null || $current->members->count() <= $maximumWorkers)
            && $current->members->every(fn ($m) => $eligible->contains('id', $m->employee_id))
            && (! $immediate || $current->members->every(fn ($m) => $this->presence($m->employee, now())['state'] === 'present')
                || ! $eligible->contains(fn ($employee) => $this->presence($employee, now())['state'] === 'present'))) {
            $selected = $current->members->map(fn ($m) => $m->employee);
            $recorder = $selected->firstWhere('id', $current->recorder_employee_id);
            if (! $recorder || ! $this->employeeOption($recorder)['has_active_account']) {
                $selected = collect();
                $recorder = null;
            }
        }
        if ($selected->isEmpty()) {
            $recorder = $eligible->first(fn ($e) => $this->employeeOption($e)['has_active_account']);
            if ($recorder) {
                $selected = collect([$recorder])->concat($eligible->reject(fn ($e) => $e->id === $recorder->id)->take(max(0, $minimumWorkers - 1)));
            }
        }
        $blockers = [];
        if ($eligible->isEmpty()) {
            $blockers[] = 'No Production workers have full HR coverage without approved leave or conflicting work for this slot.';
        } elseif (! $recorder) {
            $blockers[] = 'No eligible Production worker has an active internal recorder account.';
        }
        if ($selected->count() < $minimumWorkers) {
            $blockers[] = 'Minimum staffing requires '.$minimumWorkers.' workers; '.$eligible->count().' available with full HR shift coverage, no approved leave and no conflicting reservation.';
        }

        return ['schedule' => $schedule->exists ? $this->serializeSchedule($schedule) : null,
            'members' => $selected->map(fn ($e) => $workerOptions->firstWhere('id', $e->hash_id))->values()->all(),
            'eligible_workers' => $eligible->map(fn ($e) => $workerOptions->firstWhere('id', $e->hash_id))->values()->all(),
            'worker_options' => $workerOptions->all(),
            'recorder_employee_id' => $recorder?->hash_id, 'minimum_workers' => $minimumWorkers,
            'maximum_workers' => $maximumWorkers, 'expected_crew_id' => $current?->hash_id,
            'staffing_blockers' => $blockers, 'blockers' => $blockers];
    }

    public function serializeSchedule(ProductionSchedule $schedule): array
    {
        $schedule->loadMissing('machine', 'mold');

        return ['id' => $schedule->hash_id, 'status' => $schedule->status->value, 'scheduled_start' => $schedule->scheduled_start->toIso8601String(),
            'scheduled_end' => $schedule->scheduled_end->toIso8601String(), 'updated_at' => $schedule->updated_at->toISOString(),
            'machine_id' => $schedule->machine?->hash_id, 'mold_id' => $schedule->mold?->hash_id,
            'machine' => $schedule->machine ? ['machine_code' => $schedule->machine->machine_code, 'name' => $schedule->machine->name] : null,
            'mold' => $schedule->mold ? ['mold_code' => $schedule->mold->mold_code, 'name' => $schedule->mold->name] : null];
    }

    public function employeeOption(Employee $employee, ?Carbon $date = null): array
    {
        $employee->loadMissing('user.role');
        $date = ($date ?? now())->copy()->timezone(config('app.timezone'));
        $shifts = app(ShiftAssignmentService::class);
        $shift = $shifts->current($employee, $date);
        $previous = $shifts->current($employee, $date->copy()->subDay());
        // A night shift started on the preceding HR work date and may cross midnight.
        if ($previous?->is_night_shift && $previous->end_time < $previous->start_time
            && $date->format('H:i:s') < $previous->end_time) {
            $shift = $previous;
        }
        $interval = $this->shiftInterval($employee, $date, true);

        return ['id' => $employee->hash_id, 'employee_number' => $employee->employee_no, 'name' => $employee->full_name,
            'has_active_account' => (bool) ($employee->user?->is_active && $employee->user?->role?->slug === 'employee'),
            'shift' => $shift ? ['id' => $shift->hash_id, 'name' => $shift->name,
                'scheduled_start' => $interval ? $interval['start']->toIso8601String() : null,
                'scheduled_end' => $interval ? $interval['end']->toIso8601String() : null,
                'work_date' => $interval['work_date'] ?? null] : null];
    }

    public function serialize(WorkOrderCrew $crew, ?Carbon $date = null): array
    {
        $crew->loadMissing('members.employee.user', 'ncr');

        return ['id' => $crew->hash_id, 'members' => $crew->members->map(fn ($m) => ! $crew->ended_at && $m->employee
            ? $this->employeeOption($m->employee, $date) : $m->employee_snapshot)->all(),
            'recorder_employee_id' => app('hashids')->encode($crew->recorder_employee_id), 'reason' => $crew->reason,
            'assigned_at' => $crew->assigned_at->toIso8601String(), 'ended_at' => $crew->ended_at?->toIso8601String(),
            'rework_ncr_id' => $crew->ncr?->hash_id];
    }

    public function assign(WorkOrder $wo, array $data, User $actor): WorkOrderCrew
    {
        abort_unless($this->canAssign($actor, $wo), 403);

        return DB::transaction(function () use ($wo, $data, $actor) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            abort_unless($this->canAssign($actor, $wo), 403);
            $ncrId = $data['rework_ncr_id'] ?? null;
            if ($ncrId) {
                $ncr = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncrId);
                app(WorkOrderReworkService::class)->assertRepair($wo, $ncr);
                if ($ncr->rework_finished_at) {
                    throw new BusinessRuleException('Finished repair cannot receive a new crew.');
                }
            } elseif (! in_array($wo->status->value, ['planned', 'confirmed', 'in_progress', 'paused'], true)) {
                throw new BusinessRuleException('Only open production work orders can receive a crew.');
            }
            $current = $this->currentCrew($wo, $ncrId);
            if (($current?->id) !== ($data['expected_crew_id'] ?? null)) {
                throw new BusinessRuleException('The crew changed. Refresh and review the current assignment before saving.');
            }
            $minimum = (int) ($data['minimum_workers'] ?? $wo->minimum_workers);
            $maximum = isset($data['maximum_workers']) ? (int) $data['maximum_workers'] : 4;
            $ids = array_values(array_unique($data['employee_ids'] ?? []));
            if ($minimum < 1 || $minimum > 4 || $maximum > 4 || $maximum < $minimum) {
                throw new BusinessRuleException('Minimum and maximum workers must be between 1 and 4; maximum must be at least minimum.');
            }
            if (count($ids) < $minimum || ($maximum !== null && count($ids) > $maximum)) {
                throw new BusinessRuleException('Crew size must meet the minimum and maximum workers required by this WO.');
            }
            $employees = $this->productionEmployees()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $recorder = $employees->firstWhere('id', $data['recorder_employee_id'] ?? null);
            if ($employees->count() !== count($ids) || ! $recorder || ! $this->employeeOption($recorder)['has_active_account']) {
                throw new BusinessRuleException('Select active Production-department workers with employee accounts and one crew member as output recorder.');
            }
            if (trim($data['reason'] ?? '') === '') {
                throw new BusinessRuleException('An assignment or handover reason is required.');
            }
            $slot = $data['_reviewed_slot'] ?? $this->schedule($wo, $ncrId);
            $allowHoliday = ! empty($data['_allow_holiday']) || $this->holidayAuthorized($slot);
            if (! $slot && in_array($wo->status->value, ['planned', 'confirmed'], true)) {
                throw new BusinessRuleException('PPC must approve an authoritative schedule before assigning future production.');
            }
            foreach ($employees as $employee) {
                $this->assertWorkerAvailable($employee, $wo, $slot, $ncrId, in_array($wo->status->value, ['in_progress', 'paused'], true));
            }
            if ($slot) {
                $live = in_array($wo->status->value, ['in_progress', 'paused'], true) || ($ncrId && $ncr->rework_started_at);
                $coverage = $slot;
                if ($live) {
                    $coverage = new ProductionSchedule(['scheduled_start' => now()->max($slot->scheduled_start), 'scheduled_end' => $slot->scheduled_end]);
                    if ($coverage->scheduled_start->gte($coverage->scheduled_end)) {
                        throw new BusinessRuleException('Production slot expired. Review timing and workers together.');
                    }
                }
                foreach ($employees as $employee) {
                    $blocker = $this->workerBlocker($employee, $wo, $coverage, $ncrId, true, $live, $allowHoliday);
                    if ($blocker !== null) {
                        throw new BusinessRuleException('Worker availability changed: '.$blocker);
                    }
                }
                if ($live && $coverage->scheduled_start->lte(now())) {
                    $this->assertPresence($wo, $employees, $actor, $data['presence_confirmed_employee_ids'] ?? [], $ncrId);
                }
            } else {
                throw new BusinessRuleException('Review production slot before assigning a live crew.');
            }
            $current?->update(['ended_at' => now()]);
            if ($ncrId === null) {
                $wo->update(['minimum_workers' => $minimum, 'maximum_workers' => $maximum]);
            }
            $crew = $wo->crews()->create(['rework_ncr_id' => $ncrId, 'recorder_employee_id' => $recorder->id,
                'assigned_by' => $actor->id, 'reason' => trim($data['reason']), 'assigned_at' => now()]);
            foreach ($employees as $employee) {
                $crew->members()->create(['employee_id' => $employee->id, 'employee_snapshot' => $this->employeeOption($employee)]);
            }
            $recipients = $employees->map(fn ($e) => $e->user)->filter(fn ($u) => $u?->is_active)->values();
            app(NotificationService::class)->sendForSubject($wo, $recipients, 'production.crew.assigned', [
                'title' => 'Work order assigned: '.$wo->wo_number, 'message' => 'Review your crew and production task in My work orders.',
                'link_to' => '/self-service/work-orders/'.$wo->hash_id.($ncrId ? '?rework_ncr_id='.app('hashids')->encode($ncrId) : ''),
                'entity_type' => 'work_order', 'entity_id' => $wo->hash_id], 'wo-crew-'.$crew->id, emailEnabled: false);
            if ($current) {
                $removed = $current->members->map(fn ($m) => $m->employee?->user)->filter(fn ($u) => $u?->is_active && ! $recipients->contains('id', $u->id))->values();
                app(NotificationService::class)->sendForSubject($wo, $removed, 'production.crew.changed', ['title' => 'Crew changed: '.$wo->wo_number,
                    'message' => 'Your previous crew assignment ended. Review the work order history.',
                    'link_to' => '/self-service/work-orders/'.$wo->hash_id.'?crew_id='.$current->hash_id,
                    'entity_type' => 'work_order', 'entity_id' => $wo->hash_id], 'wo-crew-ended-'.$current->id, emailEnabled: false);
            }

            return $crew->load('members.employee.user');
        });
    }

    public function assignedQuery(User $actor): Builder
    {
        $this->internal($actor);

        return WorkOrderCrew::query()->whereNull('ended_at')->whereHas('members', fn ($q) => $q->where('employee_id', $actor->employee_id)->whereHas('employee', fn ($employee) => $employee->where('status', 'active')->whereHas('department', fn ($department) => $department->where('code', 'PROD')->where('is_active', true))))
            ->whereHas('workOrder', fn ($q) => $q->whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused', 'completed']))
            ->where(fn ($q) => $q->where(fn ($standard) => $standard->whereNull('rework_ncr_id')
                ->whereHas('workOrder', fn ($wo) => $wo->where('status', '!=', 'completed')))
                ->orWhereHas('ncr', fn ($ncr) => $ncr->whereNull('rework_finished_at')->whereNotIn('status', ['closed', 'cancelled'])));
    }

    /** Historical crew scope for late-report history; this does not grant live output access. */
    public function historicalCrewQuery(User $actor): Builder
    {
        $this->internal($actor);

        return WorkOrderCrew::query()->whereHas('members', fn ($query) => $query->where('employee_id', $actor->employee_id));
    }

    public function assertCanReadHistorical(User $actor, WorkOrder $wo, ?int $crewId = null): WorkOrderCrew
    {
        $query = $this->historicalCrewQuery($actor)->where('work_order_id', $wo->id);
        if ($crewId !== null) {
            $query->whereKey($crewId);
        }

        return $query->with('members')->orderByDesc('assigned_at')->firstOrFail();
    }

    public function assertCanSubmitHistorical(User $actor, WorkOrder $wo, int $crewId): WorkOrderCrew
    {
        $crew = $this->assertCanReadHistorical($actor, $wo, $crewId);
        if ($crew->rework_ncr_id !== null) {
            throw new BusinessRuleException('Historical rework output must use the NCR repair record and cannot be posted as work-order production.');
        }
        if ((int) $crew->recorder_employee_id !== (int) $actor->employee_id) {
            throw new BusinessRuleException('Only the recorder assigned to this historical crew can submit its production report.');
        }

        return $crew;
    }

    public function assertCanRead(User $actor, WorkOrder $wo, ?int $ncrId = null): WorkOrderCrew
    {
        return $this->assignedQuery($actor)->where('work_order_id', $wo->id)->where('rework_ncr_id', $ncrId)->firstOrFail();
    }

    public function assertReadyForConfirmation(WorkOrder $wo, ProductionSchedule $slot, bool $lock = true): WorkOrderCrew
    {
        $crew = $this->currentCrew($wo, $slot->rework_ncr_id);
        if (! $crew) {
            throw new BusinessRuleException('Assign a Production crew and one output recorder before confirming.');
        }
        if ($slot->scheduled_end->lte(now())) {
            throw new BusinessRuleException('Proposal passed. Find a new slot before confirming.');
        }
        $ids = $crew->members->pluck('employee_id');
        $query = $this->productionEmployees()->whereIn('id', $ids)->orderBy('id');
        $employees = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($employees->count() !== $ids->count() || $ids->count() < ($slot->rework_ncr_id ? 1 : max(1, $wo->minimum_workers))
            || $ids->count() > ($slot->rework_ncr_id ? 4 : min(4, $wo->maximum_workers ?? 4)) || ! $employees->contains('id', $crew->recorder_employee_id)) {
            throw new BusinessRuleException('Crew eligibility, worker count or output recorder changed. Change workers before confirming.');
        }
        foreach ($employees as $employee) {
            $blocker = $this->workerBlocker($employee, $wo, $slot, $slot->rework_ncr_id);
            if ($blocker !== null) {
                throw new BusinessRuleException('Assigned crew: '.$employee->full_name.' — '.$blocker);
            }
        }

        return $crew;
    }

    public function assertCalendarChangesSafe(array $employeeIds): void
    {
        $crews = WorkOrderCrew::whereNull('ended_at')->whereHas('members', fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereHas('workOrder', fn ($query) => $query->whereIn('status', ['confirmed', 'in_progress', 'paused']))
            ->with('workOrder', 'members.employee')->get();
        foreach ($crews as $crew) {
            $slot = $this->schedule($crew->workOrder, $crew->rework_ncr_id);
            if ((! $slot || $slot->scheduled_end->lte(now())) && in_array($crew->workOrder->status->value, ['in_progress', 'paused'], true)) {
                throw new BusinessRuleException('Active '.$crew->workOrder->wo_number.' needs a schedule review before changing HR coverage.');
            }
            if (! $slot || $slot->scheduled_end->lte(now())) {
                continue;
            }
            $start = now()->max($slot->scheduled_start);
            foreach ($crew->members as $member) {
                if (in_array((int) $member->employee_id, $employeeIds, true)
                    && ! $this->shiftCovers($member->employee, $start, $slot->scheduled_end, $this->holidayAuthorized($slot))) {
                    throw new BusinessRuleException('HR change would break '.$crew->workOrder->wo_number.'. Change its crew or reschedule before saving.');
                }
            }
        }
    }

    public function staffingIssues(WorkOrder $wo, ?int $ncrId = null): array
    {
        $crew = $this->currentCrew($wo, $ncrId);
        $slot = $this->schedule($wo, $ncrId);
        $issues = [];
        foreach ($crew?->members ?? [] as $member) {
            $employee = $member->employee;
            if (! $employee || $employee->status->value !== 'active' || ! $employee->user?->is_active || $employee->user?->role?->slug !== 'employee' || $employee->department?->code !== 'PROD' || ! $employee->department?->is_active) {
                $issues[] = ($employee?->full_name ?? 'Assigned worker').': account or employment is inactive.';

                continue;
            }
            $presence = $this->presence($employee, now());
            $reason = $slot && $slot->scheduled_start->gt(now())
                ? $this->workerBlocker($employee, $wo, $slot, $ncrId, allowHoliday: $this->holidayAuthorized($slot))
                : ($presence['state'] === 'blocked' ? $presence['reason'] : null);
            if ($reason) {
                $issues[] = $employee->full_name.': '.$reason;
            }
        }

        return $issues;
    }

    public function notifyStaffingChange(int $employeeId, string $changeKey, ?string $context = null): void
    {
        $crews = WorkOrderCrew::whereNull('ended_at')->whereHas('members', fn ($query) => $query->where('employee_id', $employeeId))
            ->whereHas('workOrder', fn ($query) => $query->whereIn('status', ['confirmed', 'in_progress', 'paused']))->with('workOrder')->get();
        if ($crews->isEmpty()) {
            return;
        }
        $owners = User::where('is_active', true)->whereHas('role', fn ($query) => $query->whereIn('slug', ['ppc_head', 'production_manager']))->get();
        foreach ($crews as $crew) {
            $issues = $this->staffingIssues($crew->workOrder, $crew->rework_ncr_id);
            if ($issues === [] && $context === null) {
                continue;
            }
            app(NotificationService::class)->sendForSubject($crew->workOrder, $owners, 'production.assignment.review', [
                'title' => 'Crew needs review: '.$crew->workOrder->wo_number,
                'message' => $issues === [] ? $context : implode(' ', $issues).' Review workers before starting new operations.',
                'link_to' => '/production/work-orders/'.$crew->workOrder->hash_id,
                'entity_type' => 'work_order', 'entity_id' => $crew->workOrder->hash_id,
            ], 'wo-staffing-'.$crew->id.'-'.$changeKey, emailEnabled: false);
        }
    }

    public function assertCrewPresent(WorkOrder $wo, WorkOrderCrew $crew): void
    {
        foreach ($crew->members as $member) {
            $this->assertOperatorPresent($wo, $member->employee);
        }
    }

    public function assertScheduledCrew(WorkOrder $wo, ProductionSchedule $slot, ?int $ncrId = null, bool $allowHoliday = false, bool $lock = true): void
    {
        $crew = $this->currentCrew($wo, $ncrId);
        if (! $crew) {
            return;
        }
        $ids = $crew->members->pluck('employee_id');
        $query = $this->productionEmployees()->whereIn('id', $ids)->orderBy('id');
        $employees = ($lock ? $query->lockForUpdate() : $query)->get();
        $valid = $employees->every(fn ($employee) => $this->workerBlocker($employee, $wo, $slot, $ncrId, true, false, $allowHoliday) === null);
        if ($employees->count() !== $ids->count() || $ids->count() < ($ncrId ? 1 : max(1, $wo->minimum_workers))
            || $ids->count() > ($ncrId ? 4 : min(4, $wo->maximum_workers ?? 4))
            || ! $employees->contains('id', $crew->recorder_employee_id) || ! $valid) {
            throw new BusinessRuleException('Crew does not cover this time. Check HR shifts, leave, or other assignments.');
        }
    }

    public function assertReadyForStart(WorkOrder $wo, ?int $ncrId = null, bool $starting = false, ?User $actor = null, array $confirmedIds = [], bool $lock = true, ?ProductionSchedule $executionWindow = null, bool $requireVerifiedPresence = false, bool $allowHoliday = true): WorkOrderCrew
    {
        $crew = $this->currentCrew($wo, $ncrId);
        if (! $crew) {
            throw new BusinessRuleException('Assign a crew and one output recorder before starting or recording production.');
        }
        $ids = $crew->members->pluck('employee_id');
        $query = $this->productionEmployees()->whereIn('id', $ids)->orderBy('id');
        $employees = ($lock ? $query->lockForUpdate() : $query)->get();
        $active = $crew->members->filter(fn ($m) => $employees->contains('id', $m->employee_id));
        $recorder = $active->firstWhere('employee_id', $crew->recorder_employee_id)?->employee;
        if ($active->count() !== $crew->members->count() || $active->count() < ($ncrId ? 1 : max(1, $wo->minimum_workers)) || $active->count() > ($ncrId ? 4 : min(4, $wo->maximum_workers ?? 4))
            || ! $recorder || ! $this->employeeOption($recorder)['has_active_account']) {
            throw new BusinessRuleException('Crew eligibility or staffing limits changed. Production management must update the crew and recorder.');
        }

        $slot = $executionWindow ?? $this->schedule($wo, $ncrId);
        foreach ($employees as $employee) {
            $this->assertWorkerAvailable($employee, $wo, $starting ? $slot : null, $ncrId, true);
        }
        if ($starting && $slot) {
            $remainingSlot = new ProductionSchedule(['scheduled_start' => now()->max($slot->scheduled_start), 'scheduled_end' => $slot->scheduled_end]);
            foreach ($employees as $employee) {
                $blocker = $this->workerBlocker($employee, $wo, $remainingSlot, $ncrId, true, true, $allowHoliday);
                if ($blocker !== null) {
                    throw new BusinessRuleException('Worker availability changed: '.$blocker);
                }
            }
            if ($requireVerifiedPresence && $employees->contains(fn ($employee) => $this->presence($employee, now())['state'] !== 'present')) {
                throw new BusinessRuleException('Early start requires verified attendance for every assigned worker.');
            }
            $this->assertPresence($wo, $employees, $actor, $confirmedIds, $ncrId);
        }

        return $crew;
    }

    public function assertOperatorPresent(WorkOrder $wo, Employee $employee): void
    {
        $presence = $this->presence($employee, now());
        if ($presence['state'] === 'blocked' || ($presence['state'] === 'unknown' && ! $this->presenceAttested($wo, $employee))) {
            throw new BusinessRuleException($employee->full_name.': '.($presence['reason'] ?? 'Attendance is unverified.').' Ask Production management to review attendance.');
        }
    }

    private function presenceAttested(WorkOrder $wo, Employee $employee, ?int $ncrId = null): bool
    {
        $slot = $this->schedule($wo, $ncrId);
        $interval = $this->shiftInterval($employee, now(), true);

        return $slot && $interval && $slot->scheduled_start->lte(now()) && $slot->scheduled_end->gt(now())
            && ActivityEvent::where('type', 'production')->where('action', 'presence_confirmed')->where('subject_id', $wo->id)
                ->where('detail->crew_id', $this->currentCrew($wo, $ncrId)?->id)->where('detail->schedule_id', $slot->id)
                ->whereJsonContains('detail->employee_ids', $employee->id)->where('created_at', '>=', $interval['start'])
                ->where('created_at', '<=', now())->exists();
    }

    public function assertCanRecord(User $actor, WorkOrder $wo, int $crewId, ?int $ncrId = null, ?string $recordingReason = null): WorkOrderCrew
    {
        abort_unless($actor->is_active, 403);
        $crew = $this->assertReadyForStart($wo, $ncrId);
        if ($crew->id !== $crewId) {
            throw new BusinessRuleException('The crew changed. Refresh before recording output.');
        }
        if (! $actor->hasPermission('production.wo.record')) {
            $this->internal($actor);
            $this->assertCanRead($actor, $wo, $ncrId);
            if ($crew->recorder_employee_id !== $actor->employee_id) {
                throw new BusinessRuleException('Only the designated recorder can enter output for this crew.');
            }
        }
        $recorder = $crew->members->firstWhere('employee_id', $crew->recorder_employee_id)->employee;
        $presence = $this->presence($recorder, now());
        $slot = $this->schedule($wo, $ncrId);
        $withinSlot = $slot && $slot->scheduled_start->lte(now()) && $slot->scheduled_end->gt(now());
        $attested = $withinSlot && $presence['state'] === 'unknown' && $this->presenceAttested($wo, $recorder, $ncrId);
        if (! $withinSlot || ($presence['state'] !== 'present' && ! $attested)) {
            if (! $actor->hasPermission('production.wo.record')) {
                throw new BusinessRuleException('Recording access expired or attendance changed. Ask Production management to extend the run or report earlier output.');
            }
            abort_unless($actor->hasPermission('production.work_orders.lifecycle'), 403);
            if (trim($recordingReason ?? '') === '') {
                throw new BusinessRuleException('Enter a recording reason to report output made before attendance or the schedule changed.');
            }
        }

        return $crew;
    }
}
