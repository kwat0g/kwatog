<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            [
                'quality.copq.alert_notification_roles',
                ['qc_inspector', 'production_manager'],
                'COPQ Spike Notification Roles',
                'Active role slugs that receive COPQ spike alerts.',
            ],
            [
                'quality.spc.alert_notification_roles',
                ['qc_inspector', 'production_manager'],
                'SPC Alert Notification Roles',
                'Active role slugs that receive SPC control-chart alerts.',
            ],
        ] as [$key, $roles, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($roles),
                'group' => 'quality',
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
            'quality.copq.alert_notification_roles',
            'quality.spc.alert_notification_roles',
        ])->delete();
    }
};
