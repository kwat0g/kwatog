<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Enums\EmployeeSkillLevel;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Requests\SeparateEmployeeRequest;
use App\Modules\HR\Requests\StoreEmployeeRequest;
use App\Modules\HR\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController
{
    public function __construct(
        private readonly EmployeeService $service,
        private readonly RecruitmentService $recruitmentService,
    ) {}

    /**
     * @OA\Get(
     *     path="/employees",
     *     tags={"Employees"},
     *     summary="List employees",
     *     description="Returns a paginated list of employees. Filterable by status, department, and search term.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"active","on_leave","resigned","terminated"})),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string"), description="Department hash ID"),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Search by name or employee number"),
     *
     *     @OA\Response(response=200, description="Paginated employee list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static fn (EmployeeStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                EmployeeStatus::cases(),
            ),
            'employment_types' => array_map(
                static fn (EmploymentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                EmploymentType::cases(),
            ),
            'pay_types' => array_map(
                static fn (PayType $type): array => ['value' => $type->value, 'label' => $type->label()],
                PayType::cases(),
            ),
            'genders' => array_map(
                static fn (Gender $gender): array => ['value' => $gender->value, 'label' => $gender->label()],
                Gender::cases(),
            ),
            'civil_statuses' => array_map(
                static fn (CivilStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                CivilStatus::cases(),
            ),
            'separation_reasons' => array_map(
                static fn (SeparationReason $reason): array => ['value' => $reason->value, 'label' => str_replace('_', ' ', ucfirst($reason->value))],
                SeparationReason::cases(),
            ),
            'skill_levels' => array_map(
                static fn (EmployeeSkillLevel $level): array => [
                    'value' => $level->value,
                    'label' => str_replace('_', ' ', ucfirst($level->value)),
                ],
                EmployeeSkillLevel::cases(),
            ),
        ]]);
    }

    /**
     * @OA\Post(
     *     path="/employees",
     *     tags={"Employees"},
     *     summary="Create a new employee",
     *     description="Creates an employee record with auto-generated employee number (OGM-YYYY-NNNN format).",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"first_name", "last_name", "department_id", "position_id", "date_hired"},
     *
     *             @OA\Property(property="first_name", type="string", maxLength=100),
     *             @OA\Property(property="last_name", type="string", maxLength=100),
     *             @OA\Property(property="department_id", type="string", description="Department hash ID"),
     *             @OA\Property(property="position_id", type="string", description="Position hash ID"),
     *             @OA\Property(property="date_hired", type="string", format="date", example="2026-07-01"),
     *             @OA\Property(property="basic_monthly_salary", type="string", example="25000.00"),
     *             @OA\Property(property="from_application", type="string", description="Job application hash ID to link (optional)")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Employee created"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validatedData();
        $fromApplication = $data['from_application'] ?? null;
        unset($data['from_application']);

        $employee = $this->service->create($data);

        if ($fromApplication) {
            $decoded = app('hashids')->decode($fromApplication);
            if (! empty($decoded)) {
                $application = JobApplication::find($decoded[0]);
                if ($application && $application->stage === ApplicationStage::Hired) {
                    $this->recruitmentService->markConverted($application, $employee);
                }
            }
        }

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *     path="/employees/{id}",
     *     tags={"Employees"},
     *     summary="Show employee detail",
     *     description="Returns full employee details including department, position, and related records.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Employee hash ID"),
     *
     *     @OA\Response(response=200, description="Employee detail"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function show(Request $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->show($employee, $request->user()));
    }

    /**
     * @OA\Put(
     *     path="/employees/{id}",
     *     tags={"Employees"},
     *     summary="Update an employee",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Employee hash ID"),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *
     *         @OA\Property(property="first_name", type="string", maxLength=100),
     *         @OA\Property(property="last_name", type="string", maxLength=100),
     *         @OA\Property(property="department_id", type="string"),
     *         @OA\Property(property="position_id", type="string"),
     *         @OA\Property(property="basic_monthly_salary", type="string", example="28000.00")
     *     )),
     *
     *     @OA\Response(response=200, description="Employee updated"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->update($employee, $request->validatedData()));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->service->delete($employee);

        return response()->json(null, 204);
    }

    public function restore(Employee $employee): JsonResponse
    {
        $employee->restore();
        return response()->json(['message' => 'Employee restored.']);
    }

    public function separate(SeparateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->separate($employee, $request->validated()));
    }

    /**
     * Photos live on the LOCAL disk (never public) and are served only through
     * the permission-gated photo() action. Direct /storage/ access is
     * intentionally impossible — same pattern as delivery proofs.
     */
    public function uploadPhoto(Request $request, Employee $employee): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        if ($employee->photo_path) {
            Storage::disk('local')->delete($employee->photo_path);
            // Also clear any legacy public-disk copy from before this hardening.
            Storage::disk('public')->delete($employee->photo_path);
        }

        $path = $request->file('photo')->store('employee-photos', 'local');
        $employee->update(['photo_path' => $path]);

        return response()->json(['data' => ['photo_url' => "/api/v1/hr/employees/{$employee->hash_id}/photo"]]);
    }

    /**
     * Stream the employee photo. Route must be protected by
     * permission_any:hr.employees.view,hr.directory.view (directory users and
     * the employee's own self-service view must both be able to load it).
     */
    public function photo(Employee $employee): StreamedResponse
    {
        if (! $employee->photo_path) {
            abort(404, 'No photo for this employee.');
        }

        // Prefer the local disk; fall back to the legacy public disk so photos
        // uploaded before this hardening still render.
        $disk = Storage::disk('local');
        $path = $employee->photo_path;
        if (! $disk->exists($path)) {
            $legacy = Storage::disk('public');
            if ($legacy->exists($path)) {
                $disk = $legacy;
            } else {
                abort(404, 'Photo file not found on disk.');
            }
        }

        $contents = $disk->get($path);
        $mime     = $disk->mimeType($path) ?? 'application/octet-stream';
        $filename = basename($path);

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type'        => $mime,
                'Cache-Control'       => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            ],
        );
    }

    public function deletePhoto(Employee $employee): JsonResponse
    {
        if ($employee->photo_path) {
            Storage::disk('local')->delete($employee->photo_path);
            // Also clear any legacy public-disk copy.
            Storage::disk('public')->delete($employee->photo_path);
            $employee->update(['photo_path' => null]);
        }
        return response()->json(null, 204);
    }
}
