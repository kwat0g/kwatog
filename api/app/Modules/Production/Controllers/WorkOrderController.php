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

    /**
     * @OA\Get(
     *     path="/production/work-orders",
     *     tags={"Work Orders"},
     *     summary="List work orders",
     *     description="Returns a paginated list of work orders. Filterable by status, product, and date range.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","confirmed","in_progress","paused","completed","closed","cancelled"})),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated work order list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return WorkOrderResource::collection($this->service->list($request->query()));
    }

    /**
     * @OA\Get(
     *     path="/production/work-orders/{id}",
     *     tags={"Work Orders"},
     *     summary="Show work order detail",
     *     description="Returns full work order details including product, machine, mold assignment, outputs, and chain links.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Work order hash ID"),
     *     @OA\Response(response=200, description="Work order detail"),
     *     @OA\Response(response=404, description="Work order not found")
     * )
     */
    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        return new WorkOrderResource($this->service->show($workOrder));
    }

    /**
     * @OA\Post(
     *     path="/production/work-orders",
     *     tags={"Work Orders"},
     *     summary="Create a draft work order",
     *     description="Creates a new work order in draft status. Generates WO number (WO-YYYYMM-NNNN).",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"product_id", "quantity"},
     *         @OA\Property(property="product_id", type="string", description="Product hash ID"),
     *         @OA\Property(property="quantity", type="integer", minimum=1),
     *         @OA\Property(property="priority", type="string", enum={"low","normal","high","urgent"}),
     *         @OA\Property(property="planned_start", type="string", format="date"),
     *         @OA\Property(property="planned_end", type="string", format="date"),
     *         @OA\Property(property="notes", type="string")
     *     )),
     *     @OA\Response(response=201, description="Draft work order created"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * @OA\Post(
     *     path="/production/work-orders/{id}/confirm",
     *     tags={"Work Orders"},
     *     summary="Confirm a draft work order",
     *     description="Assigns machine and mold to the work order and transitions from draft to confirmed.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"machine_id"},
     *         @OA\Property(property="machine_id", type="string", description="Machine hash ID"),
     *         @OA\Property(property="mold_id", type="string", description="Mold hash ID (optional)")
     *     )),
     *     @OA\Response(response=200, description="Work order confirmed"),
     *     @OA\Response(response=422, description="Invalid state transition or validation error")
     * )
     */
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

    /**
     * @OA\Post(
     *     path="/production/work-orders/{id}/start",
     *     tags={"Work Orders"},
     *     summary="Start production on a confirmed work order",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Work order started"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Invalid state transition")
     * )
     */
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
            $workOrder->outputs()->with(['recorder:id,name,role_id', 'defects.defectType'])->get()
        );
    }
}
