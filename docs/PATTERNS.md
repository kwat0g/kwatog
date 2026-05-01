# OGAMI ERP — Code Patterns (Anti-Sloppy Bible)

> **MANDATORY:** Before writing ANY page, component, controller, or service — find the matching pattern below and COPY it. Change only the entity names, fields, and business logic. Do NOT improvise structure, layout, state handling, or error patterns.
>
> Every pattern here is COMPLETE and WORKING. Missing states (loading, empty, error, disabled) are the #1 cause of sloppy UI. Every pattern below includes ALL states.

---

## TABLE OF CONTENTS

1. [Backend: Migration](#1-migration-pattern)
2. [Backend: Model](#2-model-pattern)
3. [Backend: Service](#3-service-pattern)
4. [Backend: Form Request](#4-form-request-pattern)
5. [Backend: API Resource](#5-api-resource-pattern)
6. [Backend: Controller](#6-controller-pattern)
7. [Backend: Routes](#7-route-pattern)
8. [Frontend: API Layer](#8-api-layer-pattern)
9. [Frontend: Types](#9-typescript-types-pattern)
10. [Frontend: List Page](#10-list-page-pattern)
11. [Frontend: Detail Page](#11-detail-page-pattern)
12. [Frontend: Create Form](#12-create-form-pattern)
13. [Frontend: Edit Form](#13-edit-form-pattern)
14. [Frontend: Delete Confirmation](#14-delete-confirmation-pattern)
15. [Frontend: Approval Actions](#15-approval-actions-pattern)
16. [Frontend: File Upload](#16-file-upload-pattern)
17. [Frontend: Filter Bar](#17-filter-bar-pattern)
18. [Frontend: Error Boundary](#18-error-boundary-pattern)
19. [Frontend: Page States](#19-page-states-pattern)
20. [Frontend: Toast Notifications](#20-toast-notifications-pattern)
21. [Frontend: Route Setup](#21-route-setup-pattern)

---

## 1. MIGRATION PATTERN

```php
<?php
// database/migrations/0016_create_employees_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // String fields
            $table->string('employee_no', 20)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);

            // Foreign keys — ALWAYS with constrained()
            $table->foreignId('department_id')->constrained('departments');
            $table->foreignId('position_id')->constrained('positions');

            // Nullable foreign key
            $table->foreignId('user_id')->nullable()->constrained('users');

            // Enum-backed string
            $table->string('status', 20)->default('active');
            $table->string('pay_type', 10); // 'monthly' or 'daily'

            // Money — ALWAYS decimal(15,2), NEVER float
            $table->decimal('basic_monthly_salary', 15, 2)->nullable();
            $table->decimal('daily_rate', 15, 2)->nullable();

            // Dates
            $table->date('date_hired');
            $table->date('date_regularized')->nullable();

            // Encrypted sensitive fields (stored as TEXT because encryption expands data)
            $table->text('sss_no')->nullable();
            $table->text('tin')->nullable();
            $table->text('bank_account_no')->nullable();

            // JSON for flexible data
            $table->json('custom_fields')->nullable();

            // Boolean
            $table->boolean('is_active')->default(true);

            // Timestamps + soft delete
            $table->timestamps();
            $table->softDeletes();

            // Indexes on frequently filtered columns
            $table->index('status');
            $table->index('department_id');
            $table->index('date_hired');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

---

## 2. MODEL PATTERN

```php
<?php
// app/Modules/HR/Models/Employee.php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Common\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'department_id',
        'position_id',
        'status',
        'pay_type',
        'basic_monthly_salary',
        'daily_rate',
        'date_hired',
        'date_regularized',
        'sss_no',
        'tin',
        'bank_account_no',
    ];

    protected $casts = [
        // Money — decimal cast
        'basic_monthly_salary' => 'decimal:2',
        'daily_rate' => 'decimal:2',

        // Dates
        'date_hired' => 'date',
        'date_regularized' => 'date',

        // Encrypted sensitive fields
        'sss_no' => 'encrypted',
        'tin' => 'encrypted',
        'bank_account_no' => 'encrypted',

        // JSON
        'custom_fields' => 'array',

        // Booleans
        'is_active' => 'boolean',
    ];

    // ─── Accessors ────────────────────────────────
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1) . '.' : null,
            $this->last_name,
            $this->suffix,
        ]);
        return implode(' ', $parts);
    }

    // ─── Relationships ────────────────────────────
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(\App\Modules\Attendance\Models\Attendance::class);
    }

    // ─── Scopes ───────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}
```

---

## 3. SERVICE PATTERN

```php
<?php
// app/Modules/HR/Services/EmployeeService.php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EmployeeService
{
    public function __construct(
        private DocumentSequenceService $sequences,
    ) {}

    /**
     * List with filters, search, sort, pagination.
     * ALWAYS eager load relationships to prevent N+1.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Employee::query()
            ->with(['department', 'position']); // ALWAYS eager load

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('employee_no', 'ilike', "%{$search}%");
            });
        }

        // Filters — each is optional
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['pay_type'])) {
            $query->where('pay_type', $filters['pay_type']);
        }

        // Sort — default by employee_no desc
        $sortField = $filters['sort'] ?? 'employee_no';
        $sortDir = $filters['direction'] ?? 'desc';
        $allowedSorts = ['employee_no', 'first_name', 'last_name', 'date_hired', 'status'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir);
        }

        // Paginate — ALWAYS paginate, never return unbounded results
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    /**
     * Create — wrapped in DB::transaction.
     * Generate document number atomically.
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $data['employee_no'] = $this->sequences->generate('employee');

            $employee = Employee::create($data);

            // Log employment history
            $employee->employmentHistory()->create([
                'change_type' => 'hired',
                'to_value' => [
                    'department' => $employee->department->name,
                    'position' => $employee->position->title,
                    'salary' => $employee->basic_monthly_salary ?? $employee->daily_rate,
                ],
                'effective_date' => $employee->date_hired,
            ]);

            return $employee->load(['department', 'position']);
        });
    }

    /**
     * Update — wrapped in DB::transaction.
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee->update($data);
            return $employee->fresh(['department', 'position']);
        });
    }

    /**
     * Show — eager load everything needed for detail page.
     */
    public function show(Employee $employee): Employee
    {
        return $employee->load([
            'department',
            'position',
            'employmentHistory',
        ]);
    }

    /**
     * Delete — soft delete.
     */
    public function delete(Employee $employee): void
    {
        $employee->delete();
    }
}
```

---

## 4. FORM REQUEST PATTERN

```php
<?php
// app/Modules/HR/Requests/StoreEmployeeRequest.php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Authorization — ALWAYS check permission here.
     * This is the server-side guard. Frontend guards are UX only.
     */
    public function authorize(): bool
    {
        return $this->user()->can('hr.employees.create');
    }

    /**
     * Validation rules — EXHAUSTIVE. Every field validated.
     * Never trust client-side validation alone.
     */
    public function rules(): array
    {
        return [
            // Required strings with max length
            'first_name'     => ['required', 'string', 'max:100'],
            'last_name'      => ['required', 'string', 'max:100'],
            'middle_name'    => ['nullable', 'string', 'max:100'],
            'suffix'         => ['nullable', 'string', 'max:20'],

            // Required foreign keys — must exist in referenced table
            'department_id'  => ['required', 'integer', 'exists:departments,id'],
            'position_id'    => ['required', 'integer', 'exists:positions,id'],

            // Enum validation — only allow specific values
            'employment_type' => ['required', Rule::in(['regular', 'probationary', 'contractual', 'project_based'])],
            'pay_type'        => ['required', Rule::in(['monthly', 'daily'])],

            // Conditional: monthly salary required if pay_type is monthly
            'basic_monthly_salary' => ['required_if:pay_type,monthly', 'nullable', 'decimal:0,2', 'min:0'],
            'daily_rate'           => ['required_if:pay_type,daily', 'nullable', 'decimal:0,2', 'min:0'],

            // Date validation
            'date_hired' => ['required', 'date', 'before_or_equal:today'],

            // Contact — optional but validated format
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'email'         => ['nullable', 'email', 'max:255'],

            // Government IDs — optional strings
            'sss_no'       => ['nullable', 'string', 'max:20'],
            'tin'          => ['nullable', 'string', 'max:20'],

            // Bank
            'bank_name'       => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Custom error messages — user-friendly, not generic.
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'department_id.exists' => 'Selected department does not exist.',
            'basic_monthly_salary.required_if' => 'Monthly salary is required for monthly-rated employees.',
            'daily_rate.required_if' => 'Daily rate is required for daily-rated employees.',
            'date_hired.before_or_equal' => 'Hire date cannot be in the future.',
        ];
    }
}
```

---

## 5. API RESOURCE PATTERN

```php
<?php
// app/Modules/HR/Resources/EmployeeResource.php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            // ID — ALWAYS hash_id, NEVER raw integer
            'id' => $this->hash_id,

            // Basic fields
            'employee_no' => $this->employee_no,
            'first_name'  => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name'   => $this->last_name,
            'suffix'      => $this->suffix,
            'full_name'   => $this->full_name,

            // Status
            'status'          => $this->status,
            'employment_type' => $this->employment_type,
            'pay_type'        => $this->pay_type,

            // Money — returned as string to preserve decimal precision
            'basic_monthly_salary' => $this->basic_monthly_salary,
            'daily_rate'           => $this->daily_rate,

            // Dates — formatted consistently
            'date_hired'       => $this->date_hired?->format('Y-m-d'),
            'date_regularized' => $this->date_regularized?->format('Y-m-d'),

            // Sensitive fields — MASKED for non-authorized users
            'sss_no'          => $this->maskField($this->sss_no, $user),
            'tin'             => $this->maskField($this->tin, $user),
            'bank_account_no' => $this->maskField($this->bank_account_no, $user),

            // Relationships — only when loaded (prevents N+1)
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'position'   => new PositionResource($this->whenLoaded('position')),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Mask sensitive field for non-authorized users.
     * HR, Finance, Admin, and the employee themselves see full value.
     * Everyone else sees: ***-**-4567
     */
    private function maskField(?string $value, $user): ?string
    {
        if ($value === null) return null;

        // Employee can see their own data
        if ($user->employee_id === $this->id) return $value;

        // Authorized roles see full data
        if ($user->can('hr.employees.view_sensitive')) return $value;

        // Mask: show only last 4 characters
        $length = mb_strlen($value);
        if ($length <= 4) return str_repeat('•', $length);
        return str_repeat('•', $length - 4) . mb_substr($value, -4);
    }
}
```

---

## 6. CONTROLLER PATTERN

```php
<?php
// app/Modules/HR/Controllers/EmployeeController.php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\StoreEmployeeRequest;
use App\Modules\HR\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Requests\ListEmployeesRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController
{
    public function __construct(
        private EmployeeService $service,
    ) {}

    /**
     * GET /api/v1/employees
     * List — paginated, filtered, sorted.
     */
    public function index(ListEmployeesRequest $request): AnonymousResourceCollection
    {
        $employees = $this->service->list($request->validated());
        return EmployeeResource::collection($employees);
    }

    /**
     * POST /api/v1/employees
     * Create — returns 201 with created resource.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->service->create($request->validated());
        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/employees/{employee}
     * Show — returns single resource with relationships.
     * {employee} is a HashID, resolved by HasHashId trait.
     */
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource(
            $this->service->show($employee)
        );
    }

    /**
     * PUT /api/v1/employees/{employee}
     * Update — returns updated resource.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee = $this->service->update($employee, $request->validated());
        return new EmployeeResource($employee);
    }

    /**
     * DELETE /api/v1/employees/{employee}
     * Soft delete — returns 204 No Content.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        // Permission checked via middleware on route
        $this->service->delete($employee);
        return response()->json(null, 204);
    }
}
```

---

## 7. ROUTE PATTERN

```php
<?php
// app/Modules/HR/routes.php

use App\Modules\HR\Controllers\EmployeeController;
use App\Modules\HR\Controllers\DepartmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:hr'])->prefix('employees')->group(function () {
    Route::get('/',           [EmployeeController::class, 'index'])
        ->middleware('permission:hr.employees.view');
    Route::post('/',          [EmployeeController::class, 'store'])
        ->middleware('permission:hr.employees.create');
    Route::get('/{employee}', [EmployeeController::class, 'show'])
        ->middleware('permission:hr.employees.view');
    Route::put('/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:hr.employees.edit');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:hr.employees.delete');
});
```

---

## 8. API LAYER PATTERN (Frontend)

```typescript
// spa/src/api/employees.ts

import { client } from './client';
import type { Employee, CreateEmployeeData, UpdateEmployeeData, PaginatedResponse, ListParams } from '@/types';

export const employeesApi = {
  /**
   * List — always returns paginated response.
   * Params: search, department_id, status, pay_type, sort, direction, page, per_page
   */
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<Employee>>('/employees', { params }).then(r => r.data),

  /**
   * Show — returns single employee with relationships.
   * ID is always a HashID string, never a number.
   */
  show: (id: string) =>
    client.get<{ data: Employee }>(`/employees/${id}`).then(r => r.data.data),

  /**
   * Create — returns created employee.
   * Throws AxiosError with 422 validation errors.
   */
  create: (data: CreateEmployeeData) =>
    client.post<{ data: Employee }>('/employees', data).then(r => r.data.data),

  /**
   * Update — returns updated employee.
   * ID is HashID string.
   */
  update: (id: string, data: UpdateEmployeeData) =>
    client.put<{ data: Employee }>(`/employees/${id}`, data).then(r => r.data.data),

  /**
   * Delete — returns void (204 No Content).
   */
  delete: (id: string) =>
    client.delete(`/employees/${id}`),
};
```

### Axios Client Setup

```typescript
// spa/src/api/client.ts

import axios from 'axios';
import toast from 'react-hot-toast';

export const client = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,  // MANDATORY for HTTP-only cookies
  headers: {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// ─── Response interceptor ─────────────────────────
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;

    switch (status) {
      case 401:
        // Session expired — redirect to login
        window.location.href = '/login';
        break;
      case 403:
        // Check if password expired
        if (error.response?.data?.code === 'password_expired') {
          window.location.href = '/change-password';
        } else {
          toast.error('You do not have permission to perform this action.');
        }
        break;
      case 404:
        toast.error('The requested resource was not found.');
        break;
      case 422:
        // Validation errors — handled by the form, don't toast
        break;
      case 429:
        toast.error('Too many requests. Please wait a moment.');
        break;
      case 500:
        toast.error('An unexpected error occurred. Please try again.');
        break;
      default:
        if (!error.response) {
          toast.error('Network error. Please check your connection.');
        }
    }

    return Promise.reject(error);
  }
);

// ─── CSRF cookie helper ─────────────────────────
export const getCsrfCookie = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
```

---

## 9. TYPESCRIPT TYPES PATTERN

```typescript
// spa/src/types/index.ts

// ─── API Response Wrappers ────────────────────────
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

export interface ListParams {
  search?: string;
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
  [key: string]: any; // for module-specific filters
}

// ─── API Error Shape ──────────────────────────────
export interface ApiValidationError {
  message: string;
  errors: Record<string, string[]>;
}

// ─── Models ───────────────────────────────────────
// ID is ALWAYS string (HashID), never number
export interface Employee {
  id: string;
  employee_no: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  suffix: string | null;
  full_name: string;
  status: 'active' | 'on_leave' | 'suspended' | 'resigned' | 'terminated' | 'retired';
  employment_type: 'regular' | 'probationary' | 'contractual' | 'project_based';
  pay_type: 'monthly' | 'daily';
  basic_monthly_salary: string | null;  // decimal as string
  daily_rate: string | null;
  date_hired: string;
  date_regularized: string | null;
  sss_no: string | null;
  tin: string | null;
  bank_account_no: string | null;
  department: Department;
  position: Position;
  created_at: string;
  updated_at: string;
}

export interface Department {
  id: string;
  name: string;
  code: string;
}

export interface Position {
  id: string;
  title: string;
  department: Department;
}

// ─── Create/Update DTOs ───────────────────────────
// These match the FormRequest rules exactly
export interface CreateEmployeeData {
  first_name: string;
  middle_name?: string;
  last_name: string;
  suffix?: string;
  department_id: number;  // raw ID sent to API (backend resolves)
  position_id: number;
  employment_type: string;
  pay_type: string;
  basic_monthly_salary?: string;
  daily_rate?: string;
  date_hired: string;
  sss_no?: string;
  tin?: string;
  bank_name?: string;
  bank_account_no?: string;
  mobile_number?: string;
  email?: string;
}

export type UpdateEmployeeData = Partial<CreateEmployeeData>;
```

---

## 10. LIST PAGE PATTERN

This is the most common page type. COPY THIS EXACTLY for every list page.

```tsx
// spa/src/pages/hr/employees/index.tsx

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Download } from 'lucide-react';
import { employeesApi } from '@/api/employees';
import { DataTable } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePermission } from '@/hooks/usePermission';
import type { Employee, ListParams } from '@/types';

// ─── Status → chip variant mapping ────────────────
const statusVariant: Record<string, string> = {
  active: 'success',
  on_leave: 'info',
  suspended: 'warning',
  resigned: 'neutral',
  terminated: 'danger',
  retired: 'neutral',
};

// ─── Column definitions ───────────────────────────
const columns = [
  {
    key: 'employee_no',
    header: 'Employee No',
    sortable: true,
    cell: (row: Employee) => (
      <Link to={`/hr/employees/${row.id}`} className="font-mono text-accent hover:underline">
        {row.employee_no}
      </Link>
    ),
  },
  {
    key: 'full_name',
    header: 'Name',
    sortable: true,
    cell: (row: Employee) => (
      <div>
        <div className="font-medium">{row.full_name}</div>
        <div className="text-xs text-muted">{row.position?.title}</div>
      </div>
    ),
  },
  {
    key: 'department',
    header: 'Department',
    cell: (row: Employee) => row.department?.name ?? '—',
  },
  {
    key: 'pay_type',
    header: 'Pay Type',
    cell: (row: Employee) => row.pay_type === 'monthly' ? 'Monthly' : 'Daily',
  },
  {
    key: 'date_hired',
    header: 'Date Hired',
    sortable: true,
    cell: (row: Employee) => (
      <span className="font-mono tabular-nums">{row.date_hired}</span>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    cell: (row: Employee) => (
      <Chip variant={statusVariant[row.status] ?? 'neutral'}>
        {row.status.replace('_', ' ')}
      </Chip>
    ),
  },
];

export default function EmployeeList() {
  const navigate = useNavigate();
  const { can } = usePermission();

  // ─── Filter state ─────────────────────────────
  const [filters, setFilters] = useState<ListParams>({
    page: 1,
    per_page: 20,
    sort: 'employee_no',
    direction: 'desc',
  });

  // ─── Data fetching ────────────────────────────
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['employees', filters],
    queryFn: () => employeesApi.list(filters),
    // Keep previous data while fetching next page (no flash)
    placeholderData: (previousData) => previousData,
  });

  // ─── Filter config ────────────────────────────
  const filterConfig = [
    {
      key: 'department_id',
      label: 'Department',
      type: 'select' as const,
      options: [], // populated from departments API
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select' as const,
      options: [
        { value: 'active', label: 'Active' },
        { value: 'on_leave', label: 'On Leave' },
        { value: 'resigned', label: 'Resigned' },
        { value: 'terminated', label: 'Terminated' },
      ],
    },
    {
      key: 'pay_type',
      label: 'Pay Type',
      type: 'select' as const,
      options: [
        { value: 'monthly', label: 'Monthly' },
        { value: 'daily', label: 'Daily' },
      ],
    },
  ];

  // ─── Event handlers ───────────────────────────
  const handlePageChange = (page: number) => {
    setFilters(prev => ({ ...prev, page }));
  };

  const handleSort = (sort: string, direction: 'asc' | 'desc') => {
    setFilters(prev => ({ ...prev, sort, direction, page: 1 }));
  };

  const handleFilter = (key: string, value: any) => {
    setFilters(prev => ({ ...prev, [key]: value, page: 1 }));
  };

  const handleSearch = (search: string) => {
    setFilters(prev => ({ ...prev, search, page: 1 }));
  };

  // ─── Render ───────────────────────────────────
  return (
    <div>
      {/* Page header with action buttons */}
      <PageHeader
        title="Employees"
        subtitle={data ? `${data.meta.total} employees` : undefined}
        actions={
          <>
            <Button variant="secondary" size="sm" icon={<Download size={14} />}>
              Export
            </Button>
            {can('hr.employees.create') && (
              <Button
                variant="primary"
                size="sm"
                icon={<Plus size={14} />}
                onClick={() => navigate('/hr/employees/create')}
              >
                Add Employee
              </Button>
            )}
          </>
        }
      />

      {/* Filter bar with search */}
      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={handleFilter}
        onSearch={handleSearch}
        searchPlaceholder="Search by name or employee no..."
      />

      {/* ─── LOADING STATE ─── */}
      {isLoading && !data && (
        <SkeletonTable columns={6} rows={10} />
      )}

      {/* ─── ERROR STATE ─── */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load employees"
          description="An error occurred while loading the employee list. Please try again."
          action={
            <Button variant="secondary" onClick={() => window.location.reload()}>
              Retry
            </Button>
          }
        />
      )}

      {/* ─── EMPTY STATE ─── */}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="users"
          title="No employees found"
          description={filters.search
            ? `No employees match "${filters.search}". Try a different search.`
            : 'Get started by adding your first employee.'
          }
          action={can('hr.employees.create') ? (
            <Button variant="primary" onClick={() => navigate('/hr/employees/create')}>
              Add Employee
            </Button>
          ) : undefined}
        />
      )}

      {/* ─── DATA TABLE ─── */}
      {data && data.data.length > 0 && (
        <DataTable
          columns={columns}
          data={data.data}
          meta={data.meta}
          onPageChange={handlePageChange}
          onSort={handleSort}
          currentSort={filters.sort}
          currentDirection={filters.direction}
          onRowClick={(row) => navigate(`/hr/employees/${row.id}`)}
        />
      )}
    </div>
  );
}
```

**CRITICAL ELEMENTS THAT MUST BE IN EVERY LIST PAGE:**
1. ✅ `isLoading` → show `<SkeletonTable />`
2. ✅ `isError` → show `<EmptyState />` with error message and retry button
3. ✅ `data.length === 0` → show `<EmptyState />` with contextual message
4. ✅ `data.length > 0` → show `<DataTable />` with pagination
5. ✅ Permission check before showing "Add" button
6. ✅ `placeholderData` on useQuery to prevent flash between pages
7. ✅ Numbers use `font-mono tabular-nums`
8. ✅ Status uses `<Chip>` with variant mapping
9. ✅ Clickable employee_no links to detail page
10. ✅ Filters reset page to 1 when changed

---

## 12. CREATE FORM PATTERN

```tsx
// spa/src/pages/hr/employees/create.tsx

import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { employeesApi } from '@/api/employees';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';
import { AxiosError } from 'axios';

// ─── Zod schema — matches FormRequest rules ──────
const schema = z.object({
  first_name: z.string().min(1, 'First name is required').max(100),
  middle_name: z.string().max(100).optional().or(z.literal('')),
  last_name: z.string().min(1, 'Last name is required').max(100),
  suffix: z.string().max(20).optional().or(z.literal('')),
  department_id: z.string().min(1, 'Department is required'), // select returns string
  position_id: z.string().min(1, 'Position is required'),
  employment_type: z.string().min(1, 'Employment type is required'),
  pay_type: z.string().min(1, 'Pay type is required'),
  basic_monthly_salary: z.string().optional(),
  daily_rate: z.string().optional(),
  date_hired: z.string().min(1, 'Date hired is required'),
  sss_no: z.string().optional(),
  tin: z.string().optional(),
  mobile_number: z.string().optional(),
  email: z.string().email('Invalid email').optional().or(z.literal('')),
  bank_name: z.string().optional(),
  bank_account_no: z.string().optional(),
}).refine(
  (data) => {
    if (data.pay_type === 'monthly') return !!data.basic_monthly_salary;
    if (data.pay_type === 'daily') return !!data.daily_rate;
    return true;
  },
  { message: 'Salary/rate is required for selected pay type', path: ['basic_monthly_salary'] }
);

type FormValues = z.infer<typeof schema>;

export default function CreateEmployee() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  // ─── Form setup ───────────────────────────────
  const {
    register,
    handleSubmit,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      pay_type: 'monthly',
      employment_type: 'probationary',
    },
  });

  const payType = watch('pay_type');

  // ─── Mutation ─────────────────────────────────
  const mutation = useMutation({
    mutationFn: (data: FormValues) =>
      employeesApi.create({
        ...data,
        department_id: parseInt(data.department_id),
        position_id: parseInt(data.position_id),
      }),
    onSuccess: (employee) => {
      // Invalidate list cache so it refetches
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      toast.success(`Employee ${employee.employee_no} created successfully.`);
      navigate(`/hr/employees/${employee.id}`);
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (error.response?.status === 422 && error.response.data.errors) {
        // Set server-side validation errors on specific fields
        const serverErrors = error.response.data.errors;
        Object.entries(serverErrors).forEach(([field, messages]) => {
          setError(field as keyof FormValues, {
            type: 'server',
            message: messages[0],
          });
        });
        toast.error('Please fix the errors below.');
      }
      // Other errors handled by Axios interceptor
    },
  });

  // ─── Submit handler ───────────────────────────
  const onSubmit = (data: FormValues) => {
    mutation.mutate(data);
  };

  // ─── Render ───────────────────────────────────
  return (
    <div>
      <PageHeader
        title="Add Employee"
        backTo="/hr/employees"
        backLabel="Employees"
      />

      <form onSubmit={handleSubmit(onSubmit)} className="max-w-3xl mx-auto px-5 py-6">

        {/* ─── Section: Personal Information ─── */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
            Personal Information
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="First Name"
              {...register('first_name')}
              error={errors.first_name?.message}
              required
            />
            <Input
              label="Middle Name"
              {...register('middle_name')}
              error={errors.middle_name?.message}
            />
            <Input
              label="Last Name"
              {...register('last_name')}
              error={errors.last_name?.message}
              required
            />
            <Input
              label="Suffix"
              {...register('suffix')}
              placeholder="Jr., Sr., III"
              error={errors.suffix?.message}
            />
          </div>
        </fieldset>

        {/* ─── Section: Employment Details ─── */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
            Employment Details
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Department"
              {...register('department_id')}
              error={errors.department_id?.message}
              required
            >
              <option value="">Select department</option>
              {/* TODO: populate from departments API */}
            </Select>
            <Select
              label="Position"
              {...register('position_id')}
              error={errors.position_id?.message}
              required
            >
              <option value="">Select position</option>
              {/* TODO: populate from positions API */}
            </Select>
            <Select
              label="Employment Type"
              {...register('employment_type')}
              error={errors.employment_type?.message}
              required
            >
              <option value="probationary">Probationary</option>
              <option value="regular">Regular</option>
              <option value="contractual">Contractual</option>
              <option value="project_based">Project-Based</option>
            </Select>
            <Select
              label="Pay Type"
              {...register('pay_type')}
              error={errors.pay_type?.message}
              required
            >
              <option value="monthly">Monthly</option>
              <option value="daily">Daily</option>
            </Select>

            {/* Conditional fields based on pay type */}
            {payType === 'monthly' && (
              <Input
                label="Monthly Salary"
                {...register('basic_monthly_salary')}
                error={errors.basic_monthly_salary?.message}
                placeholder="0.00"
                prefix="₱"
                className="font-mono"
                required
              />
            )}
            {payType === 'daily' && (
              <Input
                label="Daily Rate"
                {...register('daily_rate')}
                error={errors.daily_rate?.message}
                placeholder="0.00"
                prefix="₱"
                className="font-mono"
                required
              />
            )}

            <Input
              label="Date Hired"
              type="date"
              {...register('date_hired')}
              error={errors.date_hired?.message}
              required
            />
          </div>
        </fieldset>

        {/* ─── Section: Government IDs ─── */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
            Government IDs
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Input label="SSS No." {...register('sss_no')} />
            <Input label="TIN" {...register('tin')} />
          </div>
        </fieldset>

        {/* ─── Section: Contact ─── */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
            Contact Information
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Mobile Number" {...register('mobile_number')} />
            <Input
              label="Email"
              type="email"
              {...register('email')}
              error={errors.email?.message}
            />
          </div>
        </fieldset>

        {/* ─── Action bar ─── */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/hr/employees')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            // CRITICAL: disable while submitting to prevent double-submit
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Creating...' : 'Create Employee'}
          </Button>
        </div>
      </form>
    </div>
  );
}
```

**CRITICAL ELEMENTS THAT MUST BE IN EVERY FORM:**
1. ✅ Zod schema matches backend FormRequest rules exactly
2. ✅ `useMutation` with `onSuccess` (toast + invalidate + navigate) and `onError` (set field errors from 422)
3. ✅ Submit button has `disabled={isSubmitting || mutation.isPending}`
4. ✅ Submit button shows `loading` state with "Creating..." text
5. ✅ Cancel button navigates back without saving
6. ✅ Field errors shown inline via `error={errors.field?.message}`
7. ✅ Server-side 422 errors mapped to specific fields via `setError()`
8. ✅ `required` prop on mandatory fields
9. ✅ Fieldset with legend sections for visual grouping
10. ✅ `queryClient.invalidateQueries` on success to refresh list
11. ✅ Money inputs use `font-mono` class + ₱ prefix
12. ✅ Conditional fields based on selection (pay_type → salary/rate)

---

## 14. DELETE CONFIRMATION PATTERN

```tsx
// Used inside any component that needs delete
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import toast from 'react-hot-toast';

function DeleteEmployeeModal({ employee, isOpen, onClose }) {
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: () => employeesApi.delete(employee.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employees'] });
      toast.success(`${employee.full_name} has been removed.`);
      onClose();
    },
    onError: () => {
      toast.error('Failed to delete employee. Please try again.');
    },
  });

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm" title="Delete Employee">
      <div className="py-4">
        <p className="text-sm text-secondary">
          Are you sure you want to remove <span className="font-medium text-primary">{employee.full_name}</span>?
          This action cannot be undone.
        </p>
      </div>
      <div className="flex justify-end gap-2 pt-4 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
          Cancel
        </Button>
        <Button
          variant="danger"
          onClick={() => mutation.mutate()}
          disabled={mutation.isPending}
          loading={mutation.isPending}
        >
          {mutation.isPending ? 'Deleting...' : 'Delete'}
        </Button>
      </div>
    </Modal>
  );
}
```

**CRITICAL:** Both buttons disabled while deleting. Danger variant button. Descriptive confirmation text. Toast feedback on both success and error.

---

## 15. APPROVAL ACTIONS PATTERN

```tsx
// For any record with approval workflow (Leave, PR, PO, Loan)

function ApprovalActions({ record, entityType }: { record: any; entityType: string }) {
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [rejectRemarks, setRejectRemarks] = useState('');
  const queryClient = useQueryClient();

  const approveMutation = useMutation({
    mutationFn: () => client.patch(`/${entityType}/${record.id}/approve`, {
      action: 'approve',
      remarks: '',
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [entityType] });
      toast.success('Approved successfully.');
    },
  });

  const rejectMutation = useMutation({
    mutationFn: () => client.patch(`/${entityType}/${record.id}/approve`, {
      action: 'reject',
      remarks: rejectRemarks,
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [entityType] });
      toast.success('Rejected.');
      setShowRejectModal(false);
    },
  });

  // Only show if current user is the expected approver
  if (!record.is_pending_my_approval) return null;

  return (
    <div className="flex gap-2">
      <Button
        variant="primary"
        size="sm"
        onClick={() => approveMutation.mutate()}
        disabled={approveMutation.isPending}
        loading={approveMutation.isPending}
      >
        Approve
      </Button>
      <Button
        variant="danger"
        size="sm"
        onClick={() => setShowRejectModal(true)}
      >
        Reject
      </Button>

      {/* Reject requires remarks */}
      <Modal isOpen={showRejectModal} onClose={() => setShowRejectModal(false)} size="sm" title="Reject">
        <div className="py-3">
          <label className="text-xs text-muted font-medium mb-1 block">Reason for rejection</label>
          <textarea
            value={rejectRemarks}
            onChange={(e) => setRejectRemarks(e.target.value)}
            className="w-full h-24 px-3 py-2 rounded-md border border-default bg-canvas text-sm resize-none"
            placeholder="Enter reason..."
            required
          />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button variant="secondary" onClick={() => setShowRejectModal(false)}>Cancel</Button>
          <Button
            variant="danger"
            onClick={() => rejectMutation.mutate()}
            disabled={!rejectRemarks.trim() || rejectMutation.isPending}
            loading={rejectMutation.isPending}
          >
            Confirm Reject
          </Button>
        </div>
      </Modal>
    </div>
  );
}
```

**CRITICAL:** Approve doesn't need modal. Reject ALWAYS requires remarks (mandatory textarea). Reject button disabled until remarks entered. Both show loading states.

---

## 19. PAGE STATES — MANDATORY CHECKLIST

**EVERY page MUST handle these 5 states. No exceptions.**

```
STATE 1: LOADING (first load, no cached data)
  → Show: <SkeletonTable> for lists, <SkeletonForm> for forms, <SkeletonDetail> for detail pages
  → Never show empty page or spinner alone

STATE 2: ERROR (API failed)
  → Show: <EmptyState icon="alert-circle" title="Failed to load..." action={<RetryButton>}>
  → Never show blank page or console errors

STATE 3: EMPTY (API returned [], no data exists)
  → Show: <EmptyState icon="[contextual]" title="No [items] found" action={<CreateButton if permitted>}>
  → If search was active: "No results for '[query]'. Try a different search."
  → Never show empty table with just headers

STATE 4: DATA (normal state)
  → Show: content with all formatting, clickable links, status chips, mono numbers

STATE 5: STALE (refetching in background)
  → Show: previous data with subtle opacity or loading indicator
  → Use TanStack Query placeholderData to prevent flash
  → Never flash to loading skeleton between pages
```

### Loading skeletons

```tsx
// spa/src/components/ui/Skeleton.tsx

// Table skeleton — matches your DataTable dimensions exactly
export function SkeletonTable({ columns = 6, rows = 10 }) {
  return (
    <div className="border border-default rounded-md overflow-hidden">
      {/* Header */}
      <div className="h-8 border-b border-default bg-subtle flex items-center px-2.5 gap-4">
        {Array.from({ length: columns }).map((_, i) => (
          <div key={i} className="h-2.5 bg-elevated rounded w-16 animate-pulse" />
        ))}
      </div>
      {/* Rows */}
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-8 border-b border-subtle flex items-center px-2.5 gap-4">
          {Array.from({ length: columns }).map((_, j) => (
            <div key={j} className="h-2.5 bg-elevated rounded animate-pulse"
              style={{ width: `${40 + Math.random() * 60}px` }}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

// Detail page skeleton
export function SkeletonDetail() {
  return (
    <div className="px-5 py-4">
      <div className="h-5 w-48 bg-elevated rounded animate-pulse mb-4" />
      <div className="grid grid-cols-4 gap-2 mb-6">
        {[1,2,3,4].map(i => (
          <div key={i} className="h-16 bg-elevated rounded-md animate-pulse" />
        ))}
      </div>
      <SkeletonTable columns={5} rows={4} />
    </div>
  );
}

// Form skeleton
export function SkeletonForm() {
  return (
    <div className="max-w-3xl mx-auto px-5 py-6">
      {[1,2,3].map(section => (
        <div key={section} className="mb-8">
          <div className="h-3 w-32 bg-elevated rounded animate-pulse mb-4" />
          <div className="grid grid-cols-2 gap-3">
            {[1,2,3,4].map(i => (
              <div key={i} className="h-8 bg-elevated rounded-md animate-pulse" />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
```

---

## 20. TOAST NOTIFICATIONS

```tsx
// ALWAYS use toast for feedback. Never alert(). Never console.log user-facing messages.

// Success — after create, update, delete, approve, reject
toast.success('Employee created successfully.');
toast.success('Leave request approved.');
toast.success('Purchase order sent to supplier.');

// Error — when API call fails (422 errors show on form, not toast)
toast.error('Failed to create employee. Please try again.');
toast.error('An unexpected error occurred.');

// Never show generic "Error" — always describe what failed.
// Never show technical details (SQL errors, stack traces).
```

---

## 21. ROUTE SETUP PATTERN

```tsx
// spa/src/App.tsx

import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

// Lazy load ALL page components — code splitting per module
const Login = lazy(() => import('@/pages/auth/login'));
const ChangePassword = lazy(() => import('@/pages/auth/change-password'));
const Dashboard = lazy(() => import('@/pages/dashboard/index'));
const EmployeeList = lazy(() => import('@/pages/hr/employees/index'));
const EmployeeCreate = lazy(() => import('@/pages/hr/employees/create'));
const EmployeeDetail = lazy(() => import('@/pages/hr/employees/[id]'));
const EmployeeEdit = lazy(() => import('@/pages/hr/employees/[id]/edit'));

export default function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<FullPageLoader />}>
        <Routes>
          {/* Auth pages — no layout guard */}
          <Route element={<AuthLayout />}>
            <Route path="/login" element={<Login />} />
            <Route path="/change-password" element={<ChangePassword />} />
          </Route>

          {/* Protected pages — AuthGuard wraps everything */}
          <Route element={<AuthGuard><AppLayout /></AuthGuard>}>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<Dashboard />} />

            {/* HR Module — ModuleGuard + PermissionGuard */}
            <Route element={<ModuleGuard module="hr" />}>
              <Route
                path="/hr/employees"
                element={<PermissionGuard permission="hr.employees.view"><EmployeeList /></PermissionGuard>}
              />
              <Route
                path="/hr/employees/create"
                element={<PermissionGuard permission="hr.employees.create"><EmployeeCreate /></PermissionGuard>}
              />
              <Route
                path="/hr/employees/:id"
                element={<PermissionGuard permission="hr.employees.view"><EmployeeDetail /></PermissionGuard>}
              />
              <Route
                path="/hr/employees/:id/edit"
                element={<PermissionGuard permission="hr.employees.edit"><EmployeeEdit /></PermissionGuard>}
              />
            </Route>

            {/* Repeat for every module... */}
          </Route>

          {/* 404 */}
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  );
}
```

**CRITICAL:**
1. ✅ Every page is `lazy()` imported — code splitting per module
2. ✅ `<Suspense>` wraps everything with `<FullPageLoader />` fallback
3. ✅ `<AuthGuard>` wraps all protected routes — redirects to /login if no session
4. ✅ `<ModuleGuard>` wraps each module group — shows "module disabled" if feature toggle off
5. ✅ `<PermissionGuard>` wraps specific routes — shows 403 if no permission
6. ✅ Root `/` redirects to `/dashboard`
7. ✅ Catch-all `*` route shows 404 page

---

## FINAL CHECKLIST — APPLY TO EVERY TASK

Before marking any task complete, verify:

- [ ] Backend: Migration has proper types (decimal for money, text for encrypted, indexes on FKs)
- [ ] Backend: Model has HasHashId trait
- [ ] Backend: Model has encrypted casts for sensitive fields
- [ ] Backend: Service wraps mutations in DB::transaction()
- [ ] Backend: Controller returns proper HTTP status codes (201 create, 204 delete)
- [ ] Backend: FormRequest has authorize() checking permission
- [ ] Backend: Resource returns hash_id, never raw id
- [ ] Backend: Resource masks sensitive fields
- [ ] Backend: Routes have permission middleware
- [ ] Frontend: API layer uses hash_id strings, never numbers
- [ ] Frontend: Page handles LOADING state (skeleton)
- [ ] Frontend: Page handles ERROR state (empty state with retry)
- [ ] Frontend: Page handles EMPTY state (contextual message)
- [ ] Frontend: Form has Zod schema matching backend validation
- [ ] Frontend: Form submit button disabled while submitting
- [ ] Frontend: Form shows server-side errors on specific fields
- [ ] Frontend: Form has cancel button that navigates back
- [ ] Frontend: Success toast after every mutation
- [ ] Frontend: Error toast for unexpected failures
- [ ] Frontend: Numbers use font-mono tabular-nums
- [ ] Frontend: Status fields use Chip component with variant mapping
- [ ] Frontend: Routes wrapped in AuthGuard + ModuleGuard + PermissionGuard
- [ ] Frontend: Page component is lazy-loaded
