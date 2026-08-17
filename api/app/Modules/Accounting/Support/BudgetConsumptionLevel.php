<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Common\Support\Money;

/**
 * Classifies how much of a budget is consumed into a severity level.
 *
 * WHY THIS COMPARES AMOUNTS AND NEVER DIVIDES:
 *   The implementation this replaces computed `round(consumed / allocated * 100, 1)`
 *   and compared that percentage against a ratio. Rounding to one decimal
 *   discards 0.05% of resolution, and `budget.exhausted_ratio` is exactly 1.00,
 *   so every consumption level from 99.95% upward became 100.0 and classified
 *   `exhausted` — stamping a false label that blocked PO/PR approval through
 *   BudgetEnforcementService::assertAcknowledged().
 *
 *   Restating the comparison as money-against-money removes both the division
 *   and the rounding from the decision. Division is also the one bcmath
 *   operation that forces an explicit scale choice, so avoiding it avoids a
 *   precision question entirely.
 *
 * Percentages are still fine for display. They are not fine as a decision input.
 */
final class BudgetConsumptionLevel
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const EXHAUSTED = 'exhausted';

    public const OVERDRAWN = 'overdrawn';

    /**
     * @param  string  $consumedAfter  spent + committed + the amount under consideration
     * @param  string  $allocated  the budget ceiling
     * @param  array{warning: float, critical: float, exhausted: float, overdrawn: float}  $ratios
     */
    public static function classify(string $consumedAfter, string $allocated, array $ratios): string
    {
        // A zero or negative ceiling has no meaningful consumption ratio. Return
        // early rather than multiplying by it, so no caller can divide by it.
        if (Money::lte($allocated, '0')) {
            return self::OK;
        }

        // Descending severity: the first threshold reached wins.
        $levels = [
            self::OVERDRAWN => $ratios['overdrawn'],
            self::EXHAUSTED => $ratios['exhausted'],
            self::CRITICAL => $ratios['critical'],
            self::WARNING => $ratios['warning'],
        ];

        foreach ($levels as $level => $ratio) {
            if (Money::gte($consumedAfter, Money::mul($allocated, $ratio))) {
                return $level;
            }
        }

        return self::OK;
    }
}
