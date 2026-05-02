<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Requests\ApproveLeaveRequest;
use App\Modules\Leave\Requests\RejectLeaveRequest;
use App\Modules\Leave\Requests\StoreLeaveRequestRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveRequestController
{
    public function __construct(private readonly LeaveRequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $d = $request->validatedData();
        try {
            $req = $this->service->submit($d['employee_id'], $d);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new LeaveRequestResource($req))->response()->setStatusCode(201);
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType', 'deptApprover', 'hrApprover']));
    }

    public function approveDept(ApproveLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        try {
            $req = $this->service->approveDept($leaveRequest, $request->user(), $request->input('remarks'));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new LeaveRequestResource($req);
    }

    public function approveHR(ApproveLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        try {
            $req = $this->service->approveHR($leaveRequest, $request->user(), $request->input('remarks'));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new LeaveRequestResource($req);
    }

    public function reject(RejectLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $req = $this->service->reject($leaveRequest, $request->user(), $request->input('reason'));
        return new LeaveRequestResource($req);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $req = $this->service->cancel($leaveRequest, $request->user());
        return new LeaveRequestResource($req);
    }
}
