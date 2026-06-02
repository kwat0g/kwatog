<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Budget;

class BudgetEnforcementService
{
    /**
     * Check if a department has remaining budget for a given amount.
     * Returns [bool $canProceed, string $level, string $message].
     *
     * Level: 'ok' | 'warning' (80%+) | 'critical' (95%+) | 'exhausted' (100%+) | 'overdrawn' (120%+)
     */
    public function checkAvailability(int $departmentId, float $amount, ?int $fiscalYearId = null): array
    {
        $fyId = $fiscalYearId ?? app(BudgetService::class)->getCurrentFiscalYear()?->id;
        if (! $fyId) {
            return [true, 'ok', 'No active fiscal year found.'];
        }

        $budgets = Budget::with('lineItems')
            ->byFiscalYear($fyId)
            ->byDepartment($departmentId)
            ->active()
            ->get();

        if ($budgets->isEmpty()) {
            return [true, 'ok', 'No active budget for this department.'];
        }

        $available = $budgets->sum(fn ($b) => $b->available);
        $pct       = $available > 0
            ? round(($amount + ($budgets->sum('total_spent') + $budgets->sum('total_committed'))) / $budgets->sum('total_allocated') * 100, 1)
            : 0;

        if ($available <= 0) {
            return [false, 'exhausted', "Budget exhausted. No remaining available funds (₱0.00 available)."];
        }

        if ($amount > $available) {
            return [false, 'overdrawn', "Insufficient budget. Requested: ₱" . number_format($amount, 2)
                . ", Available: ₱" . number_format($available, 2) . "."];
        }

        if ($pct >= 120) {
            return [false, 'overdrawn', "Budget {$pct}% consumed. VP approval required."];
        }

        if ($pct >= 100) {
            return [false, 'exhausted', "Budget 100% consumed. Finance acknowledgment required."];
        }

        if ($pct >= 95) {
            return [false, 'critical', "Budget {$pct}% consumed. Finance acknowledgment required."];
        }

        if ($pct >= 80) {
            return [true, 'warning', "Budget {$pct}% consumed. Warning sent to department head."];
        }

        return [true, 'ok', "Budget within limits ({$pct}% consumed). ₱" . number_format($available, 2) . " available."];
    }
}
