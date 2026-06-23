<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Requests\AssignEmployeeSkillRequest;
use App\Modules\HR\Requests\UpdateEmployeeSkillRequest;
use App\Modules\HR\Resources\EmployeeSkillResource;
use App\Modules\HR\Services\EmployeeSkillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeSkillController
{
    public function __construct(private readonly EmployeeSkillService $service) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $rows = EmployeeSkill::query()
            ->with(['employee', 'skill', 'certifier'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25));

        return EmployeeSkillResource::collection($rows);
    }

    public function store(AssignEmployeeSkillRequest $request, Employee $employee): JsonResponse
    {
        $rec = $this->service->assign($employee, $request->validated());
        return (new EmployeeSkillResource($rec))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeSkillRequest $request, EmployeeSkill $employeeSkill): EmployeeSkillResource
    {
        return new EmployeeSkillResource($this->service->update($employeeSkill, $request->validated()));
    }

    public function destroy(EmployeeSkill $employeeSkill): JsonResponse
    {
        $this->service->revoke($employeeSkill);
        return response()->json(null, 204);
    }

    public function matrix(Request $request): JsonResponse
    {
        $data = $this->service->matrix(
            departmentId: $request->integer('department_id') ?: null,
            skillId: $request->has('skill_id')
                ? (app('hashids')->decode($request->string('skill_id'))[0] ?? null)
                : null,
        );

        return response()->json(['data' => $data]);
    }

    public function gapAnalysis(Request $request): JsonResponse
    {
        $skillId = app('hashids')->decode($request->string('skill_id'))[0] ?? 0;
        $data = $this->service->gapAnalysis($skillId);

        return response()->json(['data' => $data]);
    }
}
