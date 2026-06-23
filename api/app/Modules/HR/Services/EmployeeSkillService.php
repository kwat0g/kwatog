<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Enums\EmployeeSkillLevel;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeSkillService
{
    /**
     * Assign a skill to an employee.
     *
     * @param array<string, mixed> $data
     */
    public function assign(Employee $employee, array $data): EmployeeSkill
    {
        return DB::transaction(function () use ($employee, $data) {
            $skill = $this->resolveSkill($data['skill_id']);
            $this->assertNoDuplicate($employee, $skill);

            $certifierId = null;
            if (!empty($data['certified_by'])) {
                $certifierId = app('hashids')->decode($data['certified_by'])[0] ?? null;
            }

            $rec = EmployeeSkill::create([
                'employee_id'               => $employee->id,
                'skill_id'                  => $skill->id,
                'proficiency_level'         => $data['proficiency_level'],
                'acquired_date'             => $data['acquired_date'],
                'expires_at'                => $data['expires_at'] ?? null,
                'certified_by'              => $certifierId,
                'certification_document_path' => $data['certification_document_path'] ?? null,
                'notes'                     => $data['notes'] ?? null,
            ]);

            return $rec->fresh(['employee', 'skill', 'certifier']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(EmployeeSkill $employeeSkill, array $data): EmployeeSkill
    {
        return DB::transaction(function () use ($employeeSkill, $data) {
            if (isset($data['certified_by'])) {
                $data['certified_by'] = app('hashids')->decode($data['certified_by'])[0] ?? null;
            }

            $employeeSkill->fill($data)->save();
            return $employeeSkill->fresh(['employee', 'skill', 'certifier']);
        });
    }

    public function revoke(EmployeeSkill $employeeSkill): void
    {
        DB::transaction(fn() => $employeeSkill->delete());
    }

    /**
     * Skills matrix — employees x skills grid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrix(?int $departmentId = null, ?int $skillId = null): array
    {
        $query = Employee::query()
            ->with(['skills.skill'])
            ->where('status', \App\Modules\HR\Enums\EmployeeStatus::Active->value);

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $employees = $query->orderBy('last_name')->get();

        $rows = [];
        foreach ($employees as $employee) {
            $skillRows = [];
            foreach ($employee->skills as $es) {
                if ($skillId && $es->skill_id !== $skillId) {
                    continue;
                }

                $daysUntilExpiry = null;
                $isExpired = false;
                if ($es->expires_at) {
                    $daysUntilExpiry = Carbon::now()->startOfDay()->diffInDays(
                        $es->expires_at,
                        false // absolute = false => negative if in the past
                    );
                    $isExpired = $es->expires_at->isPast();
                }

                $skillRows[] = [
                    'skill'            => $es->skill ? [
                        'id'       => $es->skill->hash_id,
                        'name'     => $es->skill->name,
                        'category' => $es->skill->category,
                    ] : null,
                    'proficiency_level' => $es->proficiency_level?->value,
                    'acquired_date'     => $es->acquired_date?->toDateString(),
                    'expires_at'        => $es->expires_at?->toDateString(),
                    'days_until_expiry' => $daysUntilExpiry,
                    'is_expired'        => $isExpired,
                ];
            }

            $rows[] = [
                'employee' => [
                    'id'        => $employee->hash_id,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department ? [
                        'id'   => $employee->department->hash_id,
                        'name' => $employee->department->name,
                    ] : null,
                ],
                'skills' => $skillRows,
            ];
        }

        return $rows;
    }

    /**
     * Gap analysis — employees who do NOT have the given skill.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gapAnalysis(int $skillId): array
    {
        $employeeIdsWithSkill = EmployeeSkill::query()
            ->where('skill_id', $skillId)
            ->pluck('employee_id');

        $employees = Employee::query()
            ->with('department')
            ->where('status', \App\Modules\HR\Enums\EmployeeStatus::Active->value)
            ->whereNotIn('id', $employeeIdsWithSkill)
            ->orderBy('last_name')
            ->get();

        return $employees->map(fn(Employee $e) => [
            'employee' => [
                'id'         => $e->hash_id,
                'full_name'  => $e->full_name,
                'department' => $e->department ? [
                    'id'   => $e->department->hash_id,
                    'name' => $e->department->name,
                ] : null,
            ],
        ])->all();
    }

    private function resolveSkill(string $hashId): Skill
    {
        $id = app('hashids')->decode($hashId)[0] ?? 0;
        $skill = Skill::find($id);
        if (!$skill) {
            throw ValidationException::withMessages([
                'skill_id' => ['Skill not found.'],
            ]);
        }
        return $skill;
    }

    private function assertNoDuplicate(Employee $employee, Skill $skill): void
    {
        $exists = EmployeeSkill::query()
            ->where('employee_id', $employee->id)
            ->where('skill_id', $skill->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'skill_id' => ['Employee already has this skill assigned.'],
            ]);
        }
    }
}
