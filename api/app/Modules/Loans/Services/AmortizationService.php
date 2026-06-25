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
            if (bccomp($running, '0', 2) < 0) $running = '0.00';
            $schedule[] = [
                'period'          => $i,
                'amount'          => $amount,
                'principal'       => $amount,
                'interest'        => '0.00',
                'remaining_after' => $running,
            ];
        }
        return $schedule;
    }

    /**
     * Interest-bearing amortization (diminishing balance / equal payment).
     * Uses standard PMT formula: M = P * [r(1+r)^n] / [(1+r)^n - 1]
     *
     * @param string $principal   Loan amount
     * @param string $annualRate  Annual interest rate as decimal (e.g. "0.10" = 10%)
     * @param int    $periods     Number of monthly payments
     * @return array<int, array{period:int, amount:string, principal:string, interest:string, remaining_after:string}>
     */
    public function generateWithInterest(string $principal, string $annualRate, int $periods): array
    {
        if ($periods <= 0) {
            throw new \InvalidArgumentException('Pay periods must be at least 1.');
        }

        if (bccomp($annualRate, '0', 10) === 0) {
            return $this->generate($principal, $periods);
        }

        $monthlyRate = bcdiv($annualRate, '12', 10);
        $onePlusR = bcadd('1', $monthlyRate, 10);

        $pow = bcpow($onePlusR, (string) $periods, 10);
        $numerator = bcmul(bcmul($principal, $monthlyRate, 10), $pow, 10);
        $denominator = bcsub($pow, '1', 10);
        $monthlyPayment = bcdiv($numerator, $denominator, 2);

        $schedule = [];
        $balance = $principal;

        for ($i = 1; $i <= $periods; $i++) {
            $interest = bcmul($balance, $monthlyRate, 2);
            $principalPortion = bcsub($monthlyPayment, $interest, 2);

            if ($i === $periods) {
                $principalPortion = $balance;
                $monthlyPayment = bcadd($principalPortion, $interest, 2);
            }

            $balance = bcsub($balance, $principalPortion, 2);
            if (bccomp($balance, '0', 2) < 0) $balance = '0.00';

            $schedule[] = [
                'period'          => $i,
                'amount'          => $i === $periods ? bcadd($principalPortion, $interest, 2) : $monthlyPayment,
                'principal'       => $principalPortion,
                'interest'        => $interest,
                'remaining_after' => $balance,
            ];
        }

        return $schedule;
    }

    public function monthlyAmortization(string $principal, int $periods): string
    {
        return bcdiv($principal, (string) max(1, $periods), 2);
    }

    public function monthlyAmortizationWithInterest(string $principal, string $annualRate, int $periods): string
    {
        if (bccomp($annualRate, '0', 10) === 0) {
            return $this->monthlyAmortization($principal, $periods);
        }

        $monthlyRate = bcdiv($annualRate, '12', 10);
        $onePlusR = bcadd('1', $monthlyRate, 10);
        $pow = bcpow($onePlusR, (string) $periods, 10);
        $numerator = bcmul(bcmul($principal, $monthlyRate, 10), $pow, 10);
        $denominator = bcsub($pow, '1', 10);

        return bcdiv($numerator, $denominator, 2);
    }
}
