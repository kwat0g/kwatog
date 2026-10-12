<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WIDGET_KEY = 'chain.recovery_backlog';
    private const RECOVERY_VIEW = 'dashboard.chain_recovery.view';

    public function up(): void
    {
        if (! DB::table('dashboard_widgets')->where('key', self::WIDGET_KEY)->exists()) {
            DB::table('dashboard_widgets')->insert([
                'key' => self::WIDGET_KEY,
                'name' => 'Chain Recovery Backlog',
                'description' => null,
                'module' => 'platform',
                'permission' => self::RECOVERY_VIEW,
                'permission_any' => null,
                'render_kind' => 'scalar',
                'link_path' => '/chains/recovery',
                'default_w' => 4,
                'default_h' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionId = DB::table('permissions')->where('slug', self::RECOVERY_VIEW)->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'slug' => self::RECOVERY_VIEW,
                'name' => 'View Chain Listener Recovery Runs',
                'module' => 'dashboards',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $vicePresidentId = DB::table('roles')->where('slug', 'vice_president')->value('id');
        if ($vicePresidentId === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $vicePresidentId,
            'permission_id' => $permissionId,
        ]);
        Cache::forget("auth:role_perms:{$vicePresidentId}");

        $hasDefault = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->where('owner_id', $vicePresidentId)
            ->where('widget_key', self::WIDGET_KEY)
            ->exists();
        if (! $hasDefault) {
            $lastRow = DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $vicePresidentId)
                ->max('position_y');

            DB::table('dashboard_layouts')->insert([
                'owner_type' => 'role',
                'owner_id' => $vicePresidentId,
                'widget_key' => self::WIDGET_KEY,
                'position_x' => 0,
                'position_y' => $lastRow === null ? 0 : (int) $lastRow + 1,
                'width' => 4,
                'height' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('dashboard_widgets')->where('key', self::WIDGET_KEY)->delete();

        $vicePresidentId = DB::table('roles')->where('slug', 'vice_president')->value('id');
        $permissionId = DB::table('permissions')->where('slug', self::RECOVERY_VIEW)->value('id');

        if ($vicePresidentId === null || $permissionId === null) {
            return;
        }

        DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->where('owner_id', $vicePresidentId)
            ->where('widget_key', self::WIDGET_KEY)
            ->delete();
        DB::table('role_permissions')
            ->where('role_id', $vicePresidentId)
            ->where('permission_id', $permissionId)
            ->delete();
        Cache::forget("auth:role_perms:{$vicePresidentId}");
    }
};
