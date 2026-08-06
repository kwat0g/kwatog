<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'alerts.critical.notification_roles',
            'value' => json_encode([
                'stock_critical' => ['warehouse_staff', 'purchasing_officer', 'ppc_head'],
                'stock_low' => ['warehouse_staff', 'purchasing_officer', 'ppc_head'],
                'no_supplier' => ['warehouse_staff', 'purchasing_officer', 'ppc_head'],
                'machine_breakdown' => ['production_manager', 'maintenance_tech'],
                'mold_shot_critical' => ['production_manager', 'maintenance_tech'],
                'mold_shot_limit' => ['production_manager', 'maintenance_tech'],
                'wo_overdue' => ['production_manager'],
                'oee_below_threshold' => ['production_manager'],
                'ar_overdue_30' => ['finance_officer'],
                'ar_overdue_60' => ['finance_officer'],
                'ap_due_soon' => ['finance_officer'],
                'qc_fail_rate_high' => ['qc_inspector', 'production_manager'],
            ]),
            'group' => 'alerts',
            'label' => 'Critical Alert Notification Roles',
            'description' => 'Role slugs that receive email fan-out for each critical alert type.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'alerts.critical.notification_roles')->delete();
    }
};
