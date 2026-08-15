<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Enums\ProfileUpdateStatus;
use Illuminate\Support\Facades\DB;

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
    /**
     * REC-02 — SoD override. A reviewer may not approve a profile-change
     * request they submitted unless they hold this permission (system_admin
     * always does). Withheld from hr_officer via seeder `except`.
     */
    private const SELF_REVIEW_OVERRIDE_PERMISSION = 'hr.profile_updates.self_review_override';

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
        $allowed = array_merge(self::ALLOWED_FIELDS, self::FINANCE_FIELDS);
        $filtered = array_intersect_key($changes, array_flip($allowed));
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
     * @param  array<string, mixed>  $filters
     */
    public function listForReview(array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = ProfileUpdateRequest::query()
            ->with(['employee.department', 'requester']);

        $status = $filters['status'] ?? 'pending';
        if (in_array($status, ProfileUpdateStatus::values(), true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * HR approval. For non-financial requests this applies the changes and
     * closes the request. For bank changes it only clears the HR leg and
     * moves the request to `pending_finance` — Finance must approve next.
     */
    public function approve(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
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
        abort_unless($request->requires_finance, 422, 'This request does not require Finance review.');
        abort_unless($request->status === ProfileUpdateStatus::PendingFinance->value, 422, 'Request is not awaiting Finance review.');
        // REC-02 — a Finance reviewer cannot approve a request they submitted.
        $this->assertNotSelfReviewing($request, $reviewer);

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $locked = ProfileUpdateRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            abort_unless($locked->requires_finance, 422, 'This request does not require Finance review.');
            abort_unless($locked->status === ProfileUpdateStatus::PendingFinance->value, 422, 'Request is not awaiting Finance review.');

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

    /**
     * REC-02 — block a reviewer from acting on a request they submitted.
     * A null requested_by cannot trigger the guard.
     */
    private function assertNotSelfReviewing(ProfileUpdateRequest $request, User $reviewer): void
    {
        if ($request->requested_by === null || (int) $request->requested_by !== (int) $reviewer->id) {
            return; // different reviewer, or unknown submitter — allowed.
        }

        if ($reviewer->hasPermission(self::SELF_REVIEW_OVERRIDE_PERMISSION)) {
            return; // explicit override.
        }

        abort(403, 'You cannot review a profile-change request you submitted. A different reviewer must act (segregation of duties).');
    }

    /**
     * Write whitelisted changes to the employee row. Defensive: only fields
     * on the combined whitelist are applied, never blindly-trusted JSON keys.
     */
    private function applyChanges(ProfileUpdateRequest $request): void
    {
        /** @var Employee $employee */
        $employee = Employee::query()->whereKey($request->employee_id)->firstOrFail();

        $allowed = array_merge(self::ALLOWED_FIELDS, self::FINANCE_FIELDS);
        $changes = array_intersect_key((array) $request->changes, array_flip($allowed));

        if (! empty($changes)) {
            $employee->update($changes);
        }
    }
}
