<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Requests\RecordOutputRequest;
use App\Modules\Production\Requests\RecordTrialOutputRequest;
use App\Modules\Production\Requests\StoreLateProductionReportRequest;
use App\Modules\Production\Resources\LateProductionReportResource;
use App\Modules\Production\Services\LateProductionReportService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderMaterialHandoverService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AssignedWorkOrderController
{
    public function __construct(private readonly WorkOrderAssignmentService $crews) {}

    private function reportInterval(WorkOrder $wo, WorkOrderCrew $crew): array
    {
        // Released schedule rows still describe the historical run.
        $schedule = $wo->schedules()->whereNull('rework_ncr_id')->whereIn('status', ['confirmed', 'executed'])
            ->where('scheduled_end', '>', $crew->assigned_at)
            ->when($crew->ended_at, fn ($query) => $query->where('scheduled_start', '<', $crew->ended_at))
            ->orderByDesc('id')->first();
        if (! $schedule || ! $wo->actual_start) {
            return ['started_at' => null, 'finished_at' => null,
                'blocker' => 'No saved production schedule. Ask Production management to review this run.'];
        }
        $start = $schedule->scheduled_start->copy()->max($wo->actual_start)
            ->max(Carbon::parse($crew->assigned_at->format('Y-m-d H:i:s'), config('app.timezone')));
        $finish = $schedule->scheduled_end->copy();
        if ($wo->actual_end) {
            $finish = $finish->min($wo->actual_end);
        }
        if ($crew->ended_at) {
            $finish = $finish->min(Carbon::parse($crew->ended_at->format('Y-m-d H:i:s'), config('app.timezone')));
        }

        return ['started_at' => $start->toIso8601String(), 'finished_at' => $finish->toIso8601String(),
            'blocker' => $finish->lte($start) || $start->diffInSeconds($finish, true) > 86400
                ? 'Schedule does not cover this crew’s production run. Ask Production management to review it.'
                : ($finish->gt(now()) ? 'Scheduled finish has not passed. Use Record production for current output.' : null)];
    }

    private function context(Request $request): ?int
    {
        return $request->filled('rework_ncr_id') ? NonConformanceReport::decodeHash((string) $request->query('rework_ncr_id')) : null;
    }

    private function detail(Request $request, WorkOrderCrew $crew): array
    {
        $crew->loadMissing('workOrder.product', 'workOrder.machine', 'workOrder.mold', 'ncr');
        $wo = $crew->workOrder;
        $blocker = null;
        $trial = $crew->ncr ? null : app(WorkOrderOutputService::class)->trialState($wo);
        $canRecordTrial = false;
        try {
            $this->crews->assertCanRecord($request->user(), $wo, $crew->id, $crew->rework_ncr_id);
            $canRecordTrial = $wo->status->value === 'in_progress' && ($trial['state'] ?? null) === 'awaiting_samples';
        } catch (BusinessRuleException) {
            // The service rechecks recorder access and readiness before capture.
        }
        try {
            $this->crews->assertCanRecord($request->user(), $wo, $crew->id, $crew->rework_ncr_id);
            if ($crew->ncr) {
                app(WorkOrderReworkService::class)->assertRepair($wo, $crew->ncr);
                if (! $crew->ncr->rework_started_at || $crew->ncr->rework_finished_at) {
                    throw new BusinessRuleException('Production management must start this repair before output can be entered.');
                }
            } elseif ($wo->status->value !== 'in_progress') {
                throw new BusinessRuleException('Production management must start or resume this WO before output can be entered.');
            }
            $wo->assertProcessQualityReleased();
        } catch (BusinessRuleException $e) {
            $blocker = ['code' => 'production_blocked', 'message' => $e->getMessage(), 'owner' => 'Production management / QC'];
        }
        $repairRecorded = $crew->ncr ? (int) $wo->outputs()->where('rework_ncr_id', $crew->ncr->id)->sum(DB::raw('good_count + reject_count')) : 0;
        $machine = $crew->ncr ? Machine::find($crew->ncr->rework_machine_id) : $wo->machine;
        $mold = $crew->ncr ? Mold::find($crew->ncr->rework_mold_id) : $wo->mold;
        $handover = app(WorkOrderMaterialHandoverService::class)->summary($wo);
        if ($blocker === null && $handover['pending'] > 0) {
            $blocker = ['code' => 'material_handover_pending', 'message' => 'Warehouse handover is pending. Ask Warehouse to confirm before recording output.', 'owner' => 'Warehouse'];
        }

        return ['id' => $wo->hash_id, 'wo_number' => $wo->wo_number, 'status' => $wo->status->value,
            'batch_number' => $wo->batch_number,
            'qc_workflow' => $wo->getAttribute('qc_workflow'),
            'report_interval' => $this->reportInterval($wo, $crew),
            'startup_trial' => $trial, 'can_record_trial' => $canRecordTrial,
            'warehouse_handover' => $handover, 'warehouse_handover_pending' => $handover['pending'],
            'product' => ['part_number' => $wo->product->part_number, 'name' => $wo->product->name, 'unit_of_measure' => $wo->product->unit_of_measure],
            'machine' => $machine ? ['code' => $machine->machine_code, 'name' => $machine->name] : null,
            'mold' => $mold ? ['code' => $mold->mold_code, 'name' => $mold->name] : null,
            'quantity_target' => (int) $wo->quantity_target, 'quantity_produced' => (int) $wo->quantity_produced,
            'quantity_good' => (int) $wo->quantity_good, 'quantity_rejected' => (int) $wo->quantity_rejected,
            'remaining_produced' => max(0, (int) $wo->quantity_target - (int) $wo->quantity_produced),
            'remaining_good' => max(0, (int) $wo->quantity_target - (int) $wo->quantity_good),
            'crew' => $this->crews->serialize($crew), 'can_record' => $blocker === null, 'blocker' => $blocker,
            'can_report_late_production' => $request->user()->is_active
                && (int) $request->user()->employee_id === (int) $crew->recorder_employee_id
                && $crew->rework_ncr_id === null && $wo->actual_start !== null && $wo->status->value !== 'cancelled',
            'repair' => $crew->ncr ? ['ncr_id' => $crew->ncr->hash_id, 'remaining_quantity' => max(0, (int) $crew->ncr->confirmed_rework_quantity - $repairRecorded),
                'started' => (bool) $crew->ncr->rework_started_at] : null];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->crews->assignedQuery($request->user())->with('workOrder.product', 'workOrder.machine', 'workOrder.mold', 'ncr')
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 15));

        return response()->json(['data' => $page->getCollection()->map(fn ($c) => $this->detail($request, $c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($request->filled('crew_id')) {
            $crewId = WorkOrderCrew::tryDecodeHash((string) $request->query('crew_id'));
            abort_if($crewId === null, 404);
            $crew = $this->crews->assertCanReadHistorical($request->user(), $workOrder, $crewId);

            return response()->json(['data' => $this->historicalDetail($request, $workOrder, $crew)]);
        }
        try {
            $crew = $this->crews->assertCanRead($request->user(), $workOrder, $this->context($request));
        } catch (ModelNotFoundException) {
            $crew = $this->crews->assertCanReadHistorical($request->user(), $workOrder, $this->context($request));

            return response()->json(['data' => $this->historicalDetail($request, $workOrder, $crew)]);
        }

        return response()->json(['data' => $this->detail($request, $crew)]);
    }

    private function historicalCrew(WorkOrderCrew $crew): array
    {
        $crew->loadMissing('members');

        return ['id' => $crew->hash_id,
            'recorder_employee_id' => app('hashids')->encode($crew->recorder_employee_id),
            'assigned_at' => $crew->assigned_at->toIso8601String(), 'ended_at' => $crew->ended_at?->toIso8601String(),
            'reason' => $crew->reason, 'rework_ncr_id' => $crew->rework_ncr_id ? app('hashids')->encode($crew->rework_ncr_id) : null,
            'members' => $crew->members->map(static function ($member): array {
                $snapshot = $member->employee_snapshot ?? [];

                return ['id' => app('hashids')->encode($member->employee_id), 'employee_number' => $snapshot['employee_no'] ?? '',
                    'name' => $snapshot['name'] ?? 'Crew member', 'has_active_account' => false, 'shift' => null];
            })->values()->all()];
    }

    private function ownLateReports(Request $request, WorkOrder $workOrder, WorkOrderCrew $crew): array
    {
        $query = LateProductionReport::query()->where('work_order_id', $workOrder->id)
            ->where('work_order_crew_id', $crew->id)->where('recorded_by', $request->user()->id);
        $count = (clone $query)->count();
        $reports = $query->with(['workOrder', 'crew', 'submitter', 'reviewer', 'output'])
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (LateProductionReport $report): array => (new LateProductionReportResource($report))->toArray($request))->all();

        return ['count' => $count, 'reports' => $reports];
    }

    private function historicalDetail(Request $request, WorkOrder $workOrder, WorkOrderCrew $crew): array
    {
        $workOrder->loadMissing('product');
        $reports = $this->ownLateReports($request, $workOrder, $crew);
        $canReport = $request->user()->is_active && (int) $request->user()->employee_id === (int) $crew->recorder_employee_id
            && $crew->rework_ncr_id === null && $workOrder->actual_start !== null && $workOrder->status->value !== 'cancelled';

        return ['id' => $workOrder->hash_id, 'wo_number' => $workOrder->wo_number, 'status' => $workOrder->status->value,
            'batch_number' => $workOrder->batch_number,
            'qc_workflow' => $workOrder->getAttribute('qc_workflow'),
            'report_interval' => $this->reportInterval($workOrder, $crew),
            'historical_report_only' => true,
            'product' => ['part_number' => $workOrder->product->part_number, 'name' => $workOrder->product->name,
                'unit_of_measure' => $workOrder->product->unit_of_measure],
            'quantity_target' => (int) $workOrder->quantity_target, 'quantity_good' => (int) $workOrder->quantity_good,
            'quantity_produced' => (int) $workOrder->quantity_produced,
            'quantity_rejected' => (int) $workOrder->quantity_rejected,
            'remaining_good' => max(0, (int) $workOrder->quantity_target - (int) $workOrder->quantity_good),
            'remaining_produced' => max(0, (int) $workOrder->quantity_target - (int) $workOrder->quantity_produced),
            'machine' => null, 'mold' => null, 'repair' => null,
            'crew' => $this->historicalCrew($crew), 'can_record' => false,
            'can_report_late_production' => $canReport,
            'late_production_report_count' => $reports['count'],
            'blocker' => ['code' => 'historical_report_only', 'message' => $workOrder->status->value === 'cancelled'
                ? 'Cancelled work orders cannot receive late production reports.'
                : 'This crew assignment ended. Report earlier production for independent management review.',
                'owner' => 'Production management'],
            'late_production_reports' => $reports['reports']];
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->crews->historicalCrewQuery($request->user())
            ->whereHas('workOrder', fn ($query) => $query->whereNotNull('actual_start'))
            ->with(['workOrder.product', 'members'])->orderByDesc('assigned_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['data' => $page->getCollection()->map(function (WorkOrderCrew $crew) use ($request): array {
            $workOrder = $crew->workOrder;
            $reports = $this->ownLateReports($request, $workOrder, $crew);

            return ['work_order' => ['id' => $workOrder->hash_id, 'wo_number' => $workOrder->wo_number,
                'status' => $workOrder->status->value, 'actual_start' => $workOrder->actual_start?->toIso8601String(),
                'actual_end' => $workOrder->actual_end?->toIso8601String(), 'qc_workflow' => $workOrder->getAttribute('qc_workflow')],
                'crew' => $this->historicalCrew($crew),
                'can_report_late_production' => $request->user()->is_active
                    && (int) $request->user()->employee_id === (int) $crew->recorder_employee_id
                    && $crew->rework_ncr_id === null && $workOrder->actual_start !== null && $workOrder->status->value !== 'cancelled',
                'late_production_report_count' => $reports['count'],
                'late_production_reports' => $reports['reports']];
        })->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function lateReports(Request $request, WorkOrder $workOrder, LateProductionReportService $reports): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $reports->ownReports($request->user(), $workOrder)
            ->with(['workOrder', 'crew', 'submitter', 'reviewer', 'output'])->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['data' => $page->getCollection()->map(fn (LateProductionReport $report): array => (new LateProductionReportResource($report))->toArray($request))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function lateReportOptions(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $request->validate(['crew_id' => ['required', 'string', 'max:32']]);
        $crewId = WorkOrderCrew::tryDecodeHash((string) $request->query('crew_id'));
        abort_if($crewId === null, 404);
        $this->crews->assertCanSubmitHistorical($request->user(), $workOrder, $crewId);

        return response()->json(['data' => ['qc_workflow' => $workOrder->getAttribute('qc_workflow'),
            'defect_types' => $workOrder->usesFinishFirstQc() ? [] : DefectType::active()->orderBy('code')->get()
            ->map(fn ($defect) => ['id' => $defect->hash_id, 'code' => $defect->code, 'name' => $defect->name])->values()]]);
    }

    public function proposeLateProduction(StoreLateProductionReportRequest $request, WorkOrder $workOrder, LateProductionReportService $reports): JsonResponse
    {
        $key = $request->header('X-Idempotency-Key');
        abort_unless(is_string($key) && $key !== '' && strlen($key) <= 128, 422, 'A submission key is required.');
        $report = $reports->propose($workOrder, $request->user(), $request->validated(), $key);

        return response()->json(['data' => (new LateProductionReportResource($report->load(['workOrder', 'crew', 'submitter', 'reviewer', 'output'])))->toArray($request)],
            $report->wasRecentlyCreated ? 201 : 200);
    }

    public function options(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->crews->assertCanRead($request->user(), $workOrder, $this->context($request));

        return response()->json(['data' => ['defect_types' => DefectType::active()->orderBy('code')->get()->map(fn ($d) => ['id' => $d->hash_id, 'code' => $d->code, 'name' => $d->name])]]);
    }

    public function receiptData(WorkOrderOutput $output): array
    {
        return ['id' => $output->hash_id, 'batch_code' => $output->batch_code, 'produced_count' => $output->physical_count, 'good_count' => $output->good_count,
            'held_quantity' => $output->held_quantity, 'production_started_at' => $output->production_started_at?->toIso8601String(),
            'production_finished_at' => $output->production_finished_at?->toIso8601String(),
            'reject_count' => $output->reject_count, 'trial_quantity' => $output->trial_quantity,
            'trial_inspection_id' => $output->trial_inspection_id ? app('hashids')->encode($output->trial_inspection_id) : null, 'trial_settled_at' => $output->trial_settled_at?->toIso8601String(), 'physical_count' => $output->physical_count, 'recorded_at' => $output->recorded_at->toIso8601String(),
            'receipt_status' => $output->production_receipt_handoff_status->value,
            'material_handover_exception' => $output->material_handover_exception];
    }

    public function outputs(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $crew = $this->crews->assertCanRead($request->user(), $workOrder, $this->context($request));

        return response()->json(['data' => $workOrder->outputs()->where('work_order_crew_id', $crew->id)
            ->reorder()->orderByDesc('recorded_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($o) => $this->receiptData($o))]);
    }

    public function record(RecordOutputRequest $request, WorkOrder $workOrder, WorkOrderOutputService $outputs, ?NonConformanceReport $ncr = null): JsonResponse
    {
        $this->crews->internal($request->user());
        $key = $request->header('X-Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) > 0 && strlen($key) <= 128, 422, 'A submission key is required.');
        $output = $outputs->record($workOrder, $request->validated(), $request->user()->id, $key, $ncr?->id);

        return response()->json(['data' => $this->receiptData($output)]);
    }

    public function recordTrial(RecordTrialOutputRequest $request, WorkOrder $workOrder, WorkOrderOutputService $outputs): JsonResponse
    {
        $this->crews->internal($request->user());
        $key = $request->header('X-Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) > 0 && strlen($key) <= 128, 422, 'A submission key is required.');
        $output = $outputs->recordTrial($workOrder, $request->validated(), $request->user()->id, $key);

        return response()->json(['data' => $this->receiptData($output)]);
    }

    public function receipt(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if (! $request->user()->hasPermission('production.wo.record')) {
            $this->crews->internal($request->user());
        }
        $request->validate(['idempotency_key' => ['required', 'string', 'max:128']]);
        $output = $workOrder->outputs()->where('recorded_by', $request->user()->id)->where('idempotency_key', $request->query('idempotency_key'))->firstOrFail();

        return response()->json(['data' => $this->receiptData($output)]);
    }
}
