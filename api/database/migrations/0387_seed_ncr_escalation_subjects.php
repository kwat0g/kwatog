<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.ncr.escalation_subjects',
            'value' => json_encode([
                'NCR awaiting corrective',
                'NCR overdue — manager attention',
                'NCR critical overdue — exec escalation',
            ]),
            'group' => 'quality',
            'label' => 'NCR Escalation Subjects',
            'description' => 'Notification subjects used for the three NCR escalation tiers.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.ncr.escalation_subjects')->delete();
    }
};
