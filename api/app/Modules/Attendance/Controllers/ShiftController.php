<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Requests\AssignEmployeeShiftRequest;
use App\Modules\Attendance\Requests\BulkAssignShiftRequest;
use App\Modules\Attendance\Requests\StoreShiftRequest;
use App\Modules\Attendance\Requests\UpdateShiftRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\Attendance\Resources\ShiftResource;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Attendance\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Carbon\Carbon;
use App\Common\Exceptions\BusinessRuleException;

class ShiftController
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly ShiftAssignmentService $assignments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ShiftResource::collection($this->shifts->list($request->query()));
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $shift = $this->shifts->create($request->validated());
        return (new ShiftResource($shift))->response()->setStatusCode(201);
    }

    public function show(Shift $shift): ShiftResource
    {
        return new ShiftResource($shift);
    }

    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        return new ShiftResource($this->shifts->update($shift, $request->validated()));
    }

    public function destroy(Shift $shift): JsonResponse
    {
        try {
            $this->shifts->delete($shift);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function restore(Shift $shift): JsonResponse
    {
        $shift->restore();
        return response()->json(['message' => 'Shift restored.']);
    }

    public function bulkAssign(BulkAssignShiftRequest $request): JsonResponse
    {
        $d = $request->validatedData();
        $result = $this->assignments->bulkAssign(
            $d['department_id'],
            $d['shift_id'],
            $d['effective_date'],
            $d['end_date'] ?? null,
        );
        return response()->json(['data' => $result]);
    }

    public function assignEmployee(AssignEmployeeShiftRequest $request, Employee $employee): JsonResponse
    {
        $d = $request->validatedData();
        $this->assignments->assignToEmployee(
            $employee->getKey(),
            $d['shift_id'],
            $d['effective_date'],
            $d['end_date'] ?? null,
        );
        return response()->json(['data' => ['message' => 'Shift assigned.']]);
    }

    public function currentEmployeeShift(Employee $employee): ShiftResource|JsonResponse
    {
        $shift = $this->assignments->current($employee, Carbon::today());
        if (! $shift) {
            return response()->json(['data' => null]);
        }
        return new ShiftResource($shift);
    }
}
