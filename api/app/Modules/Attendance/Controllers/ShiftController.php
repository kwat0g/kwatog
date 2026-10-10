<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Requests\AssignEmployeeShiftRequest;
use App\Modules\Attendance\Requests\BulkAssignShiftRequest;
use App\Modules\Attendance\Requests\ConfigureWorkingDaysRequest;
use App\Modules\Attendance\Requests\StoreShiftRequest;
use App\Modules\Attendance\Requests\UpdateShiftRequest;
use App\Modules\Attendance\Resources\EmployeeShiftAssignmentResource;
use App\Modules\Attendance\Resources\ShiftResource;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Attendance\Services\ShiftService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $this->shifts->restore($shift);

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
            $d['working_days'],
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
            $d['working_days'],
        );

        return response()->json(['data' => ['message' => 'Shift assigned.']]);
    }

    public function assignmentIndex(Request $request): AnonymousResourceCollection
    {
        $assignments = EmployeeShiftAssignment::query()->with(['employee', 'shift'])
            ->when($request->query('department_id'), function ($query, $departmentId) {
                $query->whereHas('employee', fn ($employees) => $employees->where('department_id', Department::decodeHash($departmentId)));
            })
            ->orderByDesc('effective_date')->orderBy('id')->paginate(50);

        return EmployeeShiftAssignmentResource::collection($assignments);
    }

    public function configureWorkingDays(ConfigureWorkingDaysRequest $request, EmployeeShiftAssignment $assignment): EmployeeShiftAssignmentResource
    {
        return new EmployeeShiftAssignmentResource(
            $this->assignments->configureWorkingDays($assignment, $request->validated('working_days')),
        );
    }

    public function currentEmployeeShift(Employee $employee): ShiftResource|JsonResponse
    {
        $assignment = $this->assignments->assignmentFor($employee, Carbon::today());
        $shift = $assignment?->shift()->first();
        if (! $shift) {
            return response()->json(['data' => null, 'assignment' => null]);
        }

        // The assignment rides along so the employee edit form can prefill the
        // schedule and update it in place; `data` is unchanged for existing callers.
        return (new ShiftResource($shift))->additional(['assignment' => [
            'id' => $assignment->hash_id,
            'working_days' => $assignment->working_days,
            'effective_date' => $assignment->effective_date->toDateString(),
        ]]);
    }
}
