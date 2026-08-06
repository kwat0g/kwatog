<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['hr.recruitment.notification_roles', ['hr_officer', 'system_admin'], 'Recruitment Notification Roles', 'Roles notified when a new job application is received.'],
            ['quality.outgoing_qc_delivery.notification_roles', ['impex_officer', 'warehouse_staff'], 'Outgoing QC Delivery Roles', 'Roles notified when outgoing QC passes and a delivery draft is created.'],
            ['quality.outgoing_qc.notification_roles', ['qc_inspector', 'production_manager'], 'Outgoing QC Notification Roles', 'Roles notified when an outgoing QC inspection is required.'],
            ['quality.in_process_qc.notification_roles', ['qc_inspector', 'production_manager'], 'In-process QC Notification Roles', 'Roles notified when an in-process QC inspection is required.'],
            ['quality.inspection_failed.notification_roles', ['production_manager', 'qc_inspector'], 'Inspection Failure Notification Roles', 'Roles notified when a quality inspection fails.'],
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
            'hr.recruitment.notification_roles',
            'quality.outgoing_qc_delivery.notification_roles',
            'quality.outgoing_qc.notification_roles',
            'quality.in_process_qc.notification_roles',
            'quality.inspection_failed.notification_roles',
        ])->delete();
    }
};
