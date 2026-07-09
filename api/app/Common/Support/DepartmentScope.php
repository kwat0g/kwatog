<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * REC-11 — centralized, PERMISSION-DRIVEN row-level department scope.
 *
 * The old pattern hand-rolled `if ($roleSlug === 'department_head')` in each
 * list service — brittle (a new role with the same duty leaks; a forgotten
 * block leaks everything) and role-slug-coupled. This resolves visibility from
 * the user's GRANTS instead, in three tiers:
 *
 *   - Holds $viewAllPermission (e.g. hr.employees.view_sensitive): sees all rows.
 *   - Else holds $departmentPermission (e.g. hr.employees.view): sees their
 *     whole department, plus their own record via $selfColumn.
 *   - Else: sees only their own record (or nothing if $selfColumn is null).
 *
 * $departmentPermission = null means department visibility is not gated — any
 * non-view-all user is scoped to their department + self.
 *
 * Apply centrally in a list service:
 *   DepartmentScope::apply($query, $user,
 *       viewAllPermission: 'hr.employees.view_sensitive',
 *       departmentPermission: 'hr.employees.view',
 *       selfColumn: 'id', selfId: $user->employee_id);
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
final class DepartmentScope
{
    /**
     * @param Builder<TModel> $query               a query on a table with a department FK
     * @param User|null       $user                acting user (null = system path: no scoping)
     * @param string          $viewAllPermission   grant that lifts all scoping
     * @param string|null     $departmentPermission grant required to see the whole department (null = ungated)
     * @param string          $deptColumn          the department FK on the queried table
     * @param string|null     $selfColumn          column matching the user's own row (nullable)
     * @param int|null        $selfId              the user's own row id for $selfColumn
     * @return Builder<TModel>
     */
    public static function apply(
        Builder $query,
        ?User $user,
        string $viewAllPermission,
        ?string $departmentPermission = null,
        string $deptColumn = 'department_id',
        ?string $selfColumn = null,
        ?int $selfId = null,
    ): Builder {
        // No user context (console/system paths): do not scope. Callers that
        // need hard denial should not pass a null user.
        if (! $user) {
            return $query;
        }

        // Tier 1: a view-all grant lifts scoping entirely.
        if ($user->hasPermission($viewAllPermission)) {
            return $query;
        }

        // Tier 2: department visibility, only if the grant allows it.
        $canSeeDepartment = $departmentPermission === null
            || $user->hasPermission($departmentPermission);
        $deptId = $canSeeDepartment ? self::departmentIdFor($user) : null;

        return $query->where(function (Builder $q) use ($deptId, $deptColumn, $selfColumn, $selfId) {
            $matched = false;
            if ($deptId !== null) {
                $q->orWhere($deptColumn, $deptId);
                $matched = true;
            }
            if ($selfColumn !== null && $selfId !== null) {
                $q->orWhere($selfColumn, $selfId);
                $matched = true;
            }
            if (! $matched) {
                // No department reach and no self anchor: deny everything rather
                // than fall through to an unscoped (leaking) result.
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** The department the user belongs to, via their linked employee record. */
    public static function departmentIdFor(?User $user): ?int
    {
        if (! $user || ! $user->employee_id) {
            return null;
        }
        return \App\Modules\HR\Models\Employee::query()
            ->whereKey($user->employee_id)
            ->value('department_id');
    }
}
