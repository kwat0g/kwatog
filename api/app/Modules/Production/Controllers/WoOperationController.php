<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Resources\WoOperationResource;
use App\Modules\Production\Services\WoOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use RuntimeException;

class WoOperationController
{
    public function __construct(private readonly WoOperationService $service) {}

    /**
     * List all operations for a work order.
     */
    public function index(Request $request, WorkOrder $workOrder): AnonymousResourceCollection
    {
        $operations = $workOrder->operations()
            ->with(['machine', 'mold', 'operator:id,first_name,last_name'])
            ->orderBy('sequence')
            ->get();

        return WoOperationResource::collection($operations);
    }

    /**
     * Show a single operation with its production logs.
     */
    public function show(WoOperation $operation): WoOperationResource
    {
        $operation->load([
            'workOrder',
            'machine',
            'mold',
            'operator:id,first_name,last_name',
            'logs.operator:id,first_name,last_name',
        ]);

        return new WoOperationResource($operation);
    }

    /**
     * Start setup phase.
     */
    public function startSetup(WoOperation $operation, Request $request): WoOperationResource|JsonResponse
    {
        $operator = $this->resolveOperator($request);
        if ($operator instanceof JsonResponse) {
            return $operator;
        }

        try {
            $this->service->startSetup($operation, $operator);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * End setup phase.
     */
    public function endSetup(WoOperation $operation): WoOperationResource|JsonResponse
    {
        try {
            $this->service->endSetup($operation);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Start production on an operation.
     */
    public function start(WoOperation $operation, Request $request): WoOperationResource|JsonResponse
    {
        $operator = $this->resolveOperator($request);
        if ($operator instanceof JsonResponse) {
            return $operator;
        }

        try {
            $this->service->startOperation($operation, $operator);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Pause an in-progress operation.
     */
    public function pause(WoOperation $operation): WoOperationResource|JsonResponse
    {
        try {
            $this->service->pauseOperation($operation);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Resume a paused operation.
     */
    public function resume(WoOperation $operation, Request $request): WoOperationResource|JsonResponse
    {
        $operator = $this->resolveOperator($request);
        if ($operator instanceof JsonResponse) {
            return $operator;
        }

        try {
            $this->service->resumeOperation($operation, $operator);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Record production output and optional scrap.
     */
    public function recordOutput(WoOperation $operation, Request $request): WoOperationResource|JsonResponse
    {
        $request->validate([
            'qty'          => ['required', 'numeric', 'min:0.0001'],
            'scrap'        => ['nullable', 'numeric', 'min:0'],
            'scrap_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->recordOutput(
                $operation,
                (float) $request->input('qty'),
                (float) $request->input('scrap', 0),
                $request->input('scrap_reason'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Complete an operation.
     */
    public function complete(WoOperation $operation): WoOperationResource|JsonResponse
    {
        try {
            $this->service->completeOperation($operation);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Skip an operation with a reason.
     */
    public function skip(WoOperation $operation, Request $request): WoOperationResource|JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->service->skipOperation($operation, $request->input('reason'));

        return new WoOperationResource($operation->fresh(['machine', 'mold', 'operator:id,first_name,last_name']));
    }

    /**
     * Machine schedule — operations grouped by machine for a date range.
     */
    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $grouped = $this->service->getScheduleByMachine(
            Carbon::parse($request->input('from')),
            Carbon::parse($request->input('to')),
        );

        // Transform: machine_id keys → array with machine info + operations
        $result = $grouped->map(function ($operations, $machineId) {
            $machine = $operations->first()->machine;
            return [
                'machine' => $machine ? [
                    'id'           => $machine->hash_id,
                    'machine_code' => $machine->machine_code,
                    'name'         => $machine->name,
                ] : null,
                'operations' => WoOperationResource::collection($operations),
            ];
        })->values();

        return response()->json(['data' => $result]);
    }

    /* ─── Private helpers ──────────────────────────────────────── */

    /**
     * Resolve an Employee from the operator_id hash in the request body.
     */
    private function resolveOperator(Request $request): Employee|JsonResponse
    {
        $hashId = $request->input('operator_id');
        if (! $hashId) {
            return response()->json(['message' => 'operator_id is required.'], 422);
        }

        $decoded = app('hashids')->decode($hashId);
        if (empty($decoded)) {
            return response()->json(['message' => 'Invalid operator_id.'], 422);
        }

        $operator = Employee::find($decoded[0]);
        if (! $operator) {
            return response()->json(['message' => 'Operator not found.'], 422);
        }

        return $operator;
    }
}
