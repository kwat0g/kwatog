<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Requests\SeparateEmployeeRequest;
use App\Modules\HR\Requests\StoreEmployeeRequest;
use App\Modules\HR\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"active","on_leave","resigned","terminated"})),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string"), description="Department hash ID"),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Search by name or employee number"),
     *     @OA\Response(response=200, description="Paginated employee list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->service->list($request->query(), $request->user()));
    }

    /**
     * @OA\Post(
     *     path="/employees",
     *     tags={"Employees"},
     *     summary="Create a new employee",
     *     description="Creates an employee record with auto-generated employee number (OGM-YYYY-NNNN format).",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"first_name", "last_name", "department_id", "position_id", "date_hired"},
     *             @OA\Property(property="first_name", type="string", maxLength=100),
     *             @OA\Property(property="last_name", type="string", maxLength=100),
     *             @OA\Property(property="department_id", type="string", description="Department hash ID"),
     *             @OA\Property(property="position_id", type="string", description="Position hash ID"),
     *             @OA\Property(property="date_hired", type="string", format="date", example="2026-07-01"),
     *             @OA\Property(property="basic_monthly_salary", type="string", example="25000.00"),
     *             @OA\Property(property="from_application", type="string", description="Job application hash ID to link (optional)")
     *         )
     *     ),
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
            if (!empty($decoded)) {
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
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Employee hash ID"),
     *     @OA\Response(response=200, description="Employee detail"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->show($employee));
    }

    /**
     * @OA\Put(
     *     path="/employees/{id}",
     *     tags={"Employees"},
     *     summary="Update an employee",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Employee hash ID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="first_name", type="string", maxLength=100),
     *         @OA\Property(property="last_name", type="string", maxLength=100),
     *         @OA\Property(property="department_id", type="string"),
     *         @OA\Property(property="position_id", type="string"),
     *         @OA\Property(property="basic_monthly_salary", type="string", example="28000.00")
     *     )),
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

    public function separate(SeparateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->separate($employee, $request->validated()));
    }
}
