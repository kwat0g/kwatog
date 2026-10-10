<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Requests\ApplyWorkOrderAssignmentRequest;
use App\Modules\Production\Requests\AssignWorkOrderCrewRequest;
use App\Modules\Production\Requests\GenerateWorkOrderProposalRequest;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderAssignmentController
{
    public function __construct(private readonly WorkOrderAssignmentService $crews) {}

    private function staffingLimits(Request $request, WorkOrder $workOrder): array
    {
        $validated = $request->validate([
            'minimum_workers' => ['sometimes', 'required', 'integer', 'min:1', 'max:4'],
            'maximum_workers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4'],
        ]);
        $minimum = (int) ($validated['minimum_workers'] ?? $workOrder->minimum_workers);
        $maximum = array_key_exists('maximum_workers', $validated)
            ? ($validated['maximum_workers'] === null ? null : (int) $validated['maximum_workers'])
            : ($workOrder->maximum_workers ?? 4);
        if ($maximum !== null && $maximum < $minimum) {
            throw ValidationException::withMessages(['maximum_workers' => 'Maximum workers must be at least minimum workers.']);
        }

        return ['minimum_workers' => $minimum, 'maximum_workers' => $maximum];
    }

    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $ncrId = $request instanceof AssignWorkOrderCrewRequest
            ? $request->validated('rework_ncr_id')
            : ($request->filled('rework_ncr_id') ? NonConformanceReport::decodeHash($request->string('rework_ncr_id')->toString()) : null);
        $current = $this->crews->currentCrew($workOrder, $ncrId);
        $schedule = $this->crews->schedule($workOrder, $ncrId);

        return response()->json(['data' => ['current' => $current ? $this->crews->serialize($current, $schedule?->scheduled_start) : null,
            'server_time' => now()->toIso8601String(),
            'schedule' => $schedule ? $this->crews->serializeSchedule($schedule) : null,
            'presence' => $current?->members->map(fn ($member) => ['employee_id' => $member->employee->hash_id, 'name' => $member->employee->full_name] + $this->crews->presence($member->employee, now()))->values()->all() ?? [],
            'history' => $workOrder->crews()->where('rework_ncr_id', $ncrId)->with('members.employee.user', 'ncr')->get()->map(fn ($c) => $this->crews->serialize($c)),
            'minimum_workers' => $workOrder->minimum_workers, 'maximum_workers' => $workOrder->maximum_workers,
            'production_deadline' => $workOrder->planned_end?->toIso8601String(),
            'preferred_production_date' => $workOrder->preferred_production_date?->toDateString(),
            'delivery_date' => $workOrder->salesOrderItem?->delivery_date?->toDateString(),
            'can_approve_late_production' => ($ncrId !== null || $workOrder->status->value === 'paused')
                && $request->user()->hasPermission('production.work_orders.lifecycle'),
            'can_review_slot' => $this->crews->canAssign($request->user(), $workOrder)
                && ($request->user()->hasPermission('production.schedule.confirm') || $request->user()->hasPermission('production.wo.confirm'))
                && ($workOrder->status->value === 'confirmed' || $request->user()->hasPermission('production.work_orders.lifecycle'))
                && ($ncrId !== null || in_array($workOrder->status->value, ['confirmed', 'paused'], true)),
            'can_assign' => $this->crews->canAssign($request->user(), $workOrder)
                && (! in_array($workOrder->status->value, ['closed', 'cancelled'], true))
                && ($ncrId !== null || $workOrder->status->value !== 'completed')]]);
    }

    public function options(Request $request, WorkOrder $workOrder): JsonResponse
    {
        abort_unless($this->crews->canAssign($request->user(), $workOrder), 403);
        $request->validate(['search' => ['nullable', 'string', 'max:100'],
            'scheduled_start' => ['nullable', 'date', 'required_with:scheduled_end'],
            'scheduled_end' => ['nullable', 'date', 'required_with:scheduled_start', 'after:scheduled_start'],
            'holiday_authorized' => ['sometimes', 'boolean']]);
        $search = trim((string) $request->query('search', ''));
        $query = $this->crews->productionEmployees();
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')
                ->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('employee_no', 'ilike', '%'.$search.'%'));
        }

        $ncrId = $request->filled('rework_ncr_id') ? NonConformanceReport::decodeHash($request->string('rework_ncr_id')->toString()) : null;
        $slot = $this->crews->schedule($workOrder, $ncrId);
        $allowHoliday = $this->crews->holidayAuthorized($slot)
            || ($request->boolean('holiday_authorized') && $request->user()->hasPermission('production.work_orders.lifecycle'));
        if ($request->filled('scheduled_start')) {
            $start = Carbon::parse($request->input('scheduled_start'))->timezone(config('app.timezone'));
            $end = Carbon::parse($request->input('scheduled_end'))->timezone(config('app.timezone'));
            if ($start->diffInDays($end, true) > 2 || $end->lte(now())) {
                throw new BusinessRuleException('Review an unexpired production interval of at most two days.');
            }
            $slot = new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end]);
        } elseif ($slot && in_array($workOrder->status->value, ['in_progress', 'paused'], true) && $slot->scheduled_end->gt(now())) {
            $slot = new ProductionSchedule(['scheduled_start' => now()->max($slot->scheduled_start), 'scheduled_end' => $slot->scheduled_end]);
        }
        $isUnstartedRepair = $ncrId && ! NonConformanceReport::query()->findOrFail($ncrId)->rework_started_at;
        $isPrestartAssignment = in_array($workOrder->status->value, ['planned', 'confirmed'], true) || $isUnstartedRepair;
        if ($slot) {
            return response()->json(['data' => $this->crews->workerOptions($workOrder, $slot, $ncrId,
                checkHr: true, runtime: in_array($workOrder->status->value, ['in_progress', 'paused'], true),
                employeesQuery: $query, allowHoliday: $allowHoliday)]);
        }

        return response()->json(['data' => $query->orderBy('last_name')->orderBy('id')->get()
            ->map(fn ($e) => $this->crews->employeeOption($e) + ['can_assign' => true, 'blocked_reason' => null])]);
    }

    public function suggestion(Request $request, WorkOrder $workOrder): JsonResponse
    {
        abort_unless($this->crews->canAssign($request->user(), $workOrder), 403);
        $staffingLimits = $this->staffingLimits($request, $workOrder);
        $ncrId = $request->filled('rework_ncr_id') ? NonConformanceReport::decodeHash($request->string('rework_ncr_id')->toString()) : null;
        if ($ncrId) {
            app(WorkOrderReworkService::class)->assertRepair($workOrder, NonConformanceReport::findOrFail($ncrId));
        }
        $schedule = $this->crews->schedule($workOrder, $ncrId);
        $current = $this->crews->currentCrew($workOrder, $ncrId);

        $suggestion = $schedule ? $this->crews->suggest($workOrder, $schedule, $ncrId, $staffingLimits) : null;
        if ($suggestion) {
            $equipmentBlockers = array_filter(app(CapacityPlanningService::class)->readiness($schedule)['blockers'],
                fn ($text) => ! str_starts_with($text, 'Assign a Production crew') && ! str_starts_with($text, 'Assigned crew') && ! str_starts_with($text, 'Crew eligibility'));
            $suggestion['equipment_blockers'] = array_values(array_unique($equipmentBlockers));
            $suggestion['blockers'] = array_values(array_unique(array_merge($suggestion['staffing_blockers'], $suggestion['equipment_blockers'])));
        }

        if ($suggestion) {
            $suggestion['readiness_blockers'] = app(WorkOrderService::class)->proposalReadiness($workOrder);
            $suggestion['preferred_production_date'] = $workOrder->preferred_production_date?->toDateString();
        }

        return response()->json(['data' => $suggestion
            ?? ['schedule' => null, 'members' => [], 'recorder_employee_id' => null,
                'eligible_workers' => [], 'worker_options' => [], 'minimum_workers' => $staffingLimits['minimum_workers'],
                'maximum_workers' => $staffingLimits['maximum_workers'], 'expected_crew_id' => $current?->hash_id,
                'staffing_blockers' => [],
                'equipment_blockers' => ['No authoritative reservation exists. PPC must generate and review a schedule proposal.'],
                'blockers' => ['No authoritative reservation exists. PPC must generate and review a schedule proposal.']]]);
    }

    public function generateSuggestion(GenerateWorkOrderProposalRequest $request, WorkOrder $workOrder): JsonResponse
    {
        abort_unless($this->crews->canAssign($request->user(), $workOrder), 403);
        $this->staffingLimits($request, $workOrder);
        if ($request->filled('rework_ncr_id') || $workOrder->status->value !== 'planned') {
            throw new BusinessRuleException('Automatic equipment proposals support unstarted Planned production only. PPC must review other contexts explicitly.');
        }
        $options = $request->validated();
        $result = app(CapacityPlanningService::class)->run([$workOrder->id], [$workOrder->id => $options]);
        $response = $this->suggestion($request, $workOrder->fresh());
        $data = $response->getData(true);
        $preferred = array_key_exists('preferred_production_date', $options) ? $options['preferred_production_date'] : $workOrder->preferred_production_date?->toDateString();
        $data['data']['preferred_production_date'] = $preferred;
        if ($result['conflicts'] !== []) {
            $data['data']['schedule'] = null;
            $data['data']['members'] = [];
            $data['data']['recorder_employee_id'] = null;
            $data['data']['equipment_blockers'] = $result['conflicts'][0]['reasons'];
            $data['data']['blockers'] = $result['conflicts'][0]['reasons'];
        } elseif ($preferred && substr($data['data']['schedule']['scheduled_start'], 0, 10) !== $preferred) {
            $data['data']['preferred_day_message'] = 'Selected day unavailable. Review the next available schedule below.';
        }

        return response()->json($data);
    }

    public function apply(ApplyWorkOrderAssignmentRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $workOrder, $data) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($this->crews->canAssign($request->user(), $wo), 403);
            if (! in_array($wo->status->value, ['planned', 'confirmed'], true)) {
                throw new BusinessRuleException('Use explicit crew handover for live production.');
            }
            if ($wo->status->value === 'planned') {
                abort_unless($request->user()->hasPermission('production.wo.confirm') || $request->user()->hasPermission('production.schedule.confirm'), 403);
            }
            $current = $this->crews->currentCrew($wo, $data['rework_ncr_id'] ?? null);
            // Resolve a lost response only when every submitted assignment field matches.
            $wanted = collect($data['employee_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($wo->status->value === 'confirmed' && $current && $current->id !== ($data['expected_crew_id'] ?? null)
                && $current->assigned_by === $request->user()->id
                && $current->members->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $wanted
                && (int) $current->recorder_employee_id === (int) $data['recorder_employee_id']
                && $current->reason === trim($data['reason'])
                && (int) $wo->minimum_workers === (int) $data['minimum_workers']
                && $wo->maximum_workers === ($data['maximum_workers'] ?? 4)
                && $this->crews->schedule($wo)?->id === $data['schedule_id']
                && (! array_key_exists('preferred_production_date', $data) || $wo->preferred_production_date?->toDateString() === $data['preferred_production_date'])) {
                return;
            }
            $slot = $this->crews->schedule($wo, $data['rework_ncr_id'] ?? null);
            if (! $slot || $slot->id !== $data['schedule_id'] || ! $slot->updated_at->equalTo(Carbon::parse($data['schedule_updated_at']))) {
                throw new BusinessRuleException('The schedule changed. Refresh and review the proposal before applying.');
            }
            $machine = $slot->machine()->lockForUpdate()->firstOrFail();
            $mold = $slot->mold()->lockForUpdate()->firstOrFail();
            app(ProductionResourceService::class)->assertReservation($wo, $machine, $mold,
                $slot->scheduled_start, $slot->scheduled_end, $slot->id, $data['rework_ncr_id'] ?? null);
            $employees = $this->crews->eligibleWorkers($wo, $slot->scheduled_start, $slot->scheduled_end, $data['rework_ncr_id'] ?? null);
            foreach ($data['employee_ids'] as $id) {
                if (! $employees->contains('id', $id)) {
                    throw new BusinessRuleException('A selected worker no longer has full HR shift coverage or has leave or conflicting work. Refresh the suggestion.');
                }
            }
            if (array_key_exists('preferred_production_date', $data)) {
                abort_unless($request->user()->hasPermission('production.wo.confirm') || $request->user()->hasPermission('production.schedule.confirm'), 403);
                if ($data['preferred_production_date'] && $data['preferred_production_date'] < now()->toDateString()
                    && $wo->preferred_production_date?->toDateString() !== $data['preferred_production_date']) {
                    throw new BusinessRuleException('Choose today or a future production day.');
                }
                if ($data['preferred_production_date'] && $slot->scheduled_start->toDateString() < $data['preferred_production_date']) {
                    throw new BusinessRuleException('Schedule precedes the selected production day. Review a new proposal.');
                }
                $wo->update(['preferred_production_date' => $data['preferred_production_date']]);
            }
            $this->crews->assign($wo, $data, $request->user());
            $locked = ProductionSchedule::query()->lockForUpdate()->findOrFail($slot->id);
            if (! $locked->updated_at->equalTo(Carbon::parse($data['schedule_updated_at']))) {
                throw new BusinessRuleException('The schedule changed. Refresh the proposal.');
            }
            if ($wo->status->value === 'planned') {
                app(WorkOrderService::class)->confirm($wo, $machine->id, $mold->id);
            }
        }, 3);

        return $this->show($request, $workOrder->fresh());
    }

    public function store(AssignWorkOrderCrewRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->crews->assign($workOrder, $request->validated(), $request->user());

        return $this->show($request, $workOrder->fresh());
    }
}
