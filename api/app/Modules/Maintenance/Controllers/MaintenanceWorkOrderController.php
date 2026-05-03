<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Requests\AssignMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Requests\CompleteMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Requests\RecordSparePartUsageRequest;
use App\Modules\Maintenance\Requests\StoreMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Resources\MaintenanceWorkOrderResource;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\SparePartUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaintenanceWorkOrderController
{
    public function __construct(
        private readonly MaintenanceWorkOrderService $service,
        private readonly SparePartUsageService $spareParts,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaintenanceWorkOrderResource::collection($this->service->list($request->query()));
    }

    public function show(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->service->show($workOrder));
    }

    public function store(StoreMaintenanceWorkOrderRequest $request): JsonResponse
    {
        $wo = $this->service->create($request->validated(), $request->user());
        return (new MaintenanceWorkOrderResource($wo))->response()->setStatusCode(201);
    }

    public function assign(AssignMaintenanceWorkOrderRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource(
            $this->service->assign($workOrder, (int) $request->input('employee_id'), $request->user())
        );
    }

    public function start(Request $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        abort_unless($request->user()?->can('maintenance.wo.complete'), 403);
        return new MaintenanceWorkOrderResource($this->service->start($workOrder, $request->user()));
    }

    public function complete(CompleteMaintenanceWorkOrderRequest $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource(
            $this->service->complete($workOrder, $request->validated(), $request->user())
        );
    }

    public function cancel(Request $request, MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        abort_unless($request->user()?->can('maintenance.wo.complete'), 403);
        $reason = $request->input('reason');
        return new MaintenanceWorkOrderResource(
            $this->service->cancel($workOrder, is_string($reason) ? $reason : null, $request->user())
        );
    }

    public function addLog(Request $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        abort_unless($request->user()?->can('maintenance.wo.complete'), 403);
        $data = $request->validate(['description' => ['required', 'string', 'max:5000']]);
        $log = $this->service->log($workOrder, (string) $data['description'], $request->user());
        return response()->json([
            'data' => [
                'id'          => $log->hash_id,
                'description' => $log->description,
                'created_at'  => optional($log->created_at)?->toISOString(),
            ],
        ], 201);
    }

    public function recordSparePart(RecordSparePartUsageRequest $request, MaintenanceWorkOrder $workOrder): JsonResponse
    {
        $usage = $this->spareParts->record($workOrder, $request->validated(), $request->user());
        return response()->json([
            'data' => [
                'id'         => $usage->hash_id,
                'item'       => $usage->item ? [
                    'id'   => $usage->item->hash_id,
                    'code' => $usage->item->code,
                    'name' => $usage->item->name,
                    'unit' => $usage->item->unit_of_measure,
                ] : null,
                'quantity'   => (string) $usage->quantity,
                'unit_cost'  => (string) $usage->unit_cost,
                'total_cost' => (string) $usage->total_cost,
                'created_at' => optional($usage->created_at)?->toISOString(),
            ],
        ], 201);
    }
}
