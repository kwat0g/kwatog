<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflows = [
            [
                'workflow_type' => 'leave_request',
                'name'          => 'Leave Request Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
                    ['order' => 2, 'role' => 'hr_officer',      'label' => 'HR Officer'],
                ],
            ],
            [
                'workflow_type' => 'cash_advance',
                'name'          => 'Cash Advance Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head',  'label' => 'Department Head'],
                    ['order' => 2, 'role' => 'finance_officer',  'label' => 'Finance / Accounting'],
                    ['order' => 3, 'role' => 'system_admin',     'label' => 'VP / Approver'],
                ],
            ],
            [
                'workflow_type' => 'company_loan',
                'name'          => 'Company Loan Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head',    'label' => 'Department Head'],
                    ['order' => 2, 'role' => 'production_manager', 'label' => 'Manager'],
                    ['order' => 3, 'role' => 'finance_officer',    'label' => 'Finance / Accounting'],
                    ['order' => 4, 'role' => 'system_admin',       'label' => 'VP / Approver'],
                ],
            ],
            [
                'workflow_type'    => 'purchase_request',
                'name'             => 'Purchase Request Approval',
                'amount_threshold' => 50000.00,
                'steps' => [
                    ['order' => 1, 'role' => 'department_head',    'label' => 'Department Head'],
                    ['order' => 2, 'role' => 'production_manager', 'label' => 'Manager'],
                    ['order' => 3, 'role' => 'purchasing_officer', 'label' => 'Purchasing'],
                    ['order' => 4, 'role' => 'system_admin',       'label' => 'VP', 'threshold' => 50000.00],
                ],
            ],
            [
                'workflow_type'    => 'purchase_order',
                'name'             => 'Purchase Order Approval',
                'amount_threshold' => 50000.00,
                'steps' => [
                    ['order' => 1, 'role' => 'purchasing_officer', 'label' => 'Purchasing'],
                    ['order' => 2, 'role' => 'finance_officer',    'label' => 'Finance'],
                    ['order' => 3, 'role' => 'system_admin',       'label' => 'VP', 'threshold' => 50000.00],
                ],
            ],
            [
                'workflow_type' => 'payroll_period_finalize',
                'name'          => 'Payroll Period Finalization',
                'steps' => [
                    ['order' => 1, 'role' => 'hr_officer',      'label' => 'HR Officer'],
                    ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance Officer'],
                ],
            ],
            [
                'workflow_type' => 'bill_payment',
                'name'          => 'Bill Payment Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'finance_officer', 'label' => 'Finance Officer'],
                    ['order' => 2, 'role' => 'system_admin',    'label' => 'VP'],
                ],
            ],
            [
                'workflow_type' => 'journal_entry_post',
                'name'          => 'Journal Entry Posting',
                'steps' => [
                    ['order' => 1, 'role' => 'finance_officer', 'label' => 'Finance Officer'],
                ],
            ],
        ];

        foreach ($workflows as $w) {
            WorkflowDefinition::updateOrCreate(
                ['workflow_type' => $w['workflow_type']],
                [
                    'name'             => $w['name'],
                    'steps'            => $w['steps'],
                    'amount_threshold' => $w['amount_threshold'] ?? null,
                ],
            );
        }

        $this->command?->info('Workflow definitions seeded.');
    }
}
