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
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Noted by'],
                    ['order' => 2, 'role' => 'hr_officer',      'label' => 'Approved by'],
                ],
            ],
            [
                'workflow_type' => 'overtime_request',
                'name'          => 'Overtime Request Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Approved by'],
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
                'workflow_type' => 'payroll',
                'name'          => 'Payroll Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'hr_officer',      'label' => 'Reviewed by'],
                    ['order' => 2, 'role' => 'finance_officer', 'label' => 'Confirmed by'],
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
                'workflow_type' => 'salary_adjustment',
                'name'          => 'Salary Adjustment Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'production_manager', 'label' => 'Checked by'],
                    ['order' => 2, 'role' => 'system_admin',       'label' => 'Approved by'],
                ],
            ],
            [
                'workflow_type' => 'department_transfer',
                'name'          => 'Department Transfer Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Old Dept Head'],
                    ['order' => 2, 'role' => 'department_head', 'label' => 'New Dept Head'],
                ],
            ],
            [
                'workflow_type' => 'work_order',
                'name'          => 'Work Order Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'production_manager', 'label' => 'Approved by'],
                ],
            ],
            [
                'workflow_type' => 'ncr',
                'name'          => 'NCR Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'qc_inspector',  'label' => 'Reviewed by'],
                    ['order' => 2, 'role' => 'system_admin',  'label' => 'Approved by'],
                ],
            ],
            [
                'workflow_type' => 'maintenance_request',
                'name'          => 'Maintenance Request Assignment',
                'steps' => [
                    ['order' => 1, 'role' => 'maintenance_tech', 'label' => 'Assigned by'],
                ],
            ],
            [
                'workflow_type' => 'asset_disposal',
                'name'          => 'Asset Disposal Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Noted by'],
                    ['order' => 2, 'role' => 'production_manager', 'label' => 'Checked by'],
                    ['order' => 3, 'role' => 'finance_officer', 'label' => 'Reviewed by'],
                    ['order' => 4, 'role' => 'system_admin', 'label' => 'Approved by'],
                ],
            ],
            [
                'workflow_type' => 'separation_clearance',
                'name'          => 'Separation Clearance',
                'steps' => [
                    ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
                    ['order' => 2, 'role' => 'warehouse_staff', 'label' => 'Warehouse'],
                    ['order' => 3, 'role' => 'maintenance_tech', 'label' => 'Maintenance'],
                    ['order' => 4, 'role' => 'finance_officer', 'label' => 'Finance'],
                    ['order' => 5, 'role' => 'hr_officer', 'label' => 'HR'],
                ],
            ],
            [
                'workflow_type' => '8d_report',
                'name'          => '8D Report Approval',
                'steps' => [
                    ['order' => 1, 'role' => 'qc_inspector', 'label' => 'Reviewed by'],
                    ['order' => 2, 'role' => 'system_admin', 'label' => 'Approved by'],
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
