<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateDefinition([
            ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
            ['order' => 2, 'role' => 'hr_officer', 'label' => 'HR Officer'],
            ['order' => 3, 'role' => 'finance_officer', 'label' => 'Finance / Accounting'],
            ['order' => 4, 'role' => 'vice_president', 'label' => 'VP / Approver', 'threshold' => '50000.00'],
        ]);
    }

    public function down(): void
    {
        $this->updateDefinition([
            ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
            ['order' => 2, 'role' => 'production_manager', 'label' => 'Manager'],
            ['order' => 3, 'role' => 'finance_officer', 'label' => 'Finance / Accounting'],
            ['order' => 4, 'role' => 'vice_president', 'label' => 'VP / Approver'],
        ]);
    }

    private function updateDefinition(array $steps): void
    {
        // Submitted requests retain their recorded workflow and approval history.
        DB::table('workflow_definitions')->where('workflow_type', 'company_loan')->update([
            'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
