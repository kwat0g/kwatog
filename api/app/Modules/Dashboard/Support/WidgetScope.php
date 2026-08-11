<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a widget asks "whose data may this viewer see?".
 *
 * Widget scope used to be an inline lookup in DashboardWidgetDataService
 * repeated per call site. Concentrating it here means a widget states its
 * intent ("company-wide under X, else my department") in one readable line
 * instead of re-deriving the department each time.
 *
 * Deliberately scoped to widgets. The ad-hoc controller scoping — a literal
 * role-slug compare in LoanController, a permission proxy in
 * LeaveRequestController — is NOT unified here; that is a separate refactor
 * this class gives a home to grow into.
 */
final class WidgetScope
{
    /** The department of the user's linked employee, or null when unlinked. */
    public function departmentId(User $user): ?int
    {
        if (! $user->employee_id) {
            return null;
        }

        $id = DB::table('employees')
            ->where('id', (int) $user->employee_id)
            ->value('department_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether this viewer may see the whole company for the domain gated by
     * $permission. Delegates to hasPermission so the system_admin
     * short-circuit (User::hasPermission) is honoured — a plain in_array over
     * the cached slug array would wrongly scope admin down to a department.
     */
    public function isCompanyWide(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }
}
