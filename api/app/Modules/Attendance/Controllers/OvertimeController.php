<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Requests\ApproveOvertimeRequestRequest;
use App\Modules\Attendance\Requests\RejectOvertimeRequestRequest;
use App\Modules\Attendance\Requests\StoreOvertimeRequestRequest;
use App\Modules\Attendance\Resources\OvertimeRequestResource;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OvertimeController
{
    public function __construct(private readonly OvertimeService $service) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->service->options()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return OvertimeRequestResource::collection($this->service->list($request->query(), $request->user()));
    }

    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $ot = $this->service->create($request->validatedData());

        return (new OvertimeRequestResource($ot))->response()->setStatusCode(201);
    }

    public function show(OvertimeRequest $overtime, Request $request): OvertimeRequestResource
    {
        $user = $request->user();
        $canView = $user?->role?->slug === 'system_admin'
            || (int) $user?->employee_id === (int) $overtime->employee_id;

        if (! $canView && $user?->hasPermission('attendance.ot.approve') && $user->employee_id) {
            $ownDepartment = Employee::query()
                ->whereKey($user->employee_id)
                ->value('department_id');
            $recordDepartment = Employee::query()
                ->whereKey($overtime->employee_id)
                ->value('department_id');
            $canView = $ownDepartment && (int) $ownDepartment === (int) $recordDepartment;
        }

        abort_unless($canView, 403, 'You do not have permission to view this overtime request.');

        return new OvertimeRequestResource($overtime->load(['employee', 'approver']));
    }

    public function approve(ApproveOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        // OGAMI audit DEFECT-1 — the service throws RuntimeException for business
        // -rule violations (self-approval SoD, non-pending state). Surface them as
        // 422 like the Leave/Loan controllers, instead of leaking an unhandled 500.
        try {
            $ot = $this->service->approve($overtime, $request->user(), $request->input('remarks'));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return new OvertimeRequestResource($ot);
    }

    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        try {
            $ot = $this->service->reject($overtime, $request->user(), $request->input('reason'));
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return new OvertimeRequestResource($ot);
    }

    /**
     * Cancel a pending overtime request. The owning employee may withdraw
     * their own; admins and OT approvers may cancel any pending one. Renders
     * the same authorization shape as show() — department-scoped for approvers.
     */
    public function cancel(Request $request, OvertimeRequest $overtime): OvertimeRequestResource
    {
        $user = $request->user();
        $isAdmin = $user?->role?->slug === 'system_admin';
        $isOwner = (int) $user?->employee_id === (int) $overtime->employee_id;
        $canCancel = $isAdmin || $isOwner;

        if (! $canCancel && $user?->hasPermission('attendance.ot.approve') && $user->employee_id) {
            $ownDepartment = Employee::query()
                ->whereKey($user->employee_id)
                ->value('department_id');
            $recordDepartment = Employee::query()
                ->whereKey($overtime->employee_id)
                ->value('department_id');
            $canCancel = $ownDepartment && (int) $ownDepartment === (int) $recordDepartment;
        }

        abort_unless($canCancel, 403, 'You do not have permission to cancel this overtime request.');

        $reason = $request->input('reason')
            ? 'Cancelled: '.trim((string) $request->input('reason'))
            : 'Cancelled.';

        try {
            $ot = $this->service->cancel($overtime, $user, $reason);
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        return new OvertimeRequestResource($ot);
    }

    /**
     * L-23 — Bulk approve. Body: { ids: ["hash1", "hash2", ...], remarks?: string }.
     * Returns 200 with summary {approved_count, failed} so the SPA can surface
     * partial successes; per-row failures don't abort the batch.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $decoded = collect($validated['ids'])
            ->map(fn (string $hash) => HashIdFilter::decode($hash, OvertimeRequest::class))
            ->filter()
            ->values()
            ->all();

        $result = $this->service->bulkApprove(
            $decoded,
            $request->user(),
            $validated['remarks'] ?? null,
        );

        return response()->json([
            'message' => sprintf('%d approved, %d failed.', count($result['approved']), count($result['failed'])),
            'approved_count' => count($result['approved']),
            'failed' => $result['failed'],
            'data' => OvertimeRequestResource::collection($result['approved']),
        ]);
    }
}
