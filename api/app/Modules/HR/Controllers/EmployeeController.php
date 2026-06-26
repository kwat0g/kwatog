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

    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->service->list($request->query(), $request->user()));
    }

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

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->service->show($employee));
    }

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
