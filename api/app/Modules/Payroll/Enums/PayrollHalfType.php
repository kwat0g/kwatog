<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

/**
 * Semi-monthly half, as a FILTER value for listing periods.
 *
 * No longer an input when creating a period: the half is derived from
 * period_start (see PayrollPeriod::deriveIsFirstHalf). Offering it as a choice
 * let the label contradict the dates — Aug 16–31 marked "1st half" — which
 * inverted the pay-cycle key and allowed one employee to be paid twice in a
 * month, and moved government contributions onto the wrong cutoff.
 */
enum PayrollHalfType: string
{
    case FirstHalf = 'true';
    case SecondHalf = 'false';

    public function label(): string
    {
        return $this === self::FirstHalf
            ? '1st half (government deductions apply)'
            : '2nd half';
    }
}
