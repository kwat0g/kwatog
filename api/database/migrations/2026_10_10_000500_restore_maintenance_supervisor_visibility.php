<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WIDGETS = [
        'machine.status', 'maintenance.open_wos', 'maintenance.due_schedules',
        'assets.under_maintenance', 'approvals.pending',
        'self.payslip_summary', 'self.leave_balance',
        'self.dtr_today', 'self.pending_requests',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'maintenance_supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'mrp.machines.view')->value('id');
        if ($roleId === null || $permissionId === null) {
            return;
        }
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        Cache::forget("auth:role_perms:{$roleId}");

        if (DB::table('dashboard_layouts')->where('owner_type', 'role')->where('owner_id', $roleId)->exists()) {
            return;
        }
        $widths = DB::table('dashboard_widgets')->whereIn('key', self::WIDGETS)->pluck('default_w', 'key');
        $x = 0;
        $y = 0;
        foreach (self::WIDGETS as $key) {
            if (! $widths->has($key)) {
                continue;
            }
            $width = (int) $widths[$key];
            if ($x + $width > 12) {
                $x = 0;
                $y++;
            }
            DB::table('dashboard_layouts')->insert([
                'owner_type' => 'role', 'owner_id' => $roleId, 'widget_key' => $key,
                'position_x' => $x, 'position_y' => $y, 'width' => $width, 'height' => 4,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $x += $width;
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'maintenance_supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'mrp.machines.view')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        Cache::forget("auth:role_perms:{$roleId}");
        // Keep dashboard rows so rollback does not erase later layout customization.
    }
};
