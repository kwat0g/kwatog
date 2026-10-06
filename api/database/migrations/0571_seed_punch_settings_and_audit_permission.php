<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        ['company.punch_radius_m', 200, 'company', 'Time in/out radius (m)', 'Distance from the plant centre within which an employee may time in/out.'],
        ['company.punch_max_accuracy_m', 100, 'company', 'Time in/out max GPS error (m)', 'Worst GPS accuracy accepted for a time in/out.'],
        ['attendance.punch_early_minutes', 120, 'attendance', 'Punch window: minutes before shift', 'How early before shift start an employee may time in.'],
        ['attendance.punch_late_minutes', 240, 'attendance', 'Punch window: minutes after shift', 'How late after shift end an employee may still time out.'],
    ];

    private const SLUG = 'attendance.punch.audit';

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => self::SLUG],
            ['name' => 'View Time In/Out Location Audit', 'module' => 'attendance', 'updated_at' => now(), 'created_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['hr_officer', 'system_admin'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
