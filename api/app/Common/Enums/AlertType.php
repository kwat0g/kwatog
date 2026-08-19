<?php

declare(strict_types=1);

namespace App\Common\Enums;

/**
 * Task A2 — Operational alert types fired by AlertEngineService.
 *
 *  INVENTORY
 *    stock_critical   stock < safety_stock
 *    stock_low        stock < reorder_point
 *    no_supplier      no approved supplier and stock < reorder_point
 *
 *  PRODUCTION
 *    machine_breakdown      machine.status = 'breakdown'
 *    mold_shot_limit        mold.current_shot_count > 80% of max_shots
 *    mold_shot_critical     mold.current_shot_count > 95% of max_shots
 *    wo_overdue             wo.planned_end < now() and status != 'completed'
 *    oee_below_threshold    machine OEE below the configured threshold/window
 *
 *  FINANCE
 *    ar_overdue_30   invoice.due_date beyond the configured warning band
 *    ar_overdue_60   invoice.due_date beyond the configured critical band
 *    ap_due_soon     bill.due_date within the configured due-soon band
 *
 *  QUALITY
 *    qc_fail_rate_high   daily scrap rate > 5% on any product
 *
 *  SCHEDULER
 *    scheduler_stale   the execution ledger's heartbeat is stale, stuck or gapped.
 *                      Catches a STALLED scheduler, not a dead one — the check
 *                      that raises it is itself scheduled. See
 *                      AlertEngineService::checkScheduler().
 */
enum AlertType: string
{
    case StockCritical      = 'stock_critical';
    case StockLow           = 'stock_low';
    case NoSupplier         = 'no_supplier';

    case MachineBreakdown   = 'machine_breakdown';
    case MoldShotLimit      = 'mold_shot_limit';
    case MoldShotCritical   = 'mold_shot_critical';
    case WoOverdue          = 'wo_overdue';
    case OeeBelowThreshold  = 'oee_below_threshold';

    case ArOverdue30        = 'ar_overdue_30';
    case ArOverdue60        = 'ar_overdue_60';
    case ApDueSoon          = 'ap_due_soon';

    case QcFailRateHigh     = 'qc_fail_rate_high';

    /** Series C — Task C5 — Chain bottleneck alert. */
    case ChainBottleneck    = 'chain_bottleneck';

    case MrpShortage         = 'mrp_shortage';
    case MrpScheduleConflict = 'mrp_schedule_conflict';
    case MrpRunFailed        = 'mrp_run_failed';
    case MrpDataError        = 'mrp_data_error';

    /** Raised from SchedulerExecutionLedger::health() — see AlertEngineService::checkScheduler(). */
    case SchedulerStale      = 'scheduler_stale';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::StockCritical => 'Stock critical',
            self::StockLow => 'Stock low',
            self::NoSupplier => 'No supplier',
            self::MachineBreakdown => 'Machine breakdown',
            self::MoldShotLimit => 'Mold approaching limit',
            self::MoldShotCritical => 'Mold critical limit',
            self::WoOverdue => 'Work order overdue',
            self::OeeBelowThreshold => 'OEE below threshold',
            self::ArOverdue30 => 'AR overdue (warning)',
            self::ArOverdue60 => 'AR overdue (critical)',
            self::ApDueSoon => 'AP due soon',
            self::QcFailRateHigh => 'QC fail rate high',
            self::ChainBottleneck => 'Chain bottleneck',
            self::MrpShortage => 'MRP material shortage',
            self::MrpScheduleConflict => 'MRP schedule conflict',
            self::MrpRunFailed => 'MRP run failed',
            self::MrpDataError => 'MRP data error',
            self::SchedulerStale => 'Scheduler stale',
        };
    }

    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::StockCritical, self::MachineBreakdown,
            self::MoldShotCritical, self::ArOverdue60,
            self::MrpRunFailed, self::SchedulerStale => AlertSeverity::Critical,

            self::ApDueSoon => AlertSeverity::Info,

            default => AlertSeverity::Warning,
        };
    }
}
