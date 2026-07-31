<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.settings.manage') ?? false;
    }

    public function rules(): array
    {
        $key = $this->route('key');

        $base = ['value' => ['present']];

        $securityRules = [
            'security.max_login_attempts'      => ['value' => ['required', 'integer', 'min:1', 'max:20']],
            'security.lockout_minutes'          => ['value' => ['required', 'integer', 'min:1', 'max:60']],
            'security.password_history_depth'   => ['value' => ['required', 'integer', 'min:0', 'max:10']],
            'security.password_min_length'      => ['value' => ['required', 'integer', 'min:6', 'max:32']],
            'security.session_timeout_employee' => ['value' => ['required', 'integer', 'min:5', 'max:120']],
            'security.session_timeout_default'  => ['value' => ['required', 'integer', 'min:5', 'max:120']],
            'security.password_expiry_days'     => ['value' => ['required', 'integer', 'min:0', 'max:365']],
        ];

        $maintenanceRules = [
            'maintenance.predictive.temperature.max' => ['value' => ['required', 'numeric', 'min:0']],
            'maintenance.predictive.vibration.max'   => ['value' => ['required', 'numeric', 'min:0']],
            'maintenance.predictive.pressure.min'    => ['value' => ['required', 'numeric', 'min:0']],
            'maintenance.predictive.pressure.max'    => ['value' => ['required', 'numeric', 'min:0']],
            'maintenance.predictive.current.max'     => ['value' => ['required', 'numeric', 'min:0']],
            'maintenance.predictive.oil_quality.min' => ['value' => ['required', 'numeric', 'min:0', 'max:100']],
            'maintenance.predictive.breach_window'   => ['value' => ['required', 'integer', 'min:1', 'max:20']],
        ];

        if (str_starts_with((string) $key, 'loans.') && str_ends_with((string) $key, '.annual_interest_rate')) {
            return ['value' => ['required', 'numeric', 'min:0', 'max:1']];
        }
        if (str_starts_with((string) $key, 'loans.') && str_ends_with((string) $key, '.max_salary_multiplier')) {
            return ['value' => ['required', 'numeric', 'min:0.1', 'max:24']];
        }
        if (str_starts_with((string) $key, 'purchasing.supplier_score.weight_')) {
            return ['value' => ['required', 'numeric', 'min:0', 'max:1']];
        }
        $policyRules = [
            'tax.ph.vat_rate' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'payroll.anomaly.net_change_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:5']],
            'payroll.anomaly.overtime_hours' => ['value' => ['required', 'numeric', 'min:0', 'max:500']],
            'payroll.anomaly.deduction_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'mrp.safety_buffer_days' => ['value' => ['required', 'integer', 'min:0', 'max:365']],
            'mrp.default_lead_time_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'sales.default_customer_payment_terms_days' => ['value' => ['required', 'integer', 'min:0', 'max:365']],
            'purchasing.default_vendor_payment_terms_days' => ['value' => ['required', 'integer', 'min:0', 'max:365']],
            'sales.default_delivery_lead_days' => ['value' => ['required', 'integer', 'min:0', 'max:3650']],
            'approvals.reminder_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'approvals.escalation_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'approvals.auto_resolve.enabled' => ['value' => ['required', 'boolean']],
            'approvals.auto_resolve.default_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'approvals.auto_resolve.default_action' => ['value' => ['required', 'in:approve,reject,escalate']],
            'alerts.dedup_window_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'alerts.mold.warning_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'alerts.mold.critical_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'alerts.oee.quality_rate_threshold' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'alerts.oee.lookback_days' => ['value' => ['required', 'integer', 'min:1', 'max:365']],
            'alerts.oee.minimum_output_count' => ['value' => ['required', 'integer', 'min:1']],
            'alerts.ar.warning_overdue_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'alerts.ar.critical_overdue_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'alerts.ap.due_soon_days' => ['value' => ['required', 'integer', 'min:0', 'max:365']],
            'alerts.quality.scrap_rate_threshold' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'alerts.quality.lookback_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'alerts.quality.minimum_output_count' => ['value' => ['required', 'integer', 'min:1']],
            'quality.effectiveness.check_interval_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'quality.effectiveness.overdue_escalation_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'quality.calibration.due_window_days' => ['value' => ['required', 'integer', 'min:0', 'max:3650']],
            'quality.ncr.recurrence_window_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'quality.document_review.rearm_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'quality.copq.spike_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'quality.copq.rework_cost_ratio' => ['value' => ['required', 'numeric', 'min:0', 'max:1']],
            'payroll.work_days_per_month' => ['value' => ['required', 'numeric', 'gt:0', 'max:31']],
            'payroll.hours_per_day' => ['value' => ['required', 'numeric', 'gt:0', 'max:24']],
            'payroll.overtime.ordinary_multiplier' => ['value' => ['required', 'numeric', 'min:1', 'max:10']],
            'payroll.overtime.premium_day_multiplier' => ['value' => ['required', 'numeric', 'min:1', 'max:10']],
            'payroll.night_differential_rate' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'payroll.pagibig.compensation_ceiling' => ['value' => ['required', 'numeric', 'gt:0']],
            'fiscal.year_start_month' => ['value' => ['required', 'integer', 'between:1,12']],
            'accounting.ar_dunning.enabled' => ['value' => ['required', 'boolean']],
            'accounting.ar_dunning.tier_days_csv' => ['value' => ['required', 'regex:/^\d+(,\d+)*$/']],
            'accounting.default_sales_revenue_account_code' => ['value' => ['required', 'string', 'max:20']],
            'hr.auto_provision_user.enabled' => ['value' => ['required', 'boolean']],
            'payroll.payslip_email.enabled' => ['value' => ['required', 'boolean']],
            'purchasing.supplier_score.neutral_missing_metric' => ['value' => ['required', 'numeric', 'between:0,100']],
            'purchasing.supplier_score.ncr_penalty_factor' => ['value' => ['required', 'numeric', 'min:0']],
            'purchasing.supplier_score.price_penalty_factor' => ['value' => ['required', 'numeric', 'min:0']],
            'purchasing.supplier_score.lead_time_penalty_factor' => ['value' => ['required', 'numeric', 'min:0']],
            'purchasing.supplier_score.tier_a_min' => ['value' => ['required', 'numeric', 'between:0,100']],
            'purchasing.supplier_score.tier_b_min' => ['value' => ['required', 'numeric', 'between:0,100']],
            'purchasing.supplier_score.tier_c_min' => ['value' => ['required', 'numeric', 'between:0,100']],
            'purchasing.supplier_score.deterioration_drop' => ['value' => ['required', 'numeric', 'between:0,100']],
            'hr.training_expiry.t30_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'hr.training_expiry.t14_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'hr.training_expiry.t7_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'quality.ncr.sla_critical_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'quality.ncr.sla_high_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'quality.ncr.sla_medium_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'quality.ncr.sla_low_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'quality.ppap.approval_validity_years' => ['value' => ['required', 'integer', 'min:1', 'max:100']],
            'crm.complaint_8d.d3_due_hours' => ['value' => ['required', 'integer', 'min:1', 'max:8760']],
            'crm.complaint_8d.d4_due_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'crm.complaint_8d.finalize_due_days' => ['value' => ['required', 'integer', 'min:1', 'max:3650']],
            'attendance.ot.minimum_minutes' => ['value' => ['required', 'integer', 'min:0', 'max:1440']],
            'attendance.ot.maximum_minutes' => ['value' => ['required', 'integer', 'min:1', 'max:1440']],
            'attendance.tardiness.maximum_minutes' => ['value' => ['required', 'integer', 'min:1', 'max:1440']],
            'attendance.night_band_start_hour' => ['value' => ['required', 'integer', 'between:0,23']],
            'attendance.night_band_end_hour' => ['value' => ['required', 'integer', 'between:0,23']],
            'attendance.half_day_work_ratio' => ['value' => ['required', 'numeric', 'between:0,1']],
            'payroll.day_rate.regular_holiday' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'payroll.day_rate.regular_holiday_rest_day' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'payroll.day_rate.special_holiday' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'payroll.day_rate.special_holiday_rest_day' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
            'payroll.day_rate.rest_day' => ['value' => ['required', 'numeric', 'min:0', 'max:10']],
        ];

        return $securityRules[$key] ?? $maintenanceRules[$key] ?? $policyRules[$key] ?? $base;
    }

    public function messages(): array
    {
        return [
            'value.min' => 'Value is below the allowed minimum.',
            'value.max' => 'Value exceeds the allowed maximum.',
        ];
    }
}
