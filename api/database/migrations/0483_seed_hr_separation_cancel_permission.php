<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-04 — separations initiated by mistake (rescinded resignation, mistyped
 * separation date) had no cancel path and could only move forward through
 * every signature to final pay. Add the cancellation permission and grant it
 * to hr_officer, the role holding the rest of the hr_separation module.
 */
return new class extends Migration
{
    private const SLUG = 'hr.separation.cancel';

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['slug' => self::SLUG],
            ['name' => 'Cancel Initiated Separation', 'module' => 'hr_separation', 'updated_at' => now(), 'created_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        $roleId = DB::table('roles')->where('slug', 'hr_officer')->value('id');
        if ($permissionId && $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
