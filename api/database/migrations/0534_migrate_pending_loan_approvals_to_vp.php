<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('vice_president');
    }

    public function down(): void
    {
        $this->rewrite('system_admin');
    }

    private function rewrite(string $executiveRole): void
    {
        foreach ([
            'cash_advance' => [
                ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
                ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance / Accounting'],
                ['order' => 3, 'role' => $executiveRole, 'label' => 'VP / Approver'],
            ],
            'company_loan' => [
                ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
                ['order' => 2, 'role' => 'production_manager', 'label' => 'Manager'],
                ['order' => 3, 'role' => 'finance_officer', 'label' => 'Finance / Accounting'],
                ['order' => 4, 'role' => $executiveRole, 'label' => 'VP / Approver'],
            ],
        ] as $type => $steps) {
            $definition = DB::table('workflow_definitions')->where('workflow_type', $type)->first();
            if ($definition === null) {
                continue;
            }

            $snapshot = [
                'workflow_type' => $type,
                'name' => (string) $definition->name,
                'steps' => array_values($steps),
            ];
            $version = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

            DB::table('workflow_definitions')->where('id', $definition->id)->update([
                'steps' => json_encode(array_values($steps), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            $loanIds = DB::table('employee_loans')
                ->where('loan_type', $type)
                ->where('status', 'pending')
                ->pluck('id');
            if ($loanIds->isEmpty()) {
                continue;
            }

            $pending = DB::table('approval_records')
                ->where('approvable_type', 'App\\Modules\\Loans\\Models\\EmployeeLoan')
                ->whereIn('approvable_id', $loanIds)
                ->where('is_current', true)
                ->whereIn('action', ['pending', 'skipped'])
                ->get();

            foreach ($pending as $record) {
                $step = $steps[(int) $record->step_order - 1] ?? null;
                $attributes = $step === null
                    ? ['action' => 'superseded', 'is_current' => false]
                    : [
                        'role_slug' => $step['role'],
                        'workflow_definition_id' => $definition->id,
                        'workflow_version' => $version,
                        'workflow_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    ];
                DB::table('approval_records')->where('id', $record->id)->update($attributes);
            }
        }
    }
};
