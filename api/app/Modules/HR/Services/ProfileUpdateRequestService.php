<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use Illuminate\Support\Facades\DB;

/**
 * U3 — captures employee-initiated profile change requests.
 * Never auto-applies. HR reviews via the regular profile-update-request UI
 * (queue inbox) — backend is in place; an HR-side review screen is a
 * follow-up enhancement noted in the plan.
 */
class ProfileUpdateRequestService
{
    /** Whitelist of fields an employee may request to change. */
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

    /**
     * @param  array<string, string|null>  $changes
     */
    public function submit(Employee $employee, User $requester, array $changes, ?string $note = null): ProfileUpdateRequest
    {
        $filtered = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));
        abort_if(empty($filtered), 422, 'No allowed fields provided.');

        return DB::transaction(fn () => ProfileUpdateRequest::create([
            'employee_id'  => $employee->id,
            'requested_by' => $requester->id,
            'status'       => 'pending',
            'changes'      => $filtered,
            'note'         => $note,
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
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Approve a request: write each whitelisted change to the employee row.
     */
    public function approve(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        abort_unless($request->status === 'pending', 422, 'Request is not pending.');

        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($request->employee_id)->firstOrFail();
            $changes = (array) $request->changes;

            // Defensive: only apply whitelisted fields, never blindly trust the JSON.
            $allowed = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));
            if (! empty($allowed)) {
                $employee->update($allowed);
            }

            $request->update([
                'status'         => 'approved',
                'reviewed_by'    => $reviewer->id,
                'reviewed_at'    => now(),
                'review_remarks' => $remarks,
            ]);

            return $request->fresh(['employee', 'reviewer']);
        });
    }

    public function reject(ProfileUpdateRequest $request, User $reviewer, ?string $remarks = null): ProfileUpdateRequest
    {
        abort_unless($request->status === 'pending', 422, 'Request is not pending.');

        $request->update([
            'status'         => 'rejected',
            'reviewed_by'    => $reviewer->id,
            'reviewed_at'    => now(),
            'review_remarks' => $remarks,
        ]);

        return $request->fresh(['employee', 'reviewer']);
    }
}
