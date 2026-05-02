<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Requests\StoreDepartmentRequest;
use App\Modules\HR\Requests\UpdateDepartmentRequest;
use App\Modules\HR\Resources\DepartmentResource;
use App\Modules\HR\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController
{
    public function __construct(private readonly DepartmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DepartmentResource::collection($this->service->list($request->query()));
    }

    public function tree(): AnonymousResourceCollection
    {
        return DepartmentResource::collection($this->service->tree());
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $dept = $this->service->create($request->validatedData());
        return (new DepartmentResource($dept))->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($this->service->show($department));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        return new DepartmentResource($this->service->update($department, $request->validatedData()));
    }

    public function destroy(Department $department): JsonResponse
    {
        try {
            $this->service->delete($department);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}
