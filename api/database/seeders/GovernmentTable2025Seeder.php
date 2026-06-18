<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Database\Seeder;

/**
 * 2025 PH government contribution schedule (effective 2025-01-01).
 *
 * SSS: contribution rate 15% (EE 5% / ER 10% of the Monthly Salary Credit),
 *      MSC ₱5,000–₱35,000 in ₱500 steps. (EC and WISP are employer-side /
 *      provident add-ons; refine here if the pilot requires their separate
 *      reporting — the regular SS share below is what drives the payslip.)
 * PhilHealth: premium 5%, floor ₱10,000, ceiling ₱100,000 (split 2.5%/2.5%).
 * Pag-IBIG: 1%/2% for ≤₱1,500 MSC, else 2%/2%.
 *
 * Source: SSS Circular 2024-006 (rate 15% from Jan 2025); PhilHealth Circular
 * 2024 (5% held); HDMF MC. Re-verify exact step values before live filing.
 */
class GovernmentTable2025Seeder extends Seeder
{
    private const EFFECTIVE = '2025-01-01';

    public function run(): void
    {
        // ─── SSS 2025 (computed from MSC × rate) ───────────────────────────
        // Minimum MSC is ₱5,000 (statutory floor): the lowest bracket covers
        // ₱0 upward so sub-floor earners still contribute at the ₱5,000 MSC.
        for ($msc = 5000.00; $msc <= 35000.00; $msc += 500.00) {
            $min = $msc - 249.99 < 0.00 ? 0.00 : round($msc - 249.99, 2);
            // The lowest MSC step still covers ₱0 upward (statutory floor): clamp
            // its lower bound to 0 so sub-floor earners contribute at the floor MSC.
            if ($msc <= 5000.00) {
                $min = 0.00;
            }
            $max = round($msc + 250.00, 2);
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'sss', 'bracket_min' => $min, 'effective_date' => self::EFFECTIVE],
                [
                    'bracket_max' => $msc >= 35000.00 ? 999999.99 : $max,
                    'ee_amount'   => round($msc * 0.05, 2),
                    'er_amount'   => round($msc * 0.10, 2),
                    'is_active'   => true,
                ],
            );
        }

        // ─── PhilHealth 2025 (rate-based single row) ───────────────────────
        GovernmentContributionTable::updateOrCreate(
            ['agency' => 'philhealth', 'bracket_min' => 10000.00, 'effective_date' => self::EFFECTIVE],
            ['bracket_max' => 100000.00, 'ee_amount' => 0.0250, 'er_amount' => 0.0250, 'is_active' => true],
        );

        // ─── Pag-IBIG 2025 ─────────────────────────────────────────────────
        foreach ([[0.00, 1500.00, 0.0100, 0.0200], [1500.01, 999999.99, 0.0200, 0.0200]] as [$min, $max, $ee, $er]) {
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'pagibig', 'bracket_min' => $min, 'effective_date' => self::EFFECTIVE],
                ['bracket_max' => $max, 'ee_amount' => $ee, 'er_amount' => $er, 'is_active' => true],
            );
        }

        $this->command?->info('Government contribution tables seeded for 2025 (SSS/PhilHealth/Pag-IBIG).');
    }
}
