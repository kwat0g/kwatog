<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\TrainingMatrixStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrainingMatrixController
{
    private const MAX_EMPLOYEES = 100;
    private const MAX_SKILLS = 100;

    public function index(Request $request): JsonResponse
    {
        $departmentId = $this->decodeFilter($request, 'department_id', Department::class);
        $skillId = $this->decodeFilter($request, 'skill_id', Skill::class);
        $employeeLimit = min(max((int) $request->query('employee_limit', self::MAX_EMPLOYEES), 1), self::MAX_EMPLOYEES);
        $skillLimit = min(max((int) $request->query('skill_limit', self::MAX_SKILLS), 1), self::MAX_SKILLS);

        $employeeQuery = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->with('department');
        EmployeeCompetenceScope::employees($employeeQuery, $request->user());

        $totalEmployees = (clone $employeeQuery)->count();
        $employees = $employeeQuery->orderBy('last_name')->limit($employeeLimit)->get();

        $skillQuery = Skill::query()
            ->where('is_active', true)
            ->when($skillId !== null, fn ($query) => $query->whereKey($skillId))
            ->orderBy('category')
            ->orderBy('name');
        $totalSkills = (clone $skillQuery)->count();
        $skills = $skillQuery->limit($skillLimit)->get();

        $employeeSkills = EmployeeSkill::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('skill_id', $skills->modelKeys())
            ->with('skill')
            ->get()
            ->filter(fn (EmployeeSkill $row): bool => $row->skill?->is_active === true)
            ->keyBy(fn (EmployeeSkill $row): string => "{$row->employee_id}:{$row->skill_id}");

        $today = Carbon::now()->startOfDay();
        $totalGaps = 0;
        $totalExpired = 0;
        $totalTrained = 0;

        $rows = $employees->map(function (Employee $employee) use ($skills, $employeeSkills, $today, &$totalGaps, &$totalExpired, &$totalTrained): array {
            $cells = $skills->map(function (Skill $skill) use ($employee, $employeeSkills, $today, &$totalGaps, &$totalExpired, &$totalTrained): array {
                $employeeSkill = $employeeSkills->get("{$employee->id}:{$skill->id}");
                $status = TrainingMatrixStatus::Gap;
                $level = null;
                $expiryDate = null;

                if ($employeeSkill instanceof EmployeeSkill) {
                    $level = $employeeSkill->proficiency_level?->value;
                    $expiryDate = $employeeSkill->expires_at?->toDateString();
                    if ($employeeSkill->expires_at && $employeeSkill->expires_at->lt($today)) {
                        $status = TrainingMatrixStatus::Expired;
                        $totalExpired++;
                    } else {
                        $status = TrainingMatrixStatus::Trained;
                        $totalTrained++;
                    }
                } else {
                    $totalGaps++;
                }

                return [
                    'skill_id' => $skill->hash_id,
                    'status' => $status->value,
                    'level' => $level,
                    'expiry_date' => $expiryDate,
                ];
            })->values()->all();

            return [
                'employee_id' => $employee->hash_id,
                'employee_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'cells' => $cells,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'skills' => $skills->map(fn (Skill $skill): array => [
                    'id' => $skill->hash_id,
                    'name' => $skill->name,
                    'category' => $skill->category,
                ])->values()->all(),
                'rows' => $rows,
                'summary' => [
                    'total_employees' => $employees->count(),
                    'total_skills' => $skills->count(),
                    'trained_count' => $totalTrained,
                    'gap_count' => $totalGaps,
                    'expired_count' => $totalExpired,
                ],
                'status_options' => array_map(
                    static fn (TrainingMatrixStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    TrainingMatrixStatus::cases(),
                ),
                'meta' => [
                    'total_employees' => $totalEmployees,
                    'total_skills' => $totalSkills,
                    'employee_limit' => $employeeLimit,
                    'skill_limit' => $skillLimit,
                    'truncated' => $totalEmployees > $employeeLimit || $totalSkills > $skillLimit,
                ],
            ],
        ]);
    }

    private function decodeFilter(Request $request, string $field, string $model): ?int
    {
        if (! $request->filled($field)) {
            return null;
        }

        $decoded = HashIdFilter::decode($request->input($field), $model);
        if ($decoded === null) {
            throw ValidationException::withMessages([
                $field => ['The selected filter is invalid.'],
            ]);
        }

        return $decoded;
    }
}
