<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Skill;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingMatrixController
{
    /**
     * Training matrix heatmap — employees x skills cross-tabulated grid.
     *
     * Each cell shows: trained | expired | gap based on EmployeeSkill records.
     * Filterable by department_id (hash_id).
     */
    public function index(Request $request): JsonResponse
    {
        $deptId = null;
        if ($request->filled('department_id')) {
            $raw = $request->input('department_id');
            if (ctype_digit((string) $raw)) {
                $deptId = (int) $raw;
            } else {
                $decoded = app('hashids')->decode((string) $raw);
                $deptId = $decoded[0] ?? null;
            }
        }

        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->with(['skills.skill', 'department'])
            ->orderBy('last_name')
            ->get();

        $skills = Skill::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $now = Carbon::now()->startOfDay();

        $totalGaps = 0;
        $totalExpired = 0;
        $totalTrained = 0;

        $matrix = $employees->map(function ($emp) use ($skills, $now, &$totalGaps, &$totalExpired, &$totalTrained) {
            $cells = $skills->map(function ($skill) use ($emp, $now, &$totalGaps, &$totalExpired, &$totalTrained) {
                $empSkill = $emp->skills->firstWhere('skill_id', $skill->id);

                $status = 'gap';
                $level = null;
                $expiryDate = null;

                if ($empSkill) {
                    $level = $empSkill->proficiency_level?->value;
                    $expiryDate = $empSkill->expires_at?->toDateString();

                    if ($empSkill->expires_at && $empSkill->expires_at->lt($now)) {
                        $status = 'expired';
                        $totalExpired++;
                    } else {
                        $status = 'trained';
                        $totalTrained++;
                    }
                } else {
                    $totalGaps++;
                }

                return [
                    'skill_id'    => $skill->hash_id,
                    'status'      => $status,
                    'level'       => $level,
                    'expiry_date' => $expiryDate,
                ];
            });

            return [
                'employee_id'   => $emp->hash_id,
                'employee_name' => $emp->full_name,
                'department'    => $emp->department?->name,
                'cells'         => $cells->values()->all(),
            ];
        });

        return response()->json([
            'data' => [
                'skills' => $skills->map(fn ($s) => [
                    'id'       => $s->hash_id,
                    'name'     => $s->name,
                    'category' => $s->category,
                ])->values()->all(),
                'rows'    => $matrix->values()->all(),
                'summary' => [
                    'total_employees' => $employees->count(),
                    'total_skills'    => $skills->count(),
                    'trained_count'   => $totalTrained,
                    'gap_count'       => $totalGaps,
                    'expired_count'   => $totalExpired,
                ],
            ],
        ]);
    }
}
