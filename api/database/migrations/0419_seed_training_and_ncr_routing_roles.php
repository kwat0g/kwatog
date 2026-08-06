<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'hr.training_expiry.notification_roles',
                'value' => json_encode(['department_head', 'hr_officer']),
                'group' => 'hr',
                'label' => 'Training Expiry Notification Roles',
                'description' => 'Role slugs notified for employee training expiry alerts.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'quality.ncr.return_to_supplier.notification_roles',
                'value' => json_encode(['purchasing_officer']),
                'group' => 'quality',
                'label' => 'NCR Return-to-supplier Notification Roles',
                'description' => 'Role slugs notified when an NCR is closed with return-to-supplier disposition.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'hr.training_expiry.notification_roles',
            'quality.ncr.return_to_supplier.notification_roles',
        ])->delete();
    }
};
