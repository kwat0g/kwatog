<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['slug' => 'payroll.periods.bank_file', 'name' => 'Manage Payroll Bank Files'],
        ['slug' => 'payroll.periods.disburse', 'name' => 'Record Payroll Disbursement'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'module' => 'payroll', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $financeId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($financeId === null) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $financeId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))
            ->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
