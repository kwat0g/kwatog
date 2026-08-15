<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'accounting.bills.exception_approve';

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['slug' => self::SLUG],
            ['name' => 'Approve Service Bill Exceptions', 'module' => 'accounting', 'updated_at' => now(), 'created_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        $roleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($permissionId && $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
