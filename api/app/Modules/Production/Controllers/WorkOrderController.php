<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Requests\CancelWorkOrderRequest;
use App\Modules\Production\Requests\ConfirmWorkOrderRequest;
use App\Modules\Production\Requests\PauseWorkOrderRequest;
use App\Modules\Production\Requests\StoreWorkOrderRequest;
use App\Modules\Production\Resources\WorkOrderResource;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class WorkOrderController
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return WorkOrderResource::collection($this->service->list($request->query()));
    }

    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        return new WorkOrderResource($this->service->show($workOrder));
    }

    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['created_by'] = $request->user()->id;
        $wo = $this->service->createDraft($payload);
        return (new WorkOrderResource($wo))->response()->setStatusCode(201);
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        try {
            $this->service->delete($workOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function confirm(ConfirmWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        try {
            $wo = $this->service->confirm(
                $workOrder,
                $request->input('machine_id'),
                $request->input('mold_id'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new WorkOrderResource($wo);
    }

    public function start(WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        if (! request()->user()->hasPermission('production.work_orders.lifecycle')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        try {
            $wo = $this->service->start($workOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new WorkOrderResource($wo);
    }

    public function pause(PauseWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        $wo = $this->service->pause(
            $workOrder,
            $request->input('reason'),
            MachineDowntimeCategory::from($request->input('category')),
        );
        return new WorkOrderResource($wo);
    }

    public function resume(WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        if (! request()->user()->hasPermission('production.work_orders.lifecycle')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return new WorkOrderResource($this->service->resume($workOrder));
    }

    public function complete(WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        if (! request()->user()->hasPermission('production.work_orders.lifecycle')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return new WorkOrderResource($this->service->complete($workOrder));
    }

    public function close(WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        if (! request()->user()->hasPermission('production.work_orders.lifecycle')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return new WorkOrderResource($this->service->close($workOrder));
    }

    public function cancel(CancelWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource|JsonResponse
    {
        try {
            $wo = $this->service->cancel($workOrder, $request->input('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new WorkOrderResource($wo);
    }

    public function chain(WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->service->chain($workOrder)]);
    }

    /** Sprint 6 Task 55 — record production output (idempotent). */
    public function recordOutput(
        \App\Modules\Production\Requests\RecordOutputRequest $request,
        WorkOrder $workOrder,
        \App\Modules\Production\Services\WorkOrderOutputService $outputs,
    ): \App\Modules\Production\Resources\WorkOrderOutputResource|JsonResponse {
        $idempotency = $request->header('X-Idempotency-Key');
        try {
            $output = $outputs->record(
                $workOrder,
                $request->validated(),
                $request->user()->id,
                $idempotency,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new \App\Modules\Production\Resources\WorkOrderOutputResource($output);
    }

    public function listOutputs(WorkOrder $workOrder): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return \App\Modules\Production\Resources\WorkOrderOutputResource::collection(
            $workOrder->outputs()->with(['recorder:id,name', 'defects.defectType'])->get()
        );
    }
}
