<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'crm.complaint_8d.escalation_subjects',
            'value' => json_encode([
                'd3' => '8D D3 containment overdue',
                'd4' => '8D D4 root cause overdue',
                'finalize' => '8D finalisation overdue',
            ]),
            'group' => 'crm',
            'label' => 'Complaint 8D Escalation Subjects',
            'description' => 'Notification subjects used when complaint 8D milestones become overdue.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'crm.complaint_8d.escalation_subjects')->delete();
    }
};
