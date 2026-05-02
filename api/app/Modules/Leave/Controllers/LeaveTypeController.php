<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Requests\StoreLeaveTypeRequest;
use App\Modules\Leave\Requests\UpdateLeaveTypeRequest;
use App\Modules\Leave\Resources\LeaveTypeResource;
use App\Modules\Leave\Services\LeaveTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController
{
    public function __construct(private readonly LeaveTypeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection($this->service->list($request->query()));
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $lt = $this->service->create($request->validated());
        return (new LeaveTypeResource($lt))->response()->setStatusCode(201);
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($leaveType);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($this->service->update($leaveType, $request->validated()));
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $this->service->delete($leaveType);
        return response()->json(null, 204);
    }
}
