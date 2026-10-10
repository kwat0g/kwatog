<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Requests\AssignMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Requests\CompleteMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Requests\RecordSparePartUsageRequest;
use App\Modules\Maintenance\Requests\StoreMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Requests\SubmitMaintenanceRepairRequest;
use App\Modules\Maintenance\Requests\VerifyMaintenanceRepairRequest;
use App\Modules\Maintenance\Resources\MaintenanceWorkOrderResource;
use App\Modules\Maintenance\Services\MaintenanceAssigneeSuggestionService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\MaintenanceWindowService;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MaintenanceWorkOrderController
{
    public function targetOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:machine,mold'], 'search' => ['nullable', 'string', 'max:100'], 'available_only' => ['sometimes', 'boolean']]);
        $machine = $data['type'] === 'machine';
        $model = $machine ? Machine::class : Mold::class;
        $code = $machine ? 'machine_code' : 'mold_code';
        $query = ! empty($data['available_only'])
            ? $this->service->selectableTargets(MaintainableType::from($data['type']))
            : $model::query();
        if (($term = trim($data['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where($code, SearchOperator::like(), '%'.$term.'%')
                ->orWhere('name', SearchOperator::like(), '%'.$term.'%'));
        }

        return response()->json(['data' => $query->orderBy($code)->limit(100)->get(['id', $code, 'name'])
            ->map(fn ($row) => ['id' => $row->hash_id, 'code' => $row->{$code}, 'name' => $row->name])]);
    }

    public function sparePartOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'work_order_id' => ['nullable', 'string']]);
        $order = isset($data['work_order_id']) ? MaintenanceWorkOrder::query()->findOrFail(
            HashIdFilter::decode($data['work_order_id'], MaintenanceWorkOrder::class)) : null;

        return response()->json(['data' => $this->spareParts->options($order, trim($data['search'] ?? ''))]);
    }

    public function sparePartStock(Request $request): JsonResponse
    {
        $request->validate(['item_id' => ['required', 'string']]);
        $id = HashIdFilter::decode($request->input('item_id'), Item::class);
        $item = Item::query()->whereKey($id)->where('item_type', 'spare_part')->where('is_active', true)->first();
        if (! $item) {
            throw ValidationException::withMessages(['item_id' => ['Select an active spare part.']]);
        }
        $rows = $item->stockLevels()->with('location.zone.warehouse')->orderBy('location_id')->get();
        $summary = app(StockLocationSummaryService::class);

        return response()->json(['data' => $rows->map(function ($row) use ($summary): array {
            $available = $summary->availability(collect([$row]))['usable_quantity'];

            return ['location' => ['id' => $row->location->hash_id, 'code' => $row->location->full_code], 'available' => $available];
        })->filter(fn ($row) => bccomp($row['available'], '0', 3) > 0)->values()]);
    }

    public function __construct(
        private readonly MaintenanceWorkOrderService $service,
        private readonly MaintenanceAssigneeSuggestionService $suggestions,
        private readonly SparePartUsageService $spareParts,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaintenanceWorkOrderResource::collection($this->service->list($request->query(), $request->user()->can('maintenance.view') ? null : $request->user()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'maintainable_types' => array_map(
                static fn (MaintainableType $type): array => ['value' => $type->value, 'label' => ucfirst($type->value)],
                MaintainableType::cases(),
            ),
            'types' => array_map(
                static fn (MaintenanceWorkOrderType $type): array => ['value' => $type->value, 'label' => ucfirst($type->value)],
                MaintenanceWorkOrderType::cases(),
            ),
            'priorities' => array_map(
                static fn (MaintenancePriority $priority): array => ['value' => $priority->value, 'label' => ucfirst($priority->value)],
                MaintenancePriority::cases(),
            ),
            'statuses' => array_map(
                static function (MaintenanceWorkOrderStatus $status): array {
                    return [
                        'value' => $status->value,
                        'label' => str_replace('_', ' ', ucfirst($status->value)),
                        'is_terminal' => $status->isTerminal(),
                    ];
                },
                MaintenanceWorkOrderStatus::cases(),
            ),
            'default_type' => (string) $this->settings->get('maintenance.work_order.default_type', ''),
            'default_priority' => (string) $this->settings->get('maintenance.work_order.default_priority', ''),
        ]]);
    }

    public function assignees(): JsonResponse
    {
        $employees = $this->service->eligibleAssignees()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'employee_no', 'first_name', 'last_name'])
            ->map(static fn (Employee $employee): array => [
                'id' => $employee->hash_id,
                'employee_no' => $employee->employee_no,
                'name' => trim($employee->first_name.' '.$employee->last_name),
            ])
            ->values();

        return response()->json(['data' => $employees]);
    }

    public function assigneeSuggestions(MaintenanceWorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->suggestions->suggest($workOrder, now(), now()->addMinute())]);
    }

    public function confirmWindow(Request $request, MaintenanceWorkOrder $workOrder, MaintenanceWindowService $windows): JsonResponse
    {
        $data = $request->validate([
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'employee_id' => ['required', 'string'],
        ]);
        $employeeId = HashIdFilter::decode($data['employee_id'], Employee::class);
        if (! $employeeId) {
            throw ValidationException::withMessages(['employee_id' => ['Select an active maintenance technician.']]);
        }
        $result = DB::transaction(function () use ($workOrder, $windows, $request, $data, $employeeId): array {
            $window = $windows->confirm($workOrder, Carbon::parse($data['planned_start_at']),
                Carbon::parse($data['planned_end_at']), $request->user());
            $order = $this->service->assign($workOrder, $employeeId, $request->user(), ['expected_assignee_id' => null]);

            return ['planned_start_at' => $window->planned_start_at->toIso8601String(),
                'planned_end_at' => $window->planned_end_at->toIso8601String(),
                'work_order_id' => $order->hash_id,
                'technician' => ['id' => $order->assignee->hash_id,
                    'name' => trim($order->assignee->first_name.' '.$order->assignee->last_name)]];
        });

        return response()->json(['data' => $result], 201);
    }

    public function show(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->service->show($workOrder));
    }

    public function store(StoreMaintenanceWorkOrderRequest $request): JsonResponse
    {
        $wo = $this->service->create($request->validated(), $request->user(), null, true);

        return (new MaintenanceWorkOrderResource($wo))->response()->setStatusCode(201);
    }

    public function assign(AssignMaintenanceWorkOrderRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource(
            $this->service->assign($workOrder, (int) $request->input('employee_id'), $request->user(), $request->validated())
        );
    }

    public function start(Request $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => $this->service->start($locked, $request->user()), claim: true, supervisorMayExecute: true));
    }

    public function complete(CompleteMaintenanceWorkOrderRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource(
            $this->asExecutor($request, $workOrder,
                fn (MaintenanceWorkOrder $locked) => $this->service->complete($locked, $request->validated(), $request->user()), supervisorMayExecute: true)
        );
    }

    public function submitRepair(SubmitMaintenanceRepairRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->service->submitRepair($workOrder, $request->validated(), $request->user()));
    }

    public function reconcileParts(Request $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        $data = $request->validate([
            'confirm_consumed' => ['sometimes', 'boolean'],
            'inspection_reason' => ['nullable', 'string', 'min:5', 'max:5000'],
        ]);

        return new MaintenanceWorkOrderResource($this->service->reconcileParts($workOrder, $data, $request->user()));
    }

    public function verifyRepair(VerifyMaintenanceRepairRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->service->verifyRepair($workOrder, $request->validated(), $request->user()));
    }

    public function cancel(Request $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:5000']])['reason'];

        return new MaintenanceWorkOrderResource(
            $this->asExecutor($request, $workOrder,
                fn (MaintenanceWorkOrder $locked) => $this->service->cancel($locked, trim($reason), $request->user()),
                creatorMayCancelUnassigned: true, supervisorMayCancel: true)
        );
    }

    public function addLog(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:5000']]);
        $log = $this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => $this->service->log($locked, (string) $data['description'], $request->user()), recording: true);

        return response()->json([
            'data' => [
                'id' => $log->hash_id,
                'description' => $log->description,
                'created_at' => optional($log->created_at)?->toISOString(),
            ],
        ], 201);
    }

    public function recordWork(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'finding' => ['required', 'string', 'max:5000'],
            'action_taken' => ['required', 'string', 'max:5000'],
            'result' => ['required', 'in:in_progress,passed,needs_follow_up'],
        ]);
        $log = $this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => $this->service->recordWork($locked, $data, $request->user()), recording: true);

        return response()->json(['data' => [
            'id' => $log->hash_id,
            'finding' => $log->finding,
            'action_taken' => $log->action_taken,
            'result' => $log->result,
            'created_at' => $log->created_at?->toISOString(),
        ]], 201);
    }

    public function recordSparePart(RecordSparePartUsageRequest $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        $partRequest = $this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => app(\App\Modules\Maintenance\Services\MaintenancePartRequestService::class)
                ->request($locked, $request->validated(), $request->user()), recording: true);
        return response()->json(['data' => ['id' => $partRequest->hash_id, 'status' => $partRequest->status,
            'quantity' => $partRequest->quantity]], 201);
    }

    public function withdrawPartRequest(Request $request, MaintenanceWorkOrder $workOrder,
        \App\Modules\Maintenance\Models\MaintenancePartRequest $partRequest): JsonResponse
    {
        $result = $this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => app(\App\Modules\Maintenance\Services\MaintenancePartRequestService::class)
                ->withdraw($locked, $partRequest, $request->user()), recording: true);
        return response()->json(['data' => ['id' => $result->hash_id, 'status' => $result->status]]);
    }

    public function returnSparePart(Request $request, MaintenanceWorkOrder $workOrder, SparePartUsage $usage): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'operation_key' => ['required', 'uuid'],
        ]);
        $returned = $this->asExecutor($request, $workOrder,
            fn (MaintenanceWorkOrder $locked) => $this->spareParts->returnPart(
                $locked, $usage, (string) $data['quantity'], $request->user(), $data['operation_key'],
            ), recording: true);

        return response()->json(['data' => [
            'id' => $returned->hash_id,
            'returned_quantity' => (string) $returned->returned_quantity,
            'returned_cost' => (string) $returned->returned_cost,
        ]]);
    }

    private function asExecutor(Request $request, MaintenanceWorkOrder $workOrder, Closure $action,
        bool $claim = false, bool $creatorMayCancelUnassigned = false, bool $supervisorMayCancel = false, bool $supervisorMayExecute = false, bool $recording = false): mixed
    {
        return DB::transaction(function () use ($request, $workOrder, $action, $claim, $creatorMayCancelUnassigned, $supervisorMayCancel, $supervisorMayExecute, $recording) {
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $actor = $request->user();
            if ($supervisorMayExecute) {
                abort_unless($actor->can('maintenance.wo.complete')
                    || MaintenanceWorkAccess::supervisorMayManage($locked, $actor), 403);
            }
            if ($actor->role?->slug === 'employee') {
                abort_unless($recording && MaintenanceWorkAccess::canRecord($locked, $actor), 403);
            }
            if ($actor->role?->slug !== 'system_admin') {
                $mayCancelAsSupervisor = $supervisorMayCancel && $actor->role?->slug === 'maintenance_supervisor';
                $mayWithdrawUnassigned = $locked->assigned_to === null
                    && $creatorMayCancelUnassigned && $locked->created_by === $actor->id;
                if (! $mayCancelAsSupervisor && ! $mayWithdrawUnassigned
                    && ! ($supervisorMayExecute && MaintenanceWorkAccess::supervisorMayManage($locked, $actor))) {
                    $employeeId = $actor->employee_id;
                    abort_if($employeeId === null, 403);
                    if ($locked->assigned_to === null && $claim && ! $locked->verification_required && $locked->status === MaintenanceWorkOrderStatus::Open) {
                        $locked->forceFill(['assigned_to' => $employeeId])->save();
                    } else {
                        abort_unless((int) $locked->assigned_to === (int) $employeeId, 403);
                    }
                }
            }

            return $action($locked);
        });
    }
}
