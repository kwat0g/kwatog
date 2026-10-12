<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WIDGETS = [
        'quality.ncr_aging' => ['NCR Aging', 'quality', 'quality.ncr.view', 'breakdown', '/quality/ncrs', 6],
        'quality.eightd_sla' => ['8D SLA Burn', 'quality', 'crm.complaints.view', 'breakdown', '/crm/complaints', 6],
        'executive.risks_opportunities' => ['Risks & Opportunities', 'platform', 'dashboard.company.view', 'table', '/alerts', 12],
        'executive.weekly_briefing' => ['Weekly Briefing', 'platform', 'dashboard.company.view', 'table', '/dashboard/company', 12],
    ];

    private const PERMISSIONS = [
        'quality.view' => 'View Quality',
        'quality.ncr.view' => 'View NCRs',
    ];

    private const LAYOUT_KEYS = [
        'qc.pareto',
        'quality.ncr_aging',
        'quality.eightd_sla',
        'executive.risks_opportunities',
        'executive.weekly_briefing',
    ];

    public function up(): void
    {
        foreach (self::WIDGETS as $key => [$name, $module, $permission, $kind, $link, $width]) {
            DB::table('dashboard_widgets')->insertOrIgnore([
                'key' => $key,
                'name' => $name,
                'description' => null,
                'module' => $module,
                'permission' => $permission,
                'permission_any' => null,
                'render_kind' => $kind,
                'link_path' => $link,
                'default_w' => $width,
                'default_h' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            $permissionIds[$slug] = DB::table('permissions')->where('slug', $slug)->value('id');
            if ($permissionIds[$slug] === null) {
                $permissionIds[$slug] = DB::table('permissions')->insertGetId([
                    'slug' => $slug,
                    'name' => $name,
                    'module' => 'quality',
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $vicePresidentId = DB::table('roles')->where('slug', 'vice_president')->value('id');
        if ($vicePresidentId === null) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $vicePresidentId,
                'permission_id' => $permissionId,
            ]);
        }
        Cache::forget("auth:role_perms:{$vicePresidentId}");

        foreach (self::LAYOUT_KEYS as $key) {
            $exists = DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $vicePresidentId)
                ->where('widget_key', $key)
                ->exists();
            if ($exists) {
                continue;
            }

            $lastRow = DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $vicePresidentId)
                ->max('position_y');
            $width = $key === 'qc.pareto' ? 6 : self::WIDGETS[$key][5];
            DB::table('dashboard_layouts')->insert([
                'owner_type' => 'role',
                'owner_id' => $vicePresidentId,
                'widget_key' => $key,
                'position_x' => 0,
                'position_y' => $lastRow === null ? 0 : (int) $lastRow + 1,
                'width' => $width,
                'height' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $vicePresidentId = DB::table('roles')->where('slug', 'vice_president')->value('id');
        if ($vicePresidentId !== null) {
            DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $vicePresidentId)
                ->whereIn('widget_key', self::LAYOUT_KEYS)
                ->delete();

            foreach (self::PERMISSIONS as $slug => $_name) {
                $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
                if ($permissionId !== null) {
                    DB::table('role_permissions')
                        ->where('role_id', $vicePresidentId)
                        ->where('permission_id', $permissionId)
                        ->delete();
                }
            }
            Cache::forget("auth:role_perms:{$vicePresidentId}");
        }

        DB::table('dashboard_widgets')->whereIn('key', array_keys(self::WIDGETS))->delete();
    }
};
