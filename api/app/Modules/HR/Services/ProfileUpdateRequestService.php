<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\DepartmentScope;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Enums\ProfileUpdateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * U3 / Task SS2 — captures employee-initiated profile change requests.
 * Never auto-applies. HR reviews via the profile-update-request queue; only
 * after approval are fields written to the employee row.
 *
 * Bank-account changes are special: they affect payroll disbursement, so
 * they require BOTH HR and Finance approval. Such requests carry the
 * `requires_finance` flag and move pending → pending_finance → approved.
 */
class ProfileUpdateRequestService
{
    /** Whitelist of contact/address fields — single HR approval. */
    private const ALLOWED_FIELDS = [
        'mobile_number',
        'email',
        'street_address',
        'barangay',
        'city',
        'province',
        'zip_code',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
    ];

    /** Financial fields — require HR + Finance dual approval. */
    private const FINANCE_FIELDS = [
        'bank_name',
        'bank_account_no',
    ];

    /**
     * @param  array<string, string|null>  $changes
     */
    public function submit(Employee $employee, User $requester, array $changes, ?string $note = null): ProfileUpdateRequest
    {
        $filtered = $this->validatedChanges($changes);
        abort_if(empty($filtered), 422, 'No allowed fields provided.');

        $requiresFinance = (bool) array_intersect_key($filtered, array_flip(self::FINANCE_FIELDS));

        return DB::transaction(fn () => ProfileUpdateRequest::create([
            'employee_id'      => $employee->id,
            'requested_by'     => $requester->id,
            'status'           => ProfileUpdateStatus::Pending->value,
            'requires_finance' => $requiresFinance,
            'changes'          => $filtered,
            'note'             => $note,
        ]));
    }

    public function listForEmployee(Employee $employee): \Illuminate\Database\Eloquent\Collection
    {
        return ProfileUpdateRequest::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * HR-side review queue. Returns paginated list scoped by status.
     *
     * Row visibility is permission-derived (REC-11): a reviewer who holds the
     * view-all grant sees every request; otherwise they see only requests for
     * employees in their own department. The queue carries sensitive fields
     * (bank details), so it must never be a company-wide read for a
     * department-scoped `hr.employees.view` holder.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForReview(array $filters = [], ?User $user = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $signal = $filters['signal'] ?? null;
        if ($signal !== null && $signal !== 'awaiting_my_action') {
            throw ValidationException::withMessages(['signal' => ['Unsupported signal filter.']]);
        }

        $query = $signal === 'awaiting_my_action'
            ? $this->awaitingMyActionQuery($user)
            : ProfileUpdateRequest::query()->with(['employee.department', 'requester']);

        $financeOnly = $user?->hasPermission('hr.profile_updates.finance_review')
            && ! $user->hasPermission('hr.employees.view');

        if ($signal === null && $financeOnly) {
            // Finance sees its bank-change leg and completed bank requests,
            // without receiving contact changes or general employee access.
            $query->where('requires_finance', true)
                ->whereIn('status', [ProfileUpdateStatus::PendingFinance->value, ProfileUpdateStatus::Approved->value, ProfileUpdateStatus::Rejected->value]);
        } elseif ($signal === null) {
            DepartmentScope::apply(
                $query,
                $user,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'department_id',
                selfColumn: null,
                selfId: null,
                deptRelation: 'employee',
            );
        }

        $status = $filters['status'] ?? ($signal !== null ? null : ($financeOnly ? 'pending_finance' : 'pending'));
        if (in_array($status, ProfileUpdateStatus::values(), true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /** Shared query for the HR and Finance queues a user can act on now. */
    public function awaitingMyActionQuery(?User $user): Builder
    {
        $query = ProfileUpdateRequest::query()->with(['employee.department', 'requester']);
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $canReviewHr = $user->hasPermission('hr.employees.view') && $user->hasPermission('hr.employees.edit');
        $canReviewFinance = $user->hasPermission('hr.profile_updates.finance_review');
        if (! $canReviewHr && ! $canReviewFinance) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $queue) use ($user, $canReviewHr, $canReviewFinance): void {
            if ($canReviewHr) {
                $queue->where(function (Builder $hr) use ($user): void {
                    $hr->where('status', ProfileUpdateStatus::Pending->value)
                        ->where(fn (Builder $requests) => $requests->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id));
                    DepartmentScope::apply(
                        $hr,
                        $user,
                        viewAllPermission: 'hr.employees.view_sensitive',
                        departmentPermission: 'hr.employees.view',
                        deptColumn: 'department_id',
                        deptRelation: 'employee',
                    );
                });
            }

            if ($canReviewFinance) {
                $financeQueue = function (Builder $finance) use ($user): void {
                    $finance->where('status', ProfileUpdateStatus::PendingFinance->value)
                        ->where('requires_finance', true)
                        ->whereNotNull('reviewed_by')
                        ->whereNotNull('reviewed_at')
                        ->where('reviewed_by', '!=', $user->id)
                        ->where(fn (Builder $requests) => $requests->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id));
                };

                $canReviewHr ? $queue->orWhere($financeQueue) : $queue->where($financeQueue);
            }
        });
    }

