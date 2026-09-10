<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-10 approval-chain audit — three seeded chains named a step role that
 * did not hold the route permission the act endpoint requires, so the chain
 * could never advance past that step (the PR-chain PS-01 stall class, found in
 * three more workflows):
 *
 *   - cash_advance  step 3 (vice_president)     → loans.approve
 *   - company_loan  step 2 (production_manager) → loans.approve
 *   - company_loan  step 4 (vice_president)     → loans.approve
 *   - salary_adjustment step 2 (vice_president) → hr.salary_adjustments.act
 *
 * Route permissions alone are not enough — chain participants also need row
 * visibility (LoanAccessPolicy now has a chain-participant branch) and board
 * coverage (ApprovalTypeRegistry now carries salary_adjustment and
 * return_request). Those live in code, not the database; this migration only
 * backfills the missing permission grants for existing databases. Fresh
 * installs get them from RolePermissionSeeder.
 */
return new class extends Migration
{
    private const GRANTS = [
        'vice_president' => ['loans.view', 'loans.approve', 'hr.salary_adjustments.view', 'hr.salary_adjustments.act'],
        'production_manager' => ['loans.view', 'loans.approve'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach ($permissionSlugs as $slug) {
                $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
                if ($permissionId === null) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('slug', $permissionSlugs)
                ->pluck('id');

            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }
};
