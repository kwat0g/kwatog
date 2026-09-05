<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09 Action Center polish — two data cleanups that belong with the
 * approvals split (ActionCenterService no longer emits `approval:*` items and
 * the Exception Workbench surface is gone):
 *
 *  1. Purge `approval:*` task rows. Task state is shared globally (one row
 *     per item_key, visible to every role), and approval keys were gated only
 *     on the cross-cutting approvals.board.view — so any role could snooze or
 *     resolve pending approvals out of everyone's queue. The queue source is
 *     gone; leftover resolved/snoozed rows would only corrupt unrelated keys
 *     if the prefix were ever reintroduced. Events cascade via FK.
 *
 *  2. Drop the `dashboard.exceptions.view` permission. It gated a page that
 *     no longer exists (folded into /action-center, then de-duplicated) and
 *     was never enforced by any backend route. `dashboard.action_center.view`
 *     stays — the action-center routes are now gated on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('action_center_tasks')
            ->where('item_key', 'like', 'approval:%')
            ->delete();

        $permissionIds = DB::table('permissions')
            ->where('slug', 'dashboard.exceptions.view')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        // Task rows are not restorable; the approvals split is deliberate and
        // they belong to the Approval Queue now. Restore only the permission.
        if (DB::table('permissions')->where('slug', 'dashboard.exceptions.view')->doesntExist()) {
            DB::table('permissions')->insert([
                'slug' => 'dashboard.exceptions.view',
                'name' => 'View Exception Workbench',
                'module' => 'platform',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