    /**
     * HR approval. For non-financial requests this applies the changes and
     * closes the request. For bank changes it only clears the HR leg and
     * moves the request to `pending_finance` — Finance must approve next.
     */
    public function approve(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        $this->assertVisibleToReviewer($request, $reviewer);
        abort_unless($request->status === ProfileUpdateStatus::Pending->value, 422, 'Request is not awaiting HR review.');
        // REC-02 — a reviewer cannot approve a request they submitted.
        $this->assertNotSelfReviewing($request, $reviewer);

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            // Lock-then-guard: re-read so a concurrent reviewer cannot double-
            // apply the changes or act on a request another leg just closed.
            $locked = ProfileUpdateRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            abort_unless($locked->status === ProfileUpdateStatus::Pending->value, 422, 'Request is not awaiting HR review.');

            $locked->update([
                'reviewed_by'    => $reviewer->id,
                'reviewed_at'    => now(),
                'review_remarks' => $remarks,
            ]);

            if ($locked->requires_finance) {
                // Defer application until Finance signs off.
                $locked->update(['status' => ProfileUpdateStatus::PendingFinance->value]);
                return $locked->fresh(['employee', 'reviewer']);
            }

            $this->applyChanges($locked);
            $locked->update(['status' => ProfileUpdateStatus::Approved->value]);

            return $locked->fresh(['employee', 'reviewer']);
        });
    }

    /**
     * Finance approval — only valid for bank requests already HR-approved.
     */
    public function financeApprove(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        $this->assertVisibleToReviewer($request, $reviewer, true);
        abort_unless($request->requires_finance, 422, 'This request does not require Finance review.');
        abort_unless($request->status === ProfileUpdateStatus::PendingFinance->value, 422, 'Request is not awaiting Finance review.');
        // REC-02 — a Finance reviewer cannot approve a request they submitted.
        $this->assertNotSelfReviewing($request, $reviewer);

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $locked = ProfileUpdateRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            abort_unless($locked->requires_finance, 422, 'This request does not require Finance review.');
            abort_unless($locked->status === ProfileUpdateStatus::PendingFinance->value, 422, 'Request is not awaiting Finance review.');
            $this->assertNotSelfReviewing($locked, $reviewer);
            abort_unless(
                $locked->reviewed_by !== null && $locked->reviewed_at !== null,
                422,
                'Finance approval requires a recorded HR review.',
            );
            abort_unless(
                (int) $locked->reviewed_by !== (int) $reviewer->id,
                403,
                'Finance approval must be performed by a different reviewer than the HR approval (segregation of duties).',
            );

            $this->applyChanges($locked);

            $locked->update([
                'status'              => ProfileUpdateStatus::Approved->value,
                'finance_reviewed_by' => $reviewer->id,
                'finance_reviewed_at' => now(),
                'finance_remarks'     => $remarks,
            ]);

            return $locked->fresh(['employee', 'reviewer', 'financeReviewer']);
        });
    }

    public function reject(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        $this->assertVisibleToReviewer(
            $request,
            $reviewer,
            $request->status === ProfileUpdateStatus::PendingFinance->value,
        );
        $this->assertNotSelfReviewing($request, $reviewer);
        abort_unless(in_array($request->status, [ProfileUpdateStatus::Pending->value, ProfileUpdateStatus::PendingFinance->value], true), 422, 'Request is not pending.');

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            // Lock-then-guard: a stale reject must not flip a request another
            // reviewer already approved.
            $locked = ProfileUpdateRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            abort_unless(in_array($locked->status, [ProfileUpdateStatus::Pending->value, ProfileUpdateStatus::PendingFinance->value], true), 422, 'Request is not pending.');

            // Record the rejection on whichever leg is acting.
            $financeStage = $locked->status === ProfileUpdateStatus::PendingFinance->value;
            $locked->update($financeStage ? [
                'status'              => ProfileUpdateStatus::Rejected->value,
                'finance_reviewed_by' => $reviewer->id,
                'finance_reviewed_at' => now(),
                'finance_remarks'     => $remarks,
            ] : [
                'status'         => ProfileUpdateStatus::Rejected->value,
                'reviewed_by'    => $reviewer->id,
                'reviewed_at'    => now(),
                'review_remarks' => $remarks,
            ]);

            return $locked->fresh(['employee', 'reviewer', 'financeReviewer']);
        });
    }

    /** Block a reviewer from acting on a request they submitted, including admins and overrides. */
    private function assertNotSelfReviewing(ProfileUpdateRequest $request, User $reviewer): void
    {
        if ($request->requested_by === null || (int) $request->requested_by !== (int) $reviewer->id) {
            return; // different reviewer, or unknown submitter — allowed.
        }

        abort(403, 'You cannot review a profile-change request you submitted. A different reviewer must act (segregation of duties).');
    }

    private function assertVisibleToReviewer(ProfileUpdateRequest $request, User $reviewer, bool $finance = false): void
    {
        $query = ProfileUpdateRequest::query()->whereKey($request->getKey());

        DepartmentScope::apply(
            $query,
            $reviewer,
            viewAllPermission: $finance
                ? 'hr.profile_updates.finance_review'
                : 'hr.employees.view_sensitive',
            departmentPermission: $finance ? null : 'hr.employees.view',
            deptColumn: 'department_id',
            selfColumn: null,
            selfId: null,
            deptRelation: 'employee',
        );

        $query->firstOrFail();
    }

    /**
     * Write whitelisted changes to the employee row. Defensive: only fields
     * on the combined whitelist are applied, never blindly-trusted JSON keys.
     */
    private function applyChanges(ProfileUpdateRequest $request): void
    {
        /** @var Employee $employee */
        $employee = Employee::query()->whereKey($request->employee_id)->firstOrFail();

        $changes = $this->validatedChanges((array) $request->changes);

        if (! empty($changes)) {
            $employee->update($changes);
        }
    }

    /** @param array<string, mixed> $changes */
    private function validatedChanges(array $changes): array
    {
        $allowed = array_merge(self::ALLOWED_FIELDS, self::FINANCE_FIELDS);
        if (array_diff(array_keys($changes), $allowed) !== []) {
            throw ValidationException::withMessages([
                'changes' => ['One or more requested fields are not editable through self-service.'],
            ]);
        }

        return Validator::make($changes, [
            'mobile_number' => ['sometimes', 'nullable', 'digits:11', 'regex:/^09\d{9}$/'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'street_address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[0-9]{4,10}$/'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'digits_between:7,15'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_no' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\s]+$/'],
        ])->validate();
    }
}
