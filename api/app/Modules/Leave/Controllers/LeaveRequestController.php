<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Requests\ApproveLeaveRequest;
use App\Modules\Leave\Requests\RejectLeaveRequest;
use App\Modules\Leave\Requests\StoreLeaveRequestRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

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

    public function show(LeaveRequest $leaveRequest, Request $request): LeaveRequestResource
    {
        $user = $request->user();
        $isAdmin = $user?->role?->slug === 'system_admin';
        $isHr    = $user?->hasPermission('leave.approve_hr') ?? false;

        if (! $isAdmin && ! $isHr) {
            $isDeptHead = $user?->hasPermission('leave.approve_dept') ?? false;
            $isOwn = (int) $leaveRequest->employee?->user_id === (int) $user?->id;
            $isDeptMember = false;
            if ($isDeptHead && $user?->employee_id) {
                $deptId = \App\Modules\HR\Models\Employee::query()
                    ->whereKey($user->employee_id)->value('department_id');
                $isDeptMember = (int) $leaveRequest->employee?->department_id === (int) $deptId;
            }
            if (! $isOwn && ! $isDeptMember) {
                abort(403, 'You do not have permission to view this leave request.');
            }
        }

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

    /**
     * T1.7 — Bulk approve dept stage.
     * Body: { ids: ["hashId1", ...], remarks?: string }
     */
    public function bulkApproveDept(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'string',
            'remarks' => 'nullable|string|max:500',
        ])->validate();

        $ids = array_filter(array_map(
            fn ($hash) => HashIdFilter::decode($hash, LeaveRequest::class),
            $validated['ids'],
        ));
        if (empty($ids)) {
            return response()->json(['message' => 'No valid leave request IDs provided.'], 422);
        }

        $results = $this->service->bulkApproveDept($ids, $request->user(), $validated['remarks'] ?? null);

        return response()->json([
            'data' => [
                'approved' => array_map(fn ($r) => (new LeaveRequestResource($r))->toArray($request), $results['approved']),
                'failed'   => $results['failed'],
            ],
        ]);
    }

    /**
     * T1.7 — Bulk approve HR stage.
     */
    public function bulkApproveHR(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'string',
            'remarks' => 'nullable|string|max:500',
        ])->validate();

        $ids = array_filter(array_map(
            fn ($hash) => HashIdFilter::decode($hash, LeaveRequest::class),
            $validated['ids'],
        ));
        if (empty($ids)) {
            return response()->json(['message' => 'No valid leave request IDs provided.'], 422);
        }

        $results = $this->service->bulkApproveHR($ids, $request->user(), $validated['remarks'] ?? null);

        return response()->json([
            'data' => [
                'approved' => array_map(fn ($r) => (new LeaveRequestResource($r))->toArray($request), $results['approved']),
                'failed'   => $results['failed'],
            ],
        ]);
    }
}
