<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeProperty;
use App\Modules\HR\Requests\StoreEmployeePropertyRequest;
use App\Modules\HR\Requests\UpdateEmployeePropertyRequest;
use App\Modules\HR\Resources\EmployeePropertyResource;
use App\Modules\HR\Services\EmployeePropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeePropertyController
{
    public function __construct(
        private readonly EmployeePropertyService $service,
    ) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        return EmployeePropertyResource::collection(
            $this->service->list($employee, $request->query()),
        );
    }

    public function store(StoreEmployeePropertyRequest $request, Employee $employee): JsonResponse
    {
        $property = $this->service->create($employee, $request->validated());
        return (new EmployeePropertyResource($property))->response()->setStatusCode(201);
    }

    public function show(EmployeeProperty $employeeProperty): EmployeePropertyResource
    {
        return new EmployeePropertyResource($employeeProperty);
    }

    public function update(UpdateEmployeePropertyRequest $request, EmployeeProperty $employeeProperty): EmployeePropertyResource
    {
        return new EmployeePropertyResource(
            $this->service->update($employeeProperty, $request->validated()),
        );
    }

    public function destroy(EmployeeProperty $employeeProperty): JsonResponse
    {
        $this->service->delete($employeeProperty);
        return response()->json(null, 204);
    }

    public function restore(EmployeeProperty $employeeProperty): JsonResponse
    {
        $employeeProperty->restore();
        return response()->json(['message' => 'Employee property restored.']);
    }
}