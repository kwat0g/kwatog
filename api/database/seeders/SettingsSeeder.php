<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);

        // Company identity is deployment data, not application source code.
        // Keep bootstrap settings empty unless the operator supplies them via
        // environment/import/admin settings.
        $envString = static fn (string $key): string => trim((string) env($key));
        $envFloat = static function (string $key): ?float {
            $value = env($key);
            return is_numeric($value) ? (float) $value : null;
        };
        $company = [
            'legal_name' => $envString('COMPANY_LEGAL_NAME'),
            'address' => $envString('COMPANY_ADDRESS'),
            'tin' => $envString('COMPANY_TIN'),
            'phone' => $envString('COMPANY_PHONE'),
            'email' => $envString('COMPANY_EMAIL'),
            'sales_inbox_email' => $envString('COMPANY_SALES_INBOX_EMAIL'),
            'vat_status' => $envString('COMPANY_VAT_STATUS'),
            'logo_path' => $envString('COMPANY_LOGO_PATH'),
            'certification' => $envString('COMPANY_CERTIFICATION'),
            'public_url' => $envString('COMPANY_PUBLIC_URL'),
            'latitude' => $envFloat('COMPANY_LATITUDE'),
            'longitude' => $envFloat('COMPANY_LONGITUDE'),
        ];

        $rows = [
            // ── Company ──────────────────────────────────────
            [
                'key'         => 'company.legal_name',
                'value'       => $company['legal_name'],
                'group'       => 'company',
                'label'       => 'Company Name',
                'description' => 'Legal entity name shown on invoices, reports, and official documents.',
            ],
            [
                'key'         => 'company.address',
                'value'       => $company['address'],
                'group'       => 'company',
                'label'       => 'Company Address',
                'description' => 'Registered office address printed on official documents.',
            ],
            [
                'key'         => 'company.tin',
                'value'       => $company['tin'],
                'group'       => 'company',
                'label'       => 'Tax Identification Number',
                'description' => 'TIN used on BIR forms, invoices, and withholding certificates.',
            ],
            ['key' => 'company.phone', 'value' => $company['phone'], 'group' => 'company', 'label' => 'Company Phone', 'description' => 'Company telephone shown on official documents.'],
            ['key' => 'company.email', 'value' => $company['email'], 'group' => 'company', 'label' => 'Company Email', 'description' => 'Company email shown on official documents.'],
            ['key' => 'company.sales_inbox_email', 'value' => $company['sales_inbox_email'], 'group' => 'company', 'label' => 'Sales Inbox Email', 'description' => 'Target email address for RFQ quotes and sales inquiries.'],
            ['key' => 'company.vat_status', 'value' => $company['vat_status'], 'group' => 'company', 'label' => 'VAT Status', 'description' => 'Tax registration status shown on official documents.'],
            ['key' => 'company.logo_path', 'value' => $company['logo_path'], 'group' => 'company', 'label' => 'Company Logo Path', 'description' => 'Stored logo path used by generated documents.'],
            ['key' => 'company.certification', 'value' => $company['certification'], 'group' => 'company', 'label' => 'Company Certification', 'description' => 'Certification label shown on official documents when configured.'],
            ['key' => 'company.public_url', 'value' => $company['public_url'], 'group' => 'company', 'label' => 'Company Public URL', 'description' => 'Public website URL shown on official documents.'],
            ['key' => 'company.latitude', 'value' => $company['latitude'], 'group' => 'company', 'label' => 'Facility Latitude', 'description' => 'GPS latitude coordinate of the primary manufacturing plant.'],
            ['key' => 'company.longitude', 'value' => $company['longitude'], 'group' => 'company', 'label' => 'Facility Longitude', 'description' => 'GPS longitude coordinate of the primary manufacturing plant.'],
            ['key' => 'pdf.footer_disclaimer', 'value' => $envString('PDF_FOOTER_DISCLAIMER'), 'group' => 'company', 'label' => 'PDF Footer Disclaimer', 'description' => 'Footer disclaimer shown on generated PDF documents.'],

            // ── Fiscal ───────────────────────────────────────
            [
                'key'         => 'fiscal.year_start_month',
                'value'       => 1,
                'group'       => 'fiscal',
                'label'       => 'Fiscal Year Start Month',
                'description' => 'Month number (1–12) when your fiscal year begins.',
            ],

            // ── Payroll ──────────────────────────────────────
            [
                'key'         => 'payroll.schedule',
                'value'       => 'semi_monthly',
                'group'       => 'payroll',
                'label'       => 'Payroll Schedule',
                'description' => 'Pay frequency — semi-monthly, monthly, or weekly.',
            ],
            [
                'key'         => 'payroll.cutoff.first_half',
                'value'       => 15,
                'group'       => 'payroll',
                'label'       => 'First Half Cutoff Day',
                'description' => 'Day of the month for the first payroll cutoff.',
            ],
            [
                'key'         => 'payroll.cutoff.second_half',
                'value'       => 31,
                'group'       => 'payroll',
                'label'       => 'Second Half Cutoff Day',
                'description' => 'Day of the month for the second payroll cutoff.',
            ],
            [
                'key'         => 'payroll.payslip_email.enabled',
                'value'       => true,
                'group'       => 'payroll',
                'label'       => 'Email Payslips',
                'description' => 'Automatically email payslip PDFs to employees after payroll finalization.',
            ],

            // ── Approvals ────────────────────────────────────
            [
                'key'         => 'approval.po.vp_threshold',
                'value'       => 50000,
                'group'       => 'approval',
                'label'       => 'VP Approval Threshold (₱)',
                'description' => 'Purchase order amounts above this require VP-level approval.',
            ],
            [
                'key'         => 'approvals.auto_resolve.enabled',
                'value'       => false,
                'group'       => 'approval',
                'label'       => 'Auto-resolve Stale Approvals',
                'description' => 'Automatically approve or reject pending approvals after the SLA deadline.',
            ],
            [
                'key'         => 'approvals.auto_resolve.default_hours',
                'value'       => 72,
                'group'       => 'approval',
                'label'       => 'Auto-resolve Deadline (hours)',
                'description' => 'Hours after submission before a pending approval is auto-resolved.',
            ],
            [
                'key'         => 'approvals.auto_resolve.default_action',
                'value'       => 'reject',
                'group'       => 'approval',
                'label'       => 'Auto-resolve Action',
                'description' => 'Action taken when the auto-resolve deadline passes — approve or reject.',
            ],
            ['key' => 'approvals.reminder_hours', 'value' => 24, 'group' => 'approval', 'label' => 'Approval Reminder Hours', 'description' => 'Hours a pending approval may wait before its first reminder.'],
            ['key' => 'approvals.escalation_hours', 'value' => 48, 'group' => 'approval', 'label' => 'Approval Escalation Hours', 'description' => 'Hours a pending approval may wait before escalation.'],

            // ── Operational alerts ───────────────────────────
            ['key' => 'alerts.dedup_window_hours', 'value' => 24, 'group' => 'alerts', 'label' => 'Alert Deduplication Window', 'description' => 'Hours during which the same undismissed entity alert is not duplicated.'],
            ['key' => 'alerts.mold.warning_ratio', 'value' => 0.80, 'group' => 'alerts', 'label' => 'Mold Shot Warning Ratio', 'description' => 'Share of the mold shot limit that raises a warning.'],
            ['key' => 'alerts.mold.critical_ratio', 'value' => 0.95, 'group' => 'alerts', 'label' => 'Mold Shot Critical Ratio', 'description' => 'Share of the mold shot limit that raises a critical alert.'],
            ['key' => 'alerts.oee.quality_rate_threshold', 'value' => 0.75, 'group' => 'alerts', 'label' => 'OEE Quality Alert Threshold', 'description' => 'Quality rate below which a machine alert is raised.'],
            ['key' => 'alerts.oee.lookback_days', 'value' => 3, 'group' => 'alerts', 'label' => 'OEE Alert Lookback Days', 'description' => 'Production history window used by the machine quality alert.'],
            ['key' => 'alerts.oee.minimum_output_count', 'value' => 100, 'group' => 'alerts', 'label' => 'OEE Minimum Output Count', 'description' => 'Minimum output observations required before evaluating the quality alert.'],
            ['key' => 'alerts.ar.warning_overdue_days', 'value' => 30, 'group' => 'alerts', 'label' => 'AR Warning Overdue Days', 'description' => 'Days overdue before an accounts-receivable warning is raised.'],
            ['key' => 'alerts.ar.critical_overdue_days', 'value' => 60, 'group' => 'alerts', 'label' => 'AR Critical Overdue Days', 'description' => 'Days overdue before an accounts-receivable critical alert is raised.'],
            ['key' => 'alerts.ap.due_soon_days', 'value' => 3, 'group' => 'alerts', 'label' => 'AP Due-soon Days', 'description' => 'Days before a bill due date when an informational alert is raised.'],
            ['key' => 'alerts.quality.scrap_rate_threshold', 'value' => 0.05, 'group' => 'alerts', 'label' => 'Scrap Rate Alert Threshold', 'description' => 'Reject share above which the product scrap-rate alert is raised.'],
            ['key' => 'alerts.quality.lookback_hours', 'value' => 24, 'group' => 'alerts', 'label' => 'Scrap Alert Lookback Hours', 'description' => 'Production history window used by the scrap-rate alert.'],
            ['key' => 'alerts.quality.minimum_output_count', 'value' => 100, 'group' => 'alerts', 'label' => 'Scrap Alert Minimum Output', 'description' => 'Minimum output observations required before evaluating scrap rate.'],

            // ── Quality policies ─────────────────────────────
            ['key' => 'quality.effectiveness.check_interval_days', 'value' => 30, 'group' => 'quality', 'label' => 'CAPA Effectiveness Check Interval', 'description' => 'Days between CAPA effectiveness checks.'],
            ['key' => 'quality.effectiveness.overdue_escalation_days', 'value' => 14, 'group' => 'quality', 'label' => 'CAPA Effectiveness Escalation Days', 'description' => 'Days overdue before a CAPA effectiveness check is escalated.'],
            ['key' => 'quality.calibration.due_window_days', 'value' => 30, 'group' => 'quality', 'label' => 'Calibration Due Window Days', 'description' => 'Days before calibration expiry when equipment is marked due.'],
            ['key' => 'quality.ncr.recurrence_window_days', 'value' => 30, 'group' => 'quality', 'label' => 'NCR Recurrence Window Days', 'description' => 'Prior-history window used to identify recurring NCRs.'],
            ['key' => 'quality.document_review.rearm_days', 'value' => 7, 'group' => 'quality', 'label' => 'Document Review Reminder Interval', 'description' => 'Days before an overdue document review reminder may be sent again.'],

            // ── Accounting ───────────────────────────────────
            [
                'key'         => 'accounting.default_sales_revenue_account_code',
                'value'       => '4010',
                'group'       => 'accounting',
                'label'       => 'Default Sales Revenue Account',
                'description' => 'Chart of accounts code for auto-generated invoice revenue entries (e.g. 4010 = Sales Revenue).',
            ],
            [
                'key'         => 'accounting.ar_dunning.enabled',
                'value'       => true,
                'group'       => 'accounting',
                'label'       => 'AR Dunning Emails',
                'description' => 'Send automated overdue invoice reminder emails to customers.',
            ],
            [
                'key'         => 'accounting.ar_dunning.tier_days_csv',
                'value'       => '7,15,30',
                'group'       => 'accounting',
                'label'       => 'Dunning Tier Days',
                'description' => 'Comma-separated days after due date for each dunning tier (e.g. 7,15,30).',
            ],

            // ── Attendance ───────────────────────────────────
            [
                'key'         => 'attendance.auto_ot_detect.enabled',
                'value'       => true,
                'group'       => 'attendance',
                'label'       => 'Auto-detect Overtime',
                'description' => 'Automatically detect overtime from biometric punch data.',
            ],
            [
                'key'         => 'attendance.auto_ot_detect.threshold_minutes',
                'value'       => 30,
                'group'       => 'attendance',
                'label'       => 'OT Detection Threshold (minutes)',
                'description' => 'Minimum minutes beyond shift end before overtime is counted.',
            ],

            // ── Maintenance ──────────────────────────────────
            ['key' => 'maintenance.predictive.temperature.max', 'value' => 85.0, 'group' => 'maintenance', 'label' => 'Temperature Maximum', 'description' => 'Maximum safe machine temperature in °C.'],
            ['key' => 'maintenance.predictive.vibration.max', 'value' => 7.1, 'group' => 'maintenance', 'label' => 'Vibration Maximum', 'description' => 'Maximum safe vibration velocity in mm/s.'],
            ['key' => 'maintenance.predictive.pressure.min', 'value' => 2.0, 'group' => 'maintenance', 'label' => 'Pressure Minimum', 'description' => 'Minimum safe hydraulic pressure in bar.'],
            ['key' => 'maintenance.predictive.pressure.max', 'value' => 12.0, 'group' => 'maintenance', 'label' => 'Pressure Maximum', 'description' => 'Maximum safe hydraulic pressure in bar.'],
            ['key' => 'maintenance.predictive.current.max', 'value' => 150.0, 'group' => 'maintenance', 'label' => 'Current Maximum', 'description' => 'Maximum safe current reading in amperes.'],
            ['key' => 'maintenance.predictive.oil_quality.min', 'value' => 70.0, 'group' => 'maintenance', 'label' => 'Oil Quality Minimum', 'description' => 'Minimum acceptable oil quality percentage.'],
            ['key' => 'maintenance.predictive.breach_window', 'value' => 3, 'group' => 'maintenance', 'label' => 'Consecutive Breaches', 'description' => 'Consecutive unsafe readings required before a corrective work order is generated.'],

            // ── Loan policies ────────────────────────────────
            ['key' => 'loans.company_loan.annual_interest_rate', 'value' => 0.0, 'group' => 'loans', 'label' => 'Company Loan Annual Interest Rate', 'description' => 'Decimal annual rate, where 0.10 means 10%.'],
            ['key' => 'loans.company_loan.max_salary_multiplier', 'value' => 1.0, 'group' => 'loans', 'label' => 'Company Loan Salary Limit', 'description' => 'Maximum principal as a multiple of monthly salary.'],
            ['key' => 'loans.cash_advance.annual_interest_rate', 'value' => 0.0, 'group' => 'loans', 'label' => 'Cash Advance Annual Interest Rate', 'description' => 'Decimal annual rate, where 0.10 means 10%.'],
            ['key' => 'loans.cash_advance.max_salary_multiplier', 'value' => 1.0, 'group' => 'loans', 'label' => 'Cash Advance Salary Limit', 'description' => 'Maximum principal as a multiple of monthly salary.'],
            ['key' => 'loans.sss_loan.annual_interest_rate', 'value' => 0.10, 'group' => 'loans', 'label' => 'SSS Salary Loan Annual Interest Rate', 'description' => 'Decimal annual rate, where 0.10 means 10%.'],
            ['key' => 'loans.sss_loan.max_salary_multiplier', 'value' => 1.0, 'group' => 'loans', 'label' => 'SSS Salary Loan Salary Limit', 'description' => 'Maximum principal as a multiple of monthly salary.'],
            ['key' => 'loans.pagibig_loan.annual_interest_rate', 'value' => 0.105, 'group' => 'loans', 'label' => 'Pag-IBIG Loan Annual Interest Rate', 'description' => 'Decimal annual rate, where 0.10 means 10%.'],
            ['key' => 'loans.pagibig_loan.max_salary_multiplier', 'value' => 1.0, 'group' => 'loans', 'label' => 'Pag-IBIG Loan Salary Limit', 'description' => 'Maximum principal as a multiple of monthly salary.'],

            // ── Payroll anomaly policies ─────────────────────
            ['key' => 'payroll.anomaly.net_change_ratio', 'value' => 0.30, 'group' => 'payroll', 'label' => 'Payroll Net-Change Alert Ratio', 'description' => 'Flag net pay changes above this ratio versus the comparable prior payroll.'],
            ['key' => 'payroll.anomaly.overtime_hours', 'value' => 80.0, 'group' => 'payroll', 'label' => 'Payroll Overtime Alert Hours', 'description' => 'Flag payrolls whose attendance overtime exceeds this many hours in one period.'],
            ['key' => 'payroll.anomaly.deduction_ratio', 'value' => 0.50, 'group' => 'payroll', 'label' => 'Payroll Deduction Alert Ratio', 'description' => 'Flag total deductions above this share of gross pay.'],
            ['key' => 'payroll.work_days_per_month', 'value' => 22, 'group' => 'payroll', 'label' => 'Payroll Work Days per Month', 'description' => 'Divisor used to derive daily rates from monthly salary.'],
            ['key' => 'payroll.hours_per_day', 'value' => 8, 'group' => 'payroll', 'label' => 'Payroll Hours per Day', 'description' => 'Divisor used to derive hourly rates and day equivalents.'],
            ['key' => 'payroll.overtime.ordinary_multiplier', 'value' => 1.25, 'group' => 'payroll', 'label' => 'Ordinary-day Overtime Multiplier', 'description' => 'Multiplier applied to ordinary-day overtime hours.'],
            ['key' => 'payroll.overtime.premium_day_multiplier', 'value' => 1.30, 'group' => 'payroll', 'label' => 'Premium-day Overtime Multiplier', 'description' => 'Multiplier applied to overtime on rest days and holidays.'],
            ['key' => 'payroll.night_differential_rate', 'value' => 0.10, 'group' => 'payroll', 'label' => 'Night Differential Rate', 'description' => 'Additive hourly premium for eligible night work.'],
            ['key' => 'payroll.pagibig.compensation_ceiling', 'value' => 10000, 'group' => 'payroll', 'label' => 'Pag-IBIG Compensation Ceiling', 'description' => 'Maximum monthly compensation basis used for Pag-IBIG contributions.'],

            // ── MRP policies ─────────────────────────────────
            ['key' => 'mrp.safety_buffer_days', 'value' => 2, 'group' => 'mrp', 'label' => 'MRP Safety Buffer Days', 'description' => 'Extra days subtracted from material order-by dates after supplier lead time.'],
            ['key' => 'mrp.default_lead_time_days', 'value' => 14, 'group' => 'mrp', 'label' => 'MRP Default Lead Time Days', 'description' => 'Lead time used only when neither an item nor an approved supplier has one configured.'],

            // ── Tax policies ─────────────────────────────────
            ['key' => 'tax.ph.vat_rate', 'value' => env('TAX_PH_VAT_RATE', ''), 'group' => 'tax', 'label' => 'Philippine VAT Rate', 'description' => 'Decimal VAT rate applied to VATable quotes, orders, invoices, bills, and credit notes.'],

            // ── Cross-module business defaults ───────────────
            ['key' => 'sales.default_customer_payment_terms_days', 'value' => 30, 'group' => 'sales', 'label' => 'Default Customer Payment Terms', 'description' => 'Default payment terms for new customers when no explicit value is supplied.'],
            ['key' => 'purchasing.default_vendor_payment_terms_days', 'value' => 30, 'group' => 'purchasing', 'label' => 'Default Vendor Payment Terms', 'description' => 'Default payment terms for new vendors when no explicit value is supplied.'],
            ['key' => 'purchasing.supplier_dispatch.stale_after_minutes', 'value' => 10, 'group' => 'purchasing', 'label' => 'Supplier Dispatch Stale Age (minutes)', 'description' => 'Minutes after which an unconfirmed supplier dispatch may be reclaimed by the recovery job.'],
            ['key' => 'sales.default_delivery_lead_days', 'value' => 30, 'group' => 'sales', 'label' => 'Default Sales Delivery Lead Days', 'description' => 'Delivery lead time used when converting a quote without a valid-until date.'],

            // ── HR ───────────────────────────────────────────
            [
                'key'         => 'hr.auto_provision_user.enabled',
                'value'       => true,
                'group'       => 'hr',
                'label'       => 'Auto-provision User on Hire',
                'description' => 'Automatically create a user account when a new employee is hired.',
            ],

            // ── Purchasing ───────────────────────────────────
            [
                'key'         => 'purchasing.three_way_tolerance_qty_pct',
                'value'       => 5.0,
                'group'       => 'purchasing',
                'label'       => '3-Way Match Qty Tolerance (%)',
                'description' => 'Percentage tolerance for quantity mismatch in PO/GRN/Invoice 3-way matching.',
            ],
            [
                'key'         => 'purchasing.three_way_tolerance_price_pct',
                'value'       => 5.0,
                'group'       => 'purchasing',
                'label'       => '3-Way Match Price Tolerance (%)',
                'description' => 'Percentage tolerance for price mismatch in PO/GRN/Invoice 3-way matching.',
            ],

            // ── Inventory ────────────────────────────────────
            [
                'key'         => 'inventory.allow_negative',
                'value'       => false,
                'group'       => 'inventory',
                'label'       => 'Allow Negative Stock',
                'description' => 'Permit issuing items even when warehouse stock would go below zero.',
            ],
            [
                'key'         => 'inventory.safety_stock.enabled',
                'value'       => true,
                'group'       => 'inventory',
                'label'       => 'Auto Safety Stock',
                'description' => 'Automatically recompute safety stock levels based on demand history.',
            ],
            [
                'key'         => 'inventory.safety_stock.service_level_z',
                'value'       => 1.65,
                'group'       => 'inventory',
                'label'       => 'Service Level Z-score',
                'description' => 'Z-score for desired service level (1.65 ≈ 95%, 2.33 ≈ 99%).',
            ],
            [
                'key'         => 'inventory.safety_stock.history_days',
                'value'       => 90,
                'group'       => 'inventory',
                'label'       => 'Demand History Window (days)',
                'description' => 'Number of days of consumption history used for safety stock calculation.',
            ],
            [
                'key'         => 'inventory.safety_stock.min_demand_days',
                'value'       => 14,
                'group'       => 'inventory',
                'label'       => 'Minimum Demand Days',
                'description' => 'Minimum days of demand data required before safety stock is calculated.',
            ],

            // ── Security ─────────────────────────────────────
            [
                'key'         => 'security.max_login_attempts',
                'value'       => 5,
                'group'       => 'security',
                'label'       => 'Max Login Attempts',
                'description' => 'Account locks after this many consecutive failed login attempts.',
            ],
            [
                'key'         => 'security.lockout_minutes',
                'value'       => 15,
                'group'       => 'security',
                'label'       => 'Lockout Duration (minutes)',
                'description' => 'How long a locked account remains locked before automatic unlock.',
            ],
            [
                'key'         => 'security.password_history_depth',
                'value'       => 3,
                'group'       => 'security',
                'label'       => 'Password History Depth',
                'description' => 'Number of previous passwords that cannot be reused when changing password.',
            ],
            [
                'key'         => 'security.password_min_length',
                'value'       => 8,
                'group'       => 'security',
                'label'       => 'Minimum Password Length',
                'description' => 'Minimum number of characters required for new passwords.',
            ],
            [
                'key'         => 'security.session_timeout_employee',
                'value'       => 15,
                'group'       => 'security',
                'label'       => 'Session Timeout — Employee (minutes)',
                'description' => 'Idle session timeout for users with the employee role.',
            ],
            [
                'key'         => 'security.session_timeout_default',
                'value'       => 30,
                'group'       => 'security',
                'label'       => 'Session Timeout — Default (minutes)',
                'description' => 'Idle session timeout for all users except employees.',
            ],
            [
                'key'         => 'security.password_expiry_days',
                'value'       => 90,
                'group'       => 'security',
                'label'       => 'Password Expiry (days)',
                'description' => 'Days before a password must be changed. Set to 0 to disable expiry.',
            ],
            ['key' => 'accounting.accounts.ar_code', 'value' => '1100', 'group' => 'accounting', 'label' => 'AR Control Account Code', 'description' => 'Chart-of-accounts mapping used for receivables postings.'],
            ['key' => 'accounting.accounts.ap_code', 'value' => '2010', 'group' => 'accounting', 'label' => 'AP Control Account Code', 'description' => 'Chart-of-accounts mapping used for payables postings.'],
            ['key' => 'accounting.accounts.vat_output_code', 'value' => '2060', 'group' => 'accounting', 'label' => 'VAT Output Account Code', 'description' => 'Chart-of-accounts mapping used for output VAT postings.'],
            ['key' => 'accounting.accounts.vat_input_code', 'value' => '1310', 'group' => 'accounting', 'label' => 'VAT Input Account Code', 'description' => 'Chart-of-accounts mapping used for input VAT postings.'],
            ['key' => 'accounting.accounts.discount_code', 'value' => '4010', 'group' => 'accounting', 'label' => 'Sales Discount Account Code', 'description' => 'Chart-of-accounts mapping used for contra-revenue discounts.'],
            ['key' => 'accounting.accounts.grni_code', 'value' => '2110', 'group' => 'accounting', 'label' => 'GRNI Clearing Account Code', 'description' => 'Chart-of-accounts mapping used for goods received not invoiced.'],
            ['key' => 'accounting.accounts.inventory_raw_material_code', 'value' => '1200', 'group' => 'accounting', 'label' => 'Raw Materials Inventory Account Code', 'description' => 'Chart-of-accounts mapping used for raw material receipts.'],
            ['key' => 'accounting.accounts.inventory_finished_goods_code', 'value' => '1210', 'group' => 'accounting', 'label' => 'Finished Goods Inventory Account Code', 'description' => 'Chart-of-accounts mapping used for finished goods receipts.'],
            ['key' => 'accounting.accounts.inventory_packaging_code', 'value' => '1220', 'group' => 'accounting', 'label' => 'Packaging Inventory Account Code', 'description' => 'Chart-of-accounts mapping used for packaging receipts.'],
            ['key' => 'accounting.accounts.inventory_spare_parts_code', 'value' => '1230', 'group' => 'accounting', 'label' => 'Spare Parts Inventory Account Code', 'description' => 'Chart-of-accounts mapping used for spare parts receipts.'],
            ['key' => 'quality.rollout.fixed_sample_size', 'value' => 3, 'group' => 'quality', 'label' => 'Baseline Quality Plan Fixed Sample Size', 'description' => 'Sample size used by the baseline quality-plan rollout command.'],
            ['key' => 'approval.pr.dept_head_auto_approve_threshold', 'value' => 5000, 'group' => 'purchasing', 'label' => 'Dept-Head PR Auto-Approval Threshold', 'description' => 'Purchase request total below which department-head requests may auto-approve.'],
            ['key' => 'accounting.default_expense_account_code', 'value' => '5000', 'group' => 'accounting', 'label' => 'Default Expense Account Code', 'description' => 'Fallback expense account used by supplier portal invoice creation.'],
            ['key' => 'accounting.accounts.final_pay_salary_expense_code', 'value' => '6010', 'group' => 'accounting', 'label' => 'Final Pay Salary Expense Account Code', 'description' => 'Chart-of-accounts mapping used by final-pay journal posting.'],
            ['key' => 'accounting.accounts.cash_code', 'value' => '1020', 'group' => 'accounting', 'label' => 'Cash Account Code', 'description' => 'Chart-of-accounts mapping used for cash disbursements.'],
            ['key' => 'accounting.accounts.loans_payable_code', 'value' => '2100', 'group' => 'accounting', 'label' => 'Loans Payable Account Code', 'description' => 'Chart-of-accounts mapping used to settle employee loans.'],
            ['key' => 'accounting.accounts.accrued_expense_code', 'value' => '2070', 'group' => 'accounting', 'label' => 'Accrued Expense Account Code', 'description' => 'Chart-of-accounts mapping used for accrued final-pay deductions.'],
            ['key' => 'accounting.accounts.asset_cash_code', 'value' => '1010', 'group' => 'accounting', 'label' => 'Asset Disposal Cash Account Code', 'description' => 'Chart-of-accounts mapping used for asset disposal proceeds.'],
            ['key' => 'accounting.accounts.asset_accumulated_depreciation_code', 'value' => '1410', 'group' => 'accounting', 'label' => 'Accumulated Depreciation Account Code', 'description' => 'Chart-of-accounts mapping used for accumulated depreciation.'],
            ['key' => 'accounting.accounts.asset_cost_code', 'value' => '1400', 'group' => 'accounting', 'label' => 'Asset Cost Account Code', 'description' => 'Chart-of-accounts mapping used for asset cost.'],
            ['key' => 'accounting.accounts.asset_disposal_loss_code', 'value' => '6120', 'group' => 'accounting', 'label' => 'Asset Disposal Loss Account Code', 'description' => 'Chart-of-accounts mapping used for disposal losses.'],
            ['key' => 'accounting.accounts.asset_disposal_gain_code', 'value' => '4030', 'group' => 'accounting', 'label' => 'Asset Disposal Gain Account Code', 'description' => 'Chart-of-accounts mapping used for disposal gains.'],
            ['key' => 'accounting.accounts.depreciation_expense_code', 'value' => '6080', 'group' => 'accounting', 'label' => 'Depreciation Expense Account Code', 'description' => 'Chart-of-accounts mapping used for depreciation expense.'],
            ['key' => 'accounting.accounts.sss_payable_code', 'value' => '2020', 'group' => 'accounting', 'label' => 'SSS Payable Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.philhealth_payable_code', 'value' => '2030', 'group' => 'accounting', 'label' => 'PhilHealth Payable Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.pagibig_payable_code', 'value' => '2040', 'group' => 'accounting', 'label' => 'Pag-IBIG Payable Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.withholding_tax_payable_code', 'value' => '2050', 'group' => 'accounting', 'label' => 'Withholding Tax Payable Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.thirteenth_month_payable_code', 'value' => '2080', 'group' => 'accounting', 'label' => '13th Month Payable Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.salary_expense_code', 'value' => '5050', 'group' => 'accounting', 'label' => 'Salary Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.overtime_expense_code', 'value' => '5060', 'group' => 'accounting', 'label' => 'Overtime Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.thirteenth_month_expense_code', 'value' => '5070', 'group' => 'accounting', 'label' => '13th Month Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.sss_employer_expense_code', 'value' => '6030', 'group' => 'accounting', 'label' => 'SSS Employer Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.philhealth_employer_expense_code', 'value' => '6040', 'group' => 'accounting', 'label' => 'PhilHealth Employer Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.pagibig_employer_expense_code', 'value' => '6050', 'group' => 'accounting', 'label' => 'Pag-IBIG Employer Expense Account Code', 'description' => 'Payroll GL account mapping.'],
            ['key' => 'accounting.accounts.payroll_cash_code', 'value' => '1010', 'group' => 'accounting', 'label' => 'Payroll Cash Account Code', 'description' => 'Chart-of-accounts mapping used for payroll net-pay disbursements.'],
            ['key' => 'purchasing.urgent_skip_limit', 'value' => 0, 'group' => 'purchasing', 'label' => 'Urgent PR Skip Limit', 'description' => 'Maximum urgent purchase-request value allowed to skip the first workflow step.'],
            ['key' => 'budgeting.enforcement_mode', 'value' => 'warn', 'group' => 'budgeting', 'label' => 'Budget Enforcement Mode', 'description' => 'off, warn, or block when spend exceeds budget.'],
            ['key' => 'accounting.je_self_post_limit', 'value' => 0, 'group' => 'accounting', 'label' => 'Journal Entry Self-Post Limit', 'description' => 'Manual journal total below which maker-checker may be bypassed.'],
            ['key' => 'inventory.adjustment_approval_threshold', 'value' => 0, 'group' => 'inventory', 'label' => 'Inventory Adjustment Approval Threshold', 'description' => 'Absolute adjustment value above which approval is required.'],
            ['key' => 'inventory.over_receipt_tolerance_pct', 'value' => 0, 'group' => 'inventory', 'label' => 'Over-Receipt Tolerance (%)', 'description' => 'Allowed receipt overage as a percentage of ordered quantity.'],
            ['key' => 'quality.ncr.replacement_work_order_lead_days', 'value' => 7, 'group' => 'quality', 'label' => 'NCR Replacement Work Order Lead Days', 'description' => 'Planned duration for replacement work orders created from scrap NCRs.'],
            ['key' => 'quality.ncr.replacement_work_order_priority', 'value' => 5, 'group' => 'quality', 'label' => 'NCR Replacement Work Order Priority', 'description' => 'Priority assigned to replacement work orders created from scrap NCRs.'],
            ['key' => 'quality.ppap_gate_enabled', 'value' => false, 'group' => 'quality', 'label' => 'PPAP Gate Enabled', 'description' => 'Block purchase-order approval when required supplier PPAP is not approved.'],
            ['key' => 'quality.coc.manager_name', 'value' => $envString('QUALITY_COC_MANAGER_NAME'), 'group' => 'quality', 'label' => 'CoC Quality Manager', 'description' => 'Named quality manager printed on certificates of conformance when configured.'],
            ['key' => 'quality.coc.manager_role', 'value' => $envString('QUALITY_COC_MANAGER_ROLE'), 'group' => 'quality', 'label' => 'CoC Quality Manager Role', 'description' => 'Role label printed below the quality manager signature on certificates of conformance.'],
            ['key' => 'company.employee_email_domain', 'value' => $envString('COMPANY_EMPLOYEE_EMAIL_DOMAIN'), 'group' => 'company', 'label' => 'Employee Account Email Domain', 'description' => 'Domain used when provisioning an email for an employee without one.'],
            ['key' => 'accounting.functional_currency_code', 'value' => strtoupper(trim((string) env('ACCOUNTING_FUNCTIONAL_CURRENCY_CODE', ''))), 'group' => 'accounting', 'label' => 'Functional Currency Code', 'description' => 'Currency code used by the general ledger and statements.'],
        ];

        $deploymentKeys = [
            'company.legal_name', 'company.address', 'company.tin', 'company.phone',
            'company.email', 'company.sales_inbox_email', 'company.vat_status',
            'company.logo_path', 'company.certification', 'company.public_url',
            'company.latitude', 'company.longitude', 'company.employee_email_domain',
            'pdf.footer_disclaimer', 'quality.coc.manager_name', 'quality.coc.manager_role',
            'accounting.functional_currency_code', 'tax.ph.vat_rate',
        ];

        foreach ($rows as $row) {
            // A blank environment value means "leave deployment data alone" on
            // repeat seeds. Operators can clear a setting explicitly through
            // the admin settings endpoint.
            if (in_array($row['key'], $deploymentKeys, true)
                && ($row['value'] === null || $row['value'] === '')
                && $settings->get($row['key'], null) !== null) {
                continue;
            }
            $settings->set(
                $row['key'],
                $row['value'],
                $row['group'],
                $row['label'],
                $row['description'],
            );
        }

        // Module feature toggles
        $modules = [
            'hr'                => ['Human Resources',     'Employee records, departments, positions, separation, and clearance.'],
            'attendance'        => ['Attendance',           'Shifts, daily time records, overtime, and biometric import.'],
            'leave'             => ['Leave Management',     'Leave types, balances, requests, and approval workflows.'],
            'payroll'           => ['Payroll',              'Payroll engine, government deductions, payslips, and bank files.'],
            'loans'             => ['Loans',                'Company loans, cash advances, and automatic payroll deduction.'],
            'accounting'        => ['Accounting',           'Chart of accounts, journal entries, AP/AR, and financial statements.'],
            'inventory'         => ['Inventory',            'Items, warehouses, goods received, stock issues, and valuation.'],
            'purchasing'        => ['Purchasing',           'Purchase requests, purchase orders, approval chains, and 3-way match.'],
            'crm'               => ['CRM',                 'Customers, price agreements, sales orders, and complaint management.'],
            'mrp'               => ['MRP / MRP II',        'Bills of material, material planning, capacity planning, and molds.'],
            'production'        => ['Production',           'Work orders, machine output, downtime tracking, and OEE.'],
            'supply_chain'      => ['Supply Chain',         'Shipments, import documents, fleet management, and deliveries.'],
            'quality'           => ['Quality',              'Inspection specs, QC results, NCRs, and certificates of conformance.'],
            'maintenance'       => ['Maintenance',          'Preventive schedules, work orders, and mold shot tracking.'],
            'assets'            => ['Assets',               'Fixed asset register, depreciation schedules, and QR tracking.'],
            'search'            => ['Global Search',        'Full-text search across all modules.'],
            'notifications'     => ['Notifications',        'In-app and email notifications for events and approvals.'],
            'recruitment'       => ['Recruitment',          'Job postings, applications, interviews, and hiring workflow.'],
            'return_management' => ['Return Management',    'RMA requests, return processing, and credit memos.'],
            'b2b_portals'       => ['B2B Portals',          'Supplier and customer self-service portals.'],
            'forecasting'       => ['Forecasting',          'Demand forecasts, stock-out projections, and forecast accuracy.'],
            'budgeting'         => ['Budgeting',            'Budgets, budget line items, revisions, and transfers.'],
        ];

        foreach ($modules as $slug => [$label, $description]) {
            $settings->set(
                "modules.{$slug}",
                true,
                'modules',
                $label,
                $description,
            );
        }

        $this->command?->info('Settings + feature toggles seeded.');
    }
}
