<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_definitions')->where('workflow_type', 'overtime_request')->delete();
    }

    public function down(): void
    {
        $now = now();
        DB::table('workflow_definitions')->insert([
            'workflow_type' => 'overtime_request',
            'name' => 'Overtime Request Approval',
            'steps' => json_encode([
                ['order' => 1, 'role' => 'department_head', 'label' => 'Approved by'],
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
