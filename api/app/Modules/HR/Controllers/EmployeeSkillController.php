<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Requests\AssignEmployeeSkillRequest;
use App\Modules\HR\Requests\UpdateEmployeeSkillRequest;
use App\Modules\HR\Resources\EmployeeSkillResource;
use App\Modules\HR\Services\EmployeeSkillService;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeSkillController
{
    public function __construct(
        private readonly EmployeeSkillService $service,
        private readonly \App\Modules\HR\Services\TrainingEvidenceService $evidence,
    ) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $employee = EmployeeCompetenceScope::employee($employee, $request->user());
        $rows = EmployeeSkill::query()
            ->with(['employee', 'skill', 'certifier'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return EmployeeSkillResource::collection($rows);
    }

    public function store(AssignEmployeeSkillRequest $request, Employee $employee): JsonResponse
    {
        $employee = EmployeeCompetenceScope::employee($employee, $request->user());
        $rec = $this->service->assign($employee, $request->validated(), $request->file('certificate'), $request->user());
        return (new EmployeeSkillResource($rec))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeSkillRequest $request, EmployeeSkill $employeeSkill): EmployeeSkillResource
    {
        return new EmployeeSkillResource($this->service->update(
            $employeeSkill,
            $request->validated(),
            $request->file('certificate'),
            $request->user(),
        ));
    }

    public function destroy(Request $request, EmployeeSkill $employeeSkill): JsonResponse
    {
        $this->service->revoke($employeeSkill, $request->user());
        return response()->json(null, 204);
    }

    public function restore(Request $request, EmployeeSkill $employeeSkill): JsonResponse
    {
        $this->service->restore($employeeSkill, $request->user());
        return response()->json(['message' => 'Employee skill restored.']);
    }

    public function matrix(Request $request): JsonResponse
    {
        $departmentId = $request->filled('department_id')
            ? HashIdFilter::decode($request->input('department_id'), Department::class)
            : null;
        $skillId = $request->filled('skill_id')
            ? HashIdFilter::decode($request->input('skill_id'), Skill::class)
            : null;
        if ($request->filled('department_id') && $departmentId === null) {
            throw ValidationException::withMessages(['department_id' => ['The selected department is invalid.']]);
        }
        if ($request->filled('skill_id') && $skillId === null) {
            throw ValidationException::withMessages(['skill_id' => ['The selected skill is invalid.']]);
        }

        $data = $this->service->matrix(
            departmentId: $departmentId,
            skillId: $skillId,
            actor: $request->user(),
            employeeLimit: min(max((int) $request->query('employee_limit', 100), 1), 100),
            skillLimit: min(max((int) $request->query('skill_limit', 100), 1), 100),
        );

        return response()->json(['data' => $data]);
    }

    public function gapAnalysis(Request $request): JsonResponse
    {
        $skillId = HashIdFilter::decode($request->input('skill_id'), Skill::class);
        abort_if($skillId === null, 404);
        $data = $this->service->gapAnalysis(
            $skillId,
            $request->user(),
            min(max((int) $request->query('limit', 100), 1), 100),
        );

        return response()->json(['data' => $data]);
    }

    public function download(Request $request, EmployeeSkill $employeeSkill): StreamedResponse
    {
        $employeeSkill = EmployeeCompetenceScope::skill($employeeSkill, $request->user());
        abort_unless($employeeSkill->certification_document_path, 404);

        return $this->evidence->download(
            $employeeSkill->certification_document_path,
            $employeeSkill->certification_document_name ?: 'skill-certificate',
            $employeeSkill->certification_document_mime_type,
        );
    }
}
