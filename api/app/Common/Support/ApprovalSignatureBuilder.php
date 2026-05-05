<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Common\Models\ApprovalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Sprint P9 — build the approval-signature payload for printable forms.
 *
 * Produces the exact array shape consumed by the
 * `pdf._components.approval_signatures` blade partial:
 *
 *   [
 *     ['role' => 'Prepared by', 'name' => 'Juan Cruz',  'signed_at' => 'Apr 20, 2026'],
 *     ['role' => 'Noted by',    'name' => 'Maria Reyes','signed_at' => 'Apr 20, 2026'],
 *     ['role' => 'Checked by',  'name' => null,         'signed_at' => null], // pending
 *     ['role' => 'Approved by', 'name' => null,         'signed_at' => null],
 *   ]
 *
 * Source data:
 *   - "Prepared by" comes from the model's creator/requester relation if
 *     present, otherwise blank.
 *   - One signature row per `ApprovalRecord` step in the model's
 *     `approvalRecords` relation, mapped from `role_slug` → human label.
 *   - Pending steps emit a row with `name` and `signed_at` set to null so
 *     the partial leaves the line blank for a physical signature.
 *
 * Usage:
 *   $approvals = ApprovalSignatureBuilder::for($po, preparer: $po->creator);
 *   Pdf::loadView('pdf.purchase-order', compact('po', 'company', 'approvals'));
 */
final class ApprovalSignatureBuilder
{
    /** Friendly labels for the role_slug column on `approval_records`. */
    private const ROLE_LABELS = [
        'dept_head'          => 'Noted by',
        'department_head'    => 'Noted by',
        'manager'            => 'Checked by',
        'finance_manager'    => 'Checked by',
        'plant_manager'      => 'Checked by',
        'production_manager' => 'Checked by',
        'purchasing_officer' => 'Reviewed by',
        'finance_officer'    => 'Reviewed by',
        'accounting_officer' => 'Reviewed by',
        'hr_officer'         => 'Reviewed by',
        'vp'                 => 'Approved by',
        'vice_president'     => 'Approved by',
        'general_manager'    => 'Approved by',
    ];

    /**
     * Build the array for a model.
     *
     * @param  Model        $model     Any model that exposes an `approvalRecords` relation
     *                                 (e.g. PurchaseRequest, PurchaseOrder, EmployeeLoan, LeaveRequest).
     *                                 Models without that relation render only the "Prepared by" row.
     * @param  Model|null   $preparer  Optional preparer/creator (e.g. `$po->creator`).
     *                                 Falls back to the model's `creator`, `requester`, or `employee`.
     * @return array<int, array{role:string,name:?string,signed_at:?string}>
     */
    public static function for(Model $model, ?Model $preparer = null): array
    {
        $rows = [];

        $preparer ??= self::guessPreparer($model);
        $createdAt = $model->created_at ?? null;

        $rows[] = [
            'role'      => 'Prepared by',
            'name'      => self::nameOf($preparer),
            'signed_at' => self::formatDate($createdAt),
        ];

        $records = self::approvalRecords($model);
        foreach ($records as $record) {
            $label = self::ROLE_LABELS[$record->role_slug]
                ?? self::humanizeRoleSlug((string) $record->role_slug);

            $isApproved = $record->action === 'approved';
            $rows[] = [
                'role'      => $label,
                'name'      => $isApproved ? self::nameOf($record->approver) : null,
                'signed_at' => $isApproved ? self::formatDate($record->acted_at) : null,
            ];
        }

        // If the model had no ApprovalRecord rows at all, emit a generic
        // "Approved by" placeholder so a printed form still has the
        // required final-approval signature line.
        if (count($rows) === 1) {
            $rows[] = ['role' => 'Approved by', 'name' => null, 'signed_at' => null];
        }

        return $rows;
    }

    /** @return Collection<int, ApprovalRecord> */
    private static function approvalRecords(Model $model): Collection
    {
        if (! method_exists($model, 'approvalRecords')) {
            return collect();
        }
        // Eager-load approver:id,name (and the records themselves) lazily so
        // callers don't have to remember to do it.
        $model->loadMissing(['approvalRecords.approver:id,name']);
        $records = $model->approvalRecords;
        return $records instanceof Collection ? $records : collect($records);
    }

    private static function guessPreparer(Model $model): ?Model
    {
        foreach (['creator', 'requester', 'employee', 'createdBy'] as $relation) {
            if (method_exists($model, $relation)) {
                $value = $model->{$relation};
                if ($value instanceof Model) return $value;
            }
        }
        return null;
    }

    private static function nameOf(?Model $user): ?string
    {
        if ($user === null) return null;
        // User model has `name`; Employee has `full_name` accessor.
        if (! empty($user->full_name)) return (string) $user->full_name;
        if (! empty($user->name)) return (string) $user->name;
        return null;
    }

    private static function formatDate(mixed $date): ?string
    {
        if ($date === null || $date === '') return null;
        // Carbon instance or parseable string.
        try {
            $carbon = $date instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($date)
                : \Illuminate\Support\Carbon::parse($date);
            return $carbon->format('M d, Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function humanizeRoleSlug(string $slug): string
    {
        $cleaned = trim(str_replace('_', ' ', $slug));
        return $cleaned === '' ? '' : ucfirst($cleaned);
    }
}
