<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Enums\LeaveHalfDayPeriod;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Attendance\Services\HolidayService;
use App\Modules\Leave\Requests\ApproveLeaveRequest;
use App\Modules\Leave\Requests\RejectLeaveRequest;
use App\Modules\Leave\Requests\StoreLeaveRequestRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\CarbonImmutable;

class LeaveRequestController
{
    public function __construct(
        private readonly LeaveRequestService $service,
        private readonly HolidayService $holidays,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $range = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
        ])->validate();
        $holidayDates = [];
        if (isset($range['from'], $range['to'])) {
            $start = CarbonImmutable::parse($range['from']);
            $end = CarbonImmutable::parse($range['to']);
            abort_if($end->lt($start) || $start->diffInDays($end, true) > 731, 422, 'Leave options range must be within 731 days.');
            $holidayDates = array_keys($this->holidays->datesBetween($start, $end));
        }

        return response()->json(['data' => [
            'statuses' => array_map(static fn (LeaveRequestStatus $status): array => ['value' => $status->value, 'label' => str_replace('_', ' ', ucfirst($status->value))], LeaveRequestStatus::cases()),
            'half_day_periods' => array_map(
                static fn (LeaveHalfDayPeriod $period): array => ['value' => $period->value, 'label' => $period->label()],
                LeaveHalfDayPeriod::cases(),
            ),
            'holiday_dates' => $holidayDates,
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $d = $request->validatedData();
        $user = $request->user();
        // hasPermission short-circuits for system_admin, so the administrator
        // reaches this through the same grant HR does.
        $canFileForOthers = $user?->hasPermission('leave.approve_hr') ?? false;
        abort_unless(
            $canFileForOthers || (int) $user?->employee_id === (int) $d['employee_id'],
            403,
            'You may only file a leave request for yourself.',
        );

        try {
            $req = $this->service->submit($d['employee_id'], $d);
        } catch (BusinessRuleException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new LeaveRequestResource($req))->response()->setStatusCode(201);
    }

    public function show(LeaveRequest $leaveRequest, Request $request): LeaveRequestResource
    {
        $this->authorizeView($leaveRequest, $request->user());

        return new LeaveRequestResource($leaveRequest->load([
            'employee',
            'leaveType',
            'deptApprover',
            'hrApprover',
            'canceller',
        ]));
    }

    public function downloadDocument(LeaveRequest $leaveRequest, Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeView($leaveRequest, $request->user());
        $path = $leaveRequest->document_path;
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'Supporting document not found.');

        return Storage::disk('local')->download($path, basename($path));
    }

    private function authorizeView(LeaveRequest $leaveRequest, ?User $user): void
    {
        $isHr = $user?->hasPermission('leave.approve_hr') ?? false;
        if ($isHr) {
            return;
        }

        $isDeptHead = $user?->hasPermission('leave.approve_dept') ?? false;
        $isOwn = (int) $leaveRequest->employee_id === (int) $user?->employee_id;
        $isDeptMember = false;
        if ($isDeptHead && $user?->employee_id) {
            $deptId = Employee::query()->whereKey($user->employee_id)->value('department_id');
            $isDeptMember = (int) $leaveRequest->employee?->department_id === (int) $deptId;
        }
        if (! $isOwn && ! $isDeptMember) {
            abort(403, 'You do not have permission to view this leave request.');
        }
    }

    public function approveDept(ApproveLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        try {
            $req = $this->service->approveDept($leaveRequest, $request->user(), $request->input('remarks'));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return new LeaveRequestResource($req);
    }

    public function approveHR(ApproveLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        try {
            $req = $this->service->approveHR($leaveRequest, $request->user(), $request->input('remarks'));
        } catch (BusinessRuleException $e) {
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
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
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
                'failed' => $results['failed'],
            ],
        ]);
    }

    /**
     * T1.7 — Bulk approve HR stage.
     */
    public function bulkApproveHR(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
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
                'failed' => $results['failed'],
            ],
        ]);
    }
}
