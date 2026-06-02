<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
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
            'status'           => 'pending',
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
        if (in_array($status, ['pending', 'pending_finance', 'approved', 'rejected'], true)) {
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
        abort_unless($request->status === 'pending', 422, 'Request is not awaiting HR review.');

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request->update([
                'reviewed_by'    => $reviewer->id,
                'reviewed_at'    => now(),
                'review_remarks' => $remarks,
            ]);

            if ($request->requires_finance) {
                // Defer application until Finance signs off.
                $request->update(['status' => 'pending_finance']);
                return $request->fresh(['employee', 'reviewer']);
            }

            $this->applyChanges($request);
            $request->update(['status' => 'approved']);

            return $request->fresh(['employee', 'reviewer']);
        });
    }

    /**
     * Finance approval — only valid for bank requests already HR-approved.
     */
    public function financeApprove(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        abort_unless($request->requires_finance, 422, 'This request does not require Finance review.');
        abort_unless($request->status === 'pending_finance', 422, 'Request is not awaiting Finance review.');

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $this->applyChanges($request);

            $request->update([
                'status'              => 'approved',
                'finance_reviewed_by' => $reviewer->id,
                'finance_reviewed_at' => now(),
                'finance_remarks'     => $remarks,
            ]);

            return $request->fresh(['employee', 'reviewer', 'financeReviewer']);
        });
    }

    public function reject(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        abort_unless(in_array($request->status, ['pending', 'pending_finance'], true), 422, 'Request is not pending.');

        // Record the rejection on whichever leg is acting.
        $financeStage = $request->status === 'pending_finance';
        $request->update($financeStage ? [
            'status'              => 'rejected',
            'finance_reviewed_by' => $reviewer->id,
            'finance_reviewed_at' => now(),
            'finance_remarks'     => $remarks,
        ] : [
            'status'         => 'rejected',
            'reviewed_by'    => $reviewer->id,
            'reviewed_at'    => now(),
            'review_remarks' => $remarks,
        ]);

        return $request->fresh(['employee', 'reviewer', 'financeReviewer']);
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
