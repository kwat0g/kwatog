<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('workflow_definitions')->updateOrInsert(
            ['workflow_type' => 'finance_only_return_request'],
            [
                'name' => 'Finance-Only Return Approval',
                'steps' => json_encode([
                    ['order' => 1, 'role' => 'finance_officer', 'label' => 'Finance approval'],
                ], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['return_management.view', 'return_management.approve'])
            ->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_definitions')
            ->where('workflow_type', 'finance_only_return_request')
            ->delete();

        $roleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($roleId === null) {
            return;
        }
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['return_management.view', 'return_management.approve'])
            ->pluck('id');
        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
