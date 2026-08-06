<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.separation.clearance_checklist',
            'value' => json_encode([
                ['department' => 'Production', 'item_key' => 'tools_returned', 'label' => 'Tools returned'],
                ['department' => 'Production', 'item_key' => 'ppe_returned', 'label' => 'PPE returned'],
                ['department' => 'Warehouse', 'item_key' => 'materials_returned', 'label' => 'Materials returned'],
                ['department' => 'Maintenance', 'item_key' => 'no_pending_work', 'label' => 'No pending maintenance work'],
                ['department' => 'Finance', 'item_key' => 'no_outstanding_ca', 'label' => 'No outstanding cash advance'],
                ['department' => 'Finance', 'item_key' => 'no_outstanding_loan', 'label' => 'No outstanding company loan'],
                ['department' => 'HR', 'item_key' => 'id_returned', 'label' => 'Company ID returned'],
                ['department' => 'HR', 'item_key' => 'file_201_complete', 'label' => '201 file complete'],
                ['department' => 'HR', 'item_key' => 'exit_interview_done', 'label' => 'Exit interview done'],
                ['department' => 'IT', 'item_key' => 'equipment_returned', 'label' => 'IT equipment returned'],
                ['department' => 'IT', 'item_key' => 'accounts_disabled', 'label' => 'System accounts disabled'],
            ]),
            'group' => 'hr',
            'label' => 'Separation Clearance Checklist',
            'description' => 'Checklist template applied to newly initiated employee separations.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.separation.clearance_checklist')->delete();
    }
};
