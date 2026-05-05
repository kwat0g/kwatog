<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Sprint P7 — field metadata for the audit-log diff renderer.
 *
 * Maps `{model_type, field_name}` to a `{label, type}` pair so the SPA can
 * show "Changed Monthly Salary from ₱18,000.00 to ₱20,000.00" instead of
 * raw JSON. Encrypted fields are flagged so values are masked in the diff.
 *
 * `model_type` is matched against the basename only (the part after the
 * final namespace separator) so callers don't need to know whether the
 * stored class is fully-qualified or not.
 */
final class AuditFieldLabels
{
    /**
     * @return array<string, array{label:string,type:string}>
     */
    public static function forModel(string $modelType): array
    {
        $key = self::basename($modelType);
        return self::map()[$key] ?? [];
    }

    /**
     * Get the meta for one field. Returns null if not registered — caller
     * should fall back to humanizing the snake_case key.
     *
     * @return array{label:string,type:string}|null
     */
    public static function field(string $modelType, string $field): ?array
    {
        return self::forModel($modelType)[$field] ?? null;
    }

    /**
     * Master registry. Keep alphabetized within each model so future edits
     * have an obvious diff. Unknown models fall back to humanized keys.
     *
     * Field types drive frontend formatting:
     *   - money     → ₱ prefix, 2 decimal places, thousands separator
     *   - date      → YYYY-MM-DD
     *   - datetime  → "Apr 6, 2026 09:15"
     *   - enum      → upper-case-first, replaces _ with spaces
     *   - boolean   → Yes/No
     *   - decimal   → tabular-nums, no prefix
     *   - encrypted → value masked client-side (only "(changed)" rendered)
     *   - text      → default; no special formatting
     *
     * @return array<string, array<string, array{label:string,type:string}>>
     */
    private static function map(): array
    {
        return [
            'Employee' => [
                'employee_no'           => ['label' => 'Employee No',        'type' => 'text'],
                'first_name'            => ['label' => 'First Name',         'type' => 'text'],
                'middle_name'           => ['label' => 'Middle Name',        'type' => 'text'],
                'last_name'             => ['label' => 'Last Name',          'type' => 'text'],
                'suffix'                => ['label' => 'Suffix',             'type' => 'text'],
                'department_id'         => ['label' => 'Department',         'type' => 'text'],
                'position_id'           => ['label' => 'Position',           'type' => 'text'],
                'status'                => ['label' => 'Status',             'type' => 'enum'],
                'employment_type'       => ['label' => 'Employment Type',    'type' => 'enum'],
                'pay_type'              => ['label' => 'Pay Type',           'type' => 'enum'],
                'basic_monthly_salary'  => ['label' => 'Monthly Salary',     'type' => 'money'],
                'daily_rate'            => ['label' => 'Daily Rate',         'type' => 'money'],
                'date_hired'            => ['label' => 'Date Hired',         'type' => 'date'],
                'date_regularized'      => ['label' => 'Date Regularized',   'type' => 'date'],
                'date_separated'        => ['label' => 'Date Separated',     'type' => 'date'],
                'sss_no'                => ['label' => 'SSS No',             'type' => 'encrypted'],
                'tin'                   => ['label' => 'TIN',                'type' => 'encrypted'],
                'philhealth_no'         => ['label' => 'PhilHealth No',      'type' => 'encrypted'],
                'pagibig_no'            => ['label' => 'Pag-IBIG No',        'type' => 'encrypted'],
                'bank_name'             => ['label' => 'Bank Name',          'type' => 'text'],
                'bank_account_no'       => ['label' => 'Bank Account No',    'type' => 'encrypted'],
                'mobile_number'         => ['label' => 'Mobile Number',      'type' => 'text'],
                'email'                 => ['label' => 'Email',              'type' => 'text'],
            ],
            'PayrollPeriod' => [
                'period_start'  => ['label' => 'Period Start', 'type' => 'date'],
                'period_end'    => ['label' => 'Period End',   'type' => 'date'],
                'pay_date'      => ['label' => 'Pay Date',     'type' => 'date'],
                'status'        => ['label' => 'Status',       'type' => 'enum'],
                'is_first_half' => ['label' => 'First Half',   'type' => 'boolean'],
            ],
            'Payroll' => [
                'gross_pay'            => ['label' => 'Gross Pay',            'type' => 'money'],
                'net_pay'              => ['label' => 'Net Pay',              'type' => 'money'],
                'sss_contribution'     => ['label' => 'SSS Contribution',     'type' => 'money'],
                'philhealth_contribution' => ['label' => 'PhilHealth Contribution', 'type' => 'money'],
                'pagibig_contribution' => ['label' => 'Pag-IBIG Contribution','type' => 'money'],
                'withholding_tax'      => ['label' => 'Withholding Tax',      'type' => 'money'],
                'overtime_pay'         => ['label' => 'Overtime Pay',         'type' => 'money'],
                'night_diff_pay'       => ['label' => 'Night Differential',   'type' => 'money'],
                'holiday_pay'          => ['label' => 'Holiday Pay',          'type' => 'money'],
                'days_present'         => ['label' => 'Days Present',         'type' => 'decimal'],
                'days_absent'          => ['label' => 'Days Absent',          'type' => 'decimal'],
            ],
            'JournalEntry' => [
                'entry_number' => ['label' => 'Entry Number', 'type' => 'text'],
                'date'         => ['label' => 'Date',         'type' => 'date'],
                'description'  => ['label' => 'Description',  'type' => 'text'],
                'status'       => ['label' => 'Status',       'type' => 'enum'],
                'total_debit'  => ['label' => 'Total Debit',  'type' => 'money'],
                'total_credit' => ['label' => 'Total Credit', 'type' => 'money'],
            ],
            'Invoice' => [
                'invoice_number' => ['label' => 'Invoice No',  'type' => 'text'],
                'status'         => ['label' => 'Status',      'type' => 'enum'],
                'date'           => ['label' => 'Date',        'type' => 'date'],
                'due_date'       => ['label' => 'Due Date',    'type' => 'date'],
                'subtotal'       => ['label' => 'Subtotal',    'type' => 'money'],
                'vat_amount'     => ['label' => 'VAT',         'type' => 'money'],
                'total_amount'   => ['label' => 'Total',       'type' => 'money'],
                'balance'        => ['label' => 'Balance',     'type' => 'money'],
            ],
            'Bill' => [
                'bill_number'  => ['label' => 'Bill No',     'type' => 'text'],
                'status'       => ['label' => 'Status',      'type' => 'enum'],
                'date'         => ['label' => 'Date',        'type' => 'date'],
                'due_date'     => ['label' => 'Due Date',    'type' => 'date'],
                'total_amount' => ['label' => 'Total',       'type' => 'money'],
                'balance'      => ['label' => 'Balance',     'type' => 'money'],
            ],
            'PurchaseOrder' => [
                'po_number'              => ['label' => 'PO No',              'type' => 'text'],
                'status'                 => ['label' => 'Status',             'type' => 'enum'],
                'date'                   => ['label' => 'Date',               'type' => 'date'],
                'expected_delivery_date' => ['label' => 'Expected Delivery',  'type' => 'date'],
                'subtotal'               => ['label' => 'Subtotal',           'type' => 'money'],
                'vat_amount'             => ['label' => 'VAT',                'type' => 'money'],
                'total_amount'           => ['label' => 'Total',              'type' => 'money'],
                'remarks'                => ['label' => 'Remarks',            'type' => 'text'],
            ],
            'PurchaseRequest' => [
                'pr_number'              => ['label' => 'PR No',          'type' => 'text'],
                'status'                 => ['label' => 'Status',         'type' => 'enum'],
                'priority'               => ['label' => 'Priority',       'type' => 'enum'],
                'date'                   => ['label' => 'Date',           'type' => 'date'],
                'reason'                 => ['label' => 'Reason',         'type' => 'text'],
                'is_auto_generated'      => ['label' => 'Auto-Generated', 'type' => 'boolean'],
                'total_estimated_amount' => ['label' => 'Estimated Total','type' => 'money'],
            ],
            'EmployeeLoan' => [
                'loan_no'              => ['label' => 'Loan No',              'type' => 'text'],
                'loan_type'            => ['label' => 'Loan Type',            'type' => 'enum'],
                'principal'            => ['label' => 'Principal',            'type' => 'money'],
                'interest_rate'        => ['label' => 'Interest Rate',        'type' => 'decimal'],
                'monthly_amortization' => ['label' => 'Monthly Amortization', 'type' => 'money'],
                'pay_periods_total'    => ['label' => 'Pay Periods (total)',  'type' => 'decimal'],
                'pay_periods_remaining'=> ['label' => 'Pay Periods (remaining)', 'type' => 'decimal'],
                'status'               => ['label' => 'Status',               'type' => 'enum'],
                'start_date'           => ['label' => 'Start Date',           'type' => 'date'],
                'end_date'             => ['label' => 'End Date',             'type' => 'date'],
            ],
            'LeaveRequest' => [
                'leave_request_no' => ['label' => 'Leave Request No', 'type' => 'text'],
                'start_date'       => ['label' => 'Start Date',       'type' => 'date'],
                'end_date'         => ['label' => 'End Date',         'type' => 'date'],
                'days'             => ['label' => 'Days',             'type' => 'decimal'],
                'status'           => ['label' => 'Status',           'type' => 'enum'],
                'rejection_reason' => ['label' => 'Rejection Reason', 'type' => 'text'],
            ],
            'WorkOrder' => [
                'wo_number'         => ['label' => 'WO No',           'type' => 'text'],
                'status'            => ['label' => 'Status',          'type' => 'enum'],
                'quantity_target'   => ['label' => 'Target Qty',      'type' => 'decimal'],
                'quantity_produced' => ['label' => 'Produced Qty',    'type' => 'decimal'],
                'quantity_good'     => ['label' => 'Good Qty',        'type' => 'decimal'],
                'quantity_rejected' => ['label' => 'Rejected Qty',    'type' => 'decimal'],
                'planned_start'     => ['label' => 'Planned Start',   'type' => 'datetime'],
                'planned_end'       => ['label' => 'Planned End',     'type' => 'datetime'],
                'actual_start'      => ['label' => 'Actual Start',    'type' => 'datetime'],
                'actual_end'        => ['label' => 'Actual End',      'type' => 'datetime'],
            ],
            'SalesOrder' => [
                'so_number'    => ['label' => 'SO No',     'type' => 'text'],
                'status'       => ['label' => 'Status',    'type' => 'enum'],
                'date'         => ['label' => 'Date',      'type' => 'date'],
                'subtotal'     => ['label' => 'Subtotal',  'type' => 'money'],
                'vat_amount'   => ['label' => 'VAT',       'type' => 'money'],
                'total_amount' => ['label' => 'Total',     'type' => 'money'],
            ],
        ];
    }

    private static function basename(string $type): string
    {
        $pos = strrpos($type, '\\');
        return $pos === false ? $type : substr($type, $pos + 1);
    }
}
