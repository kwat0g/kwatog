<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('roles')->insertOrIgnore([
            'slug' => 'maintenance_supervisor',
            'name' => 'Maintenance Supervisor',
            'description' => 'Assigns maintenance technicians and manages preventive schedules without administrator access.',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = DB::table('roles')->where('slug', 'maintenance_supervisor')->value('id');

        foreach ([
            ['maintenance.view', 'View Maintenance'],
            ['maintenance.wo.create', 'Create Maintenance Work Order'],
            ['maintenance.wo.assign', 'Assign Maintenance Work Order'],
            ['maintenance.schedules.manage', 'Manage Maintenance Schedules'],
        ] as [$slug, $name]) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => $slug,
                'name' => $name,
                'module' => 'maintenance',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $slugs = [
            'maintenance.view', 'maintenance.wo.create', 'maintenance.wo.assign',
            'maintenance.schedules.manage', 'assets.view', 'search.global',
            'attendance.view', 'leave.view', 'leave.create', 'payroll.view',
            'notifications.view', 'notifications.preferences.manage',
            'calendar.view', 'approvals.board.view', 'hr.directory.view',
            'dashboard.action_center.view', 'dashboard.layout.reset', 'business_policies.view',
        ];
        foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
        Cache::forget("auth:role_perms:{$roleId}");
    }

    public function down(): void
    {
        // Preserve role assignments and grants if the application code rolls back.
    }
};
