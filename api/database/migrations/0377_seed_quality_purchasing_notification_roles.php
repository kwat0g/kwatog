<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['purchasing.supplier_score.notification_roles', ['purchasing_officer'], 'Supplier Deterioration Notification Roles', 'Roles notified when supplier performance deteriorates.'],
            ['quality.incoming_qc.notification_roles', ['qc_inspector'], 'Incoming QC Notification Roles', 'Roles notified when incoming QC is required.'],
            ['quality.grn_qc_failure.actor_roles', ['system_admin'], 'GRN QC Failure Actor Roles', 'Roles eligible to attribute automatic GRN rejection after QC failure.'],
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
            'purchasing.supplier_score.notification_roles',
            'quality.incoming_qc.notification_roles',
            'quality.grn_qc_failure.actor_roles',
        ])->delete();
    }
};
