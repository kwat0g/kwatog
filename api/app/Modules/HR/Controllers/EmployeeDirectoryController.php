<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series F — Task F5. Employee directory + org chart.
 *
 * Slim projection — does NOT expose any sensitive fields (SSS, TIN,
 * bank, salary). Reachable by all internal roles via
 * `hr.directory.view` so factory workers can look up coworkers.
 */
class EmployeeDirectoryController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'        => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = Employee::query()
            ->with(['department:id,name,code', 'position:id,title,department_id'])
            ->where('status', 'active');

        if ($request->filled('search')) {
            $s = (string) $request->query('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('first_name', 'ilike', "%{$s}%")
                   ->orWhere('last_name', 'ilike', "%{$s}%")
                   ->orWhere('employee_no', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('department_id')) {
            $decoded = app('hashids')->decode((string) $request->query('department_id'));
            if (! empty($decoded)) {
                $q->where('department_id', (int) $decoded[0]);
            }
        }

        $perPage = min(200, (int) $request->query('per_page', 100));
        $employees = $q->orderBy('last_name')->orderBy('first_name')->paginate($perPage);

        return response()->json([
            'data' => $employees->getCollection()->map(fn (Employee $e) => $this->card($e))->values(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/hr/directory/org-chart
     * Returns a tree grouped by department.
     */
    public function orgChart(): JsonResponse
    {
        $employees = Employee::query()
            ->with(['department:id,name,code', 'position:id,title'])
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get();

        $byDept = [];
        foreach ($employees as $e) {
            $deptId = $e->department?->id ?? 0;
            if (! isset($byDept[$deptId])) {
                $byDept[$deptId] = [
                    'department' => $e->department
                        ? ['id' => app('hashids')->encode($e->department->id), 'name' => $e->department->name, 'code' => $e->department->code]
                        : null,
                    'employees' => [],
                ];
            }
            $byDept[$deptId]['employees'][] = $this->card($e);
        }

        return response()->json([
            'data' => array_values($byDept),
        ]);
    }

    private function card(Employee $e): array
    {
        return [
            'id'             => $e->hash_id,
            'employee_no'    => $e->employee_no,
            'full_name'      => $e->full_name,
            'first_name'     => $e->first_name,
            'last_name'      => $e->last_name,
            'photo_path'     => $e->photo_path,
            'mobile_number'  => $e->mobile_number ? $this->maskMobile($e->mobile_number) : null,
            'email'          => $e->email,
            'status'         => $e->status?->value,
            'position'       => $e->position
                ? ['id' => app('hashids')->encode($e->position->id), 'title' => $e->position->title]
                : null,
            'department'     => $e->department
                ? ['id' => app('hashids')->encode($e->department->id), 'name' => $e->department->name, 'code' => $e->department->code]
                : null,
        ];
    }

    private function maskMobile(string $m): string
    {
        $clean = preg_replace('/\s+/', '', $m);
        $len = mb_strlen($clean);
        if ($len <= 4) return $clean;
        return str_repeat('•', $len - 4).mb_substr($clean, -4);
    }
}
