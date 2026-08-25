<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeSkillService
{
    public function __construct(private readonly TrainingEvidenceService $evidence) {}

    /**
     * Assign a skill to an employee. A revoked row is restored and updated so
     * the permanent employee/skill uniqueness key remains valid.
     *
     * @param array<string, mixed> $data
     */
    public function assign(
        Employee $employee,
        array $data,
        ?UploadedFile $certificate = null,
        ?User $actor = null,
    ): EmployeeSkill {
        EmployeeCompetenceScope::employee($employee, $actor);
        $newPath = null;
        $oldPath = null;

        try {
            $result = DB::transaction(function () use ($employee, $data, $certificate, $actor, &$newPath, &$oldPath): EmployeeSkill {
                $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
                EmployeeCompetenceScope::employee($lockedEmployee, $actor);

                $skill = $this->resolveSkill($data['skill_id']);
                $record = EmployeeSkill::withTrashed()
                    ->where('employee_id', $lockedEmployee->id)
                    ->where('skill_id', $skill->id)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && ! $record->trashed()) {
                    throw ValidationException::withMessages([
                        'skill_id' => ['Employee already has this skill assigned.'],
                    ]);
                }

                $certifierId = $this->resolveCertifier($data['certified_by'] ?? null);
                $attributes = [
                    'employee_id' => $lockedEmployee->id,
                    'skill_id' => $skill->id,
                    'proficiency_level' => $data['proficiency_level'],
                    'acquired_date' => $data['acquired_date'],
                    'expires_at' => $data['expires_at'] ?? null,
                    'certified_by' => $certifierId,
                    'notes' => $data['notes'] ?? null,
                ];

                if ($record === null) {
                    $record = EmployeeSkill::create($attributes);
                } else {
                    $record->restore();
                    $record->fill($attributes)->save();
                }

                $evidence = $certificate
                    ? $this->evidence->store($certificate, 'employee-skill-certificates/'.$record->id)
                    : null;
                $newPath = $evidence['path'] ?? null;
                $oldPath = $record->certification_document_path;

                if ($evidence !== null) {
                    $record->forceFill([
                        'certification_document_path' => $evidence['path'],
                        'certification_document_name' => $evidence['original_name'],
                        'certification_document_mime_type' => $evidence['mime_type'],
                        'certification_document_size' => $evidence['size'],
                        'certification_document_uploaded_by' => $actor?->id,
                        'certification_document_uploaded_at' => now(),
                    ])->save();
                }

                if ($evidence !== null && $oldPath !== null && $oldPath !== $evidence['path']) {
                    DB::afterCommit(fn () => $this->evidence->delete($oldPath));
                }

                return $record->fresh(['employee', 'skill', 'certifier']);
            });

            $newPath = null;
            return $result;
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $this->evidence->delete($newPath);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(
        EmployeeSkill $employeeSkill,
        array $data,
        ?UploadedFile $certificate = null,
        ?User $actor = null,
    ): EmployeeSkill {
        EmployeeCompetenceScope::skill($employeeSkill, $actor);
        $newPath = null;
        $oldPath = null;

        try {
            $result = DB::transaction(function () use ($employeeSkill, $data, $certificate, $actor, &$newPath, &$oldPath): EmployeeSkill {
                $locked = EmployeeSkill::query()
                    ->with(['employee', 'skill', 'certifier'])
                    ->lockForUpdate()
                    ->findOrFail($employeeSkill->getKey());
                EmployeeCompetenceScope::skill($locked, $actor);

                if (isset($data['certified_by'])) {
                    $data['certified_by'] = $this->resolveCertifier($data['certified_by']);
                }
                unset($data['certificate']);
                $locked->fill($data)->save();

                $evidence = $certificate
                    ? $this->evidence->store($certificate, 'employee-skill-certificates/'.$locked->id)
                    : null;
                $newPath = $evidence['path'] ?? null;
                $oldPath = $locked->certification_document_path;

                if ($evidence !== null) {
                    $locked->forceFill([
                        'certification_document_path' => $evidence['path'],
                        'certification_document_name' => $evidence['original_name'],
                        'certification_document_mime_type' => $evidence['mime_type'],
                        'certification_document_size' => $evidence['size'],
                        'certification_document_uploaded_by' => $actor?->id,
                        'certification_document_uploaded_at' => now(),
                    ])->save();
                    if ($oldPath !== null && $oldPath !== $evidence['path']) {
                        DB::afterCommit(fn () => $this->evidence->delete($oldPath));
                    }
                }

                return $locked->fresh(['employee', 'skill', 'certifier']);
            });

            $newPath = null;
            return $result;
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $this->evidence->delete($newPath);
            }
            throw $e;
        }
    }

    public function revoke(EmployeeSkill $employeeSkill, ?User $actor = null): void
    {
        EmployeeCompetenceScope::skill($employeeSkill, $actor);

        DB::transaction(function () use ($employeeSkill, $actor): void {
            $locked = EmployeeSkill::query()
                ->with('employee')
                ->lockForUpdate()
                ->findOrFail($employeeSkill->getKey());
            EmployeeCompetenceScope::skill($locked, $actor);
            $locked->delete();
        });
    }

    public function restore(EmployeeSkill $employeeSkill, ?User $actor = null): void
    {
        EmployeeCompetenceScope::skill($employeeSkill, $actor);

        DB::transaction(function () use ($employeeSkill, $actor): void {
            $locked = EmployeeSkill::withTrashed()
                ->with('employee')
                ->lockForUpdate()
                ->findOrFail($employeeSkill->getKey());
            EmployeeCompetenceScope::skill($locked, $actor);
            if (! $locked->trashed()) {
                return;
            }
            $locked->restore();
        });
    }

    /**
     * Skills matrix — employees x skills grid with bounded dimensions.
     *
     * @return array<string, mixed>
     */
    public function matrix(
        ?int $departmentId = null,
        ?int $skillId = null,
        ?User $actor = null,
        int $employeeLimit = 100,
        int $skillLimit = 100,
    ): array {
        $employeeQuery = Employee::query()
            ->with('department')
            ->where('status', EmployeeStatus::Active->value);
        EmployeeCompetenceScope::employees($employeeQuery, $actor);
        if ($departmentId !== null) {
            $employeeQuery->where('department_id', $departmentId);
        }

        $totalEmployees = (clone $employeeQuery)->count();
        $employees = $employeeQuery->orderBy('last_name')->limit($employeeLimit)->get();

        $skillQuery = Skill::query()
            ->where('is_active', true)
            ->when($skillId !== null, fn ($q) => $q->whereKey($skillId))
            ->orderBy('category')
            ->orderBy('name');
        $totalSkills = (clone $skillQuery)->count();
        $skills = $skillQuery->limit($skillLimit)->get();

        $skillRows = EmployeeSkill::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('skill_id', $skills->modelKeys())
            ->with('skill')
            ->get()
            ->filter(fn (EmployeeSkill $row): bool => $row->skill?->is_active === true)
            ->groupBy('employee_id');

        $today = Carbon::now()->startOfDay();
        $rows = [];
        foreach ($employees as $employee) {
            $assigned = $skillRows->get($employee->id, collect())->keyBy('skill_id');
            $cells = [];
            foreach ($skills as $skill) {
                /** @var EmployeeSkill|null $employeeSkill */
                $employeeSkill = $assigned->get($skill->id);
                $daysUntilExpiry = null;
                $isExpired = false;
                if ($employeeSkill?->expires_at) {
                    $daysUntilExpiry = $today->diffInDays($employeeSkill->expires_at, false);
                    $isExpired = $employeeSkill->expires_at->lt($today);
                }

                $cells[] = [
                    'skill' => [
                        'id' => $skill->hash_id,
                        'name' => $skill->name,
                        'category' => $skill->category,
                    ],
                    'proficiency_level' => $employeeSkill?->proficiency_level?->value,
                    'acquired_date' => $employeeSkill?->acquired_date?->toDateString(),
                    'expires_at' => $employeeSkill?->expires_at?->toDateString(),
                    'days_until_expiry' => $daysUntilExpiry,
                    'is_expired' => $isExpired,
                ];
            }

            $rows[] = [
                'employee' => [
                    'id' => $employee->hash_id,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department ? [
                        'id' => $employee->department->hash_id,
                        'name' => $employee->department->name,
                    ] : null,
                ],
                'skills' => $cells,
            ];
        }

        return [
            'skills' => $skills->map(fn (Skill $skill): array => [
                'id' => $skill->hash_id,
                'name' => $skill->name,
                'category' => $skill->category,
            ])->values()->all(),
            'rows' => $rows,
            'meta' => [
                'total_employees' => $totalEmployees,
                'total_skills' => $totalSkills,
                'employee_limit' => $employeeLimit,
                'skill_limit' => $skillLimit,
                'truncated' => $totalEmployees > $employeeLimit || $totalSkills > $skillLimit,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function gapAnalysis(int $skillId, ?User $actor = null, int $limit = 100): array
    {
        $today = Carbon::now()->startOfDay()->toDateString();
        $employeeIdsWithCurrentSkill = EmployeeSkill::query()
            ->where('skill_id', $skillId)
            ->where(function ($query) use ($today): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
            })
            ->pluck('employee_id');

        $query = Employee::query()
            ->with('department')
            ->where('status', EmployeeStatus::Active->value)
            ->whereNotIn('id', $employeeIdsWithCurrentSkill);
        EmployeeCompetenceScope::employees($query, $actor);

        return $query->orderBy('last_name')->limit($limit)->get()->map(fn (Employee $employee): array => [
            'employee' => [
                'id' => $employee->hash_id,
                'full_name' => $employee->full_name,
                'department' => $employee->department ? [
                    'id' => $employee->department->hash_id,
                    'name' => $employee->department->name,
                ] : null,
            ],
        ])->all();
    }

    private function resolveSkill(mixed $hashId): Skill
    {
        $id = HashIdFilter::decode($hashId, Skill::class);
        $skill = $id !== null ? Skill::query()->find($id) : null;
        if (! $skill || ! $skill->is_active) {
            throw ValidationException::withMessages([
                'skill_id' => ['Skill not found or is inactive.'],
            ]);
        }

        return $skill;
    }

    private function resolveCertifier(mixed $hashId): ?int
    {
        if (empty($hashId)) {
            return null;
        }

        return HashIdFilter::decode($hashId, User::class);
    }
}
