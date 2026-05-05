<?php

declare(strict_types=1);

namespace App\Common\Enums;

/**
 * Task A2 — All twelve alert types fired by AlertEngineService.
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
 *    oee_below_threshold    machine OEE < 75% for 3 consecutive days
 *
 *  FINANCE
 *    ar_overdue_30   invoice.due_date < today - 30 and unpaid
 *    ar_overdue_60   invoice.due_date < today - 60 and unpaid
 *    ap_due_soon     bill.due_date = today + 3 and unpaid
 *
 *  QUALITY
 *    qc_fail_rate_high   daily scrap rate > 5% on any product
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

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::StockCritical, self::MachineBreakdown,
            self::MoldShotCritical, self::ArOverdue60 => AlertSeverity::Critical,

            self::ApDueSoon => AlertSeverity::Info,

            default => AlertSeverity::Warning,
        };
    }
}
