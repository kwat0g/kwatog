<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['approvals.reminder_hours', 24, 'approval', 'Approval Reminder Hours', 'Hours a pending approval may wait before its first reminder.'],
        ['approvals.escalation_hours', 48, 'approval', 'Approval Escalation Hours', 'Hours a pending approval may wait before escalation.'],
        ['approvals.auto_resolve.enabled', false, 'approval', 'Auto-resolve Stale Approvals', 'Whether escalated approvals are automatically resolved under the configured SLA policy.'],
        ['approvals.auto_resolve.default_hours', 72, 'approval', 'Auto-resolve Deadline Hours', 'Hours after escalation before the default auto-resolution action runs.'],
        ['approvals.auto_resolve.default_action', 'reject', 'approval', 'Auto-resolve Default Action', 'Default action for stale approvals: approve, reject, or escalate.'],
        ['alerts.dedup_window_hours', 24, 'alerts', 'Alert Deduplication Window', 'Hours during which the same undismissed entity alert is not duplicated.'],
        ['alerts.mold.warning_ratio', 0.80, 'alerts', 'Mold Shot Warning Ratio', 'Share of the mold shot limit that raises a warning.'],
        ['alerts.mold.critical_ratio', 0.95, 'alerts', 'Mold Shot Critical Ratio', 'Share of the mold shot limit that raises a critical alert.'],
        ['alerts.oee.quality_rate_threshold', 0.75, 'alerts', 'OEE Quality Alert Threshold', 'Quality rate below which a machine alert is raised.'],
        ['alerts.oee.lookback_days', 3, 'alerts', 'OEE Alert Lookback Days', 'Production history window used by the machine quality alert.'],
        ['alerts.oee.minimum_output_count', 100, 'alerts', 'OEE Minimum Output Count', 'Minimum output observations required before evaluating the quality alert.'],
        ['alerts.ar.warning_overdue_days', 30, 'alerts', 'AR Warning Overdue Days', 'Days overdue before an accounts-receivable warning is raised.'],
        ['alerts.ar.critical_overdue_days', 60, 'alerts', 'AR Critical Overdue Days', 'Days overdue before an accounts-receivable critical alert is raised.'],
        ['alerts.ap.due_soon_days', 3, 'alerts', 'AP Due-soon Days', 'Days before a bill due date when an informational alert is raised.'],
        ['alerts.quality.scrap_rate_threshold', 0.05, 'alerts', 'Scrap Rate Alert Threshold', 'Reject share above which the product scrap-rate alert is raised.'],
        ['alerts.quality.lookback_hours', 24, 'alerts', 'Scrap Alert Lookback Hours', 'Production history window used by the scrap-rate alert.'],
        ['alerts.quality.minimum_output_count', 100, 'alerts', 'Scrap Alert Minimum Output', 'Minimum output observations required before evaluating scrap rate.'],
        ['quality.effectiveness.check_interval_days', 30, 'quality', 'CAPA Effectiveness Check Interval', 'Days between CAPA effectiveness checks.'],
        ['quality.effectiveness.overdue_escalation_days', 14, 'quality', 'CAPA Effectiveness Escalation Days', 'Days overdue before a CAPA effectiveness check is escalated.'],
        ['quality.calibration.due_window_days', 30, 'quality', 'Calibration Due Window Days', 'Days before calibration expiry when equipment is marked due.'],
        ['quality.ncr.recurrence_window_days', 30, 'quality', 'NCR Recurrence Window Days', 'Prior-history window used to identify recurring NCRs.'],
        ['quality.document_review.rearm_days', 7, 'quality', 'Document Review Reminder Interval', 'Days before an overdue document review reminder may be sent again.'],
        ['quality.copq.spike_ratio', 0.25, 'quality', 'COPQ Spike Alert Ratio', 'Month-over-month COPQ increase ratio that raises an alert.'],
        ['quality.copq.rework_cost_ratio', 0.30, 'quality', 'COPQ Rework Cost Ratio', 'Share of product standard cost used to value rework.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
