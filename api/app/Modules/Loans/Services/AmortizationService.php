<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

class AmortizationService
{
    /**
     * Generate an equal-split, zero-interest amortization schedule.
     * Last period absorbs rounding remainder.
     *
     * @return array<int, array{period:int, amount:string, remaining_after:string}>
     */
    public function generate(string $principal, int $periods): array
    {
        if ($periods <= 0) {
            throw new \InvalidArgumentException('Pay periods must be at least 1.');
        }
        $perPeriod = bcdiv($principal, (string) $periods, 2);
        $schedule = [];
        $running = $principal;
        for ($i = 1; $i <= $periods; $i++) {
            $amount = $i === $periods ? $running : $perPeriod;
            $running = bcsub($running, $amount, 2);
            // Avoid -0.00
            if (bccomp($running, '0', 2) < 0) $running = '0.00';
            $schedule[] = [
                'period'          => $i,
                'amount'          => $amount,
                'remaining_after' => $running,
            ];
        }
        return $schedule;
    }

    public function monthlyAmortization(string $principal, int $periods): string
    {
        return bcdiv($principal, (string) max(1, $periods), 2);
    }
}
