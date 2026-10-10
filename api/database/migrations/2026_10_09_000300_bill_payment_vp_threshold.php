<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('workflow_definitions')->where('workflow_type', 'bill_payment')->update([
            'steps' => json_encode([
                ['order' => 1, 'role' => 'vice_president', 'label' => 'VP', 'threshold' => '50000.00'],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('workflow_definitions')->where('workflow_type', 'bill_payment')->update([
            'steps' => json_encode([
                ['order' => 1, 'role' => 'finance_officer', 'label' => 'Finance Officer'],
                ['order' => 2, 'role' => 'vice_president', 'label' => 'VP'],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
