<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['production.work_order_completed.notification_roles', ['ppc_head', 'production_manager'], 'Completed Work Order Notification Roles', 'Roles notified when a production work order is completed.'],
            ['maintenance.machine_breakdown.notification_roles', ['maintenance_tech', 'production_manager'], 'Machine Breakdown Notification Roles', 'Roles notified when a machine breakdown is detected.'],
            ['crm.sales_order_confirmed.notification_roles', ['ppc_head', 'production_manager'], 'Confirmed Sales Order Notification Roles', 'Roles notified when a sales order is confirmed.'],
            ['hr.separation.notification_roles', ['hr_officer', 'finance_officer'], 'Separation Notification Roles', 'Roles notified when an employee separation is initiated.'],
        ] as [$key, $roles, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($roles),
                'group' => explode('.', $key, 2)[0],
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'production.work_order_completed.notification_roles',
            'maintenance.machine_breakdown.notification_roles',
            'crm.sales_order_confirmed.notification_roles',
            'hr.separation.notification_roles',
        ])->delete();
    }
};
