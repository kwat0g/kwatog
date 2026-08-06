<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'approvals.escalation.superior_role_map',
            'value' => json_encode([
                'department_head' => 'production_manager',
                'production_manager' => 'system_admin',
                'purchasing_officer' => 'system_admin',
                'finance_officer' => 'system_admin',
                'hr_officer' => 'system_admin',
                'ppc_head' => 'system_admin',
            ]),
            'group' => 'approval',
            'label' => 'Approval Escalation Superior Roles',
            'description' => 'Role-to-role escalation map used when an approval SLA is breached.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'approvals.escalation.superior_role_map')->delete();
    }
};
