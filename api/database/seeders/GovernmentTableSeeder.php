<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Database\Seeder;

/**
 * Seeds Philippine government contribution tables (SSS, PhilHealth, Pag-IBIG, BIR).
 *
 * Sources:
 *   - SSS schedule of contributions effective January 2024 (~30 brackets)
 *   - PhilHealth premium 2024: 5% combined (2.5% each), floor 10K, ceiling 100K
 *   - Pag-IBIG: 1%/2% (low) and 2%/2% (high) brackets, max basis 10K
 *   - BIR TRAIN Law withholding tax (semi-monthly), 6 brackets
 *
 * Convention: ee_amount/er_amount semantics differ per agency.
 *   sss        : flat peso amounts
 *   philhealth : rates (0.0225 / 0.0225)
 *   pagibig    : rates (0.01/0.02 etc.)
 *   bir        : ee_amount = fixed_tax (peso), er_amount = rate_on_excess
 */
class GovernmentTableSeeder extends Seeder
{
    public function run(): void
    {
        $sssEffective        = '2024-01-01';
        $philhealthEffective = '2024-01-01';
        $pagibigEffective    = '2024-01-01';
        $birEffective        = '2018-01-01';

        // ─── SSS (2024 schedule, regular employee — combined SS + EC + WISP) ─────────
        // Source: Social Security System contribution schedule effective Jan 2024.
        // Brackets in PHP per month. EE = employee share, ER = employer share.
        $sssBrackets = [
            [   0.00,  4249.99,  180.00,  390.00],
            [4250.00,  4749.99,  202.50,  437.50],
            [4750.00,  5249.99,  225.00,  485.00],
            [5250.00,  5749.99,  247.50,  532.50],
            [5750.00,  6249.99,  270.00,  580.00],
            [6250.00,  6749.99,  292.50,  627.50],
            [6750.00,  7249.99,  315.00,  675.00],
            [7250.00,  7749.99,  337.50,  722.50],
            [7750.00,  8249.99,  360.00,  770.00],
            [8250.00,  8749.99,  382.50,  817.50],
            [8750.00,  9249.99,  405.00,  865.00],
            [9250.00,  9749.99,  427.50,  912.50],
            [9750.00, 10249.99,  450.00,  960.00],
            [10250.00, 10749.99,  472.50, 1007.50],
            [10750.00, 11249.99,  495.00, 1055.00],
            [11250.00, 11749.99,  517.50, 1102.50],
            [11750.00, 12249.99,  540.00, 1150.00],
            [12250.00, 12749.99,  562.50, 1197.50],
            [12750.00, 13249.99,  585.00, 1245.00],
            [13250.00, 13749.99,  607.50, 1292.50],
            [13750.00, 14249.99,  630.00, 1340.00],
            [14250.00, 14749.99,  652.50, 1387.50],
            [14750.00, 15249.99,  675.00, 1435.00],
            [15250.00, 15749.99,  697.50, 1482.50],
            [15750.00, 16249.99,  720.00, 1530.00],
            [16250.00, 16749.99,  742.50, 1577.50],
            [16750.00, 17249.99,  765.00, 1625.00],
            [17250.00, 17749.99,  787.50, 1672.50],
            [17750.00, 18249.99,  810.00, 1720.00],
            [18250.00, 18749.99,  832.50, 1767.50],
            [18750.00, 19249.99,  855.00, 1815.00],
            [19250.00, 19749.99,  877.50, 1862.50],
            [19750.00, 20249.99,  900.00, 1910.00],
            [20250.00, 20749.99,  922.50, 1957.50],
            [20750.00, 21249.99,  945.00, 2005.00],
            [21250.00, 21749.99,  967.50, 2052.50],
            [21750.00, 22249.99,  990.00, 2100.00],
            [22250.00, 22749.99, 1012.50, 2147.50],
            [22750.00, 23249.99, 1035.00, 2195.00],
            [23250.00, 23749.99, 1057.50, 2242.50],
            [23750.00, 24249.99, 1080.00, 2290.00],
            [24250.00, 24749.99, 1102.50, 2337.50],
            [24750.00, 25249.99, 1125.00, 2385.00],
            [25250.00, 25749.99, 1147.50, 2432.50],
            [25750.00, 26249.99, 1170.00, 2480.00],
            [26250.00, 26749.99, 1192.50, 2527.50],
            [26750.00, 27249.99, 1215.00, 2575.00],
            [27250.00, 27749.99, 1237.50, 2622.50],
            [27750.00, 28249.99, 1260.00, 2670.00],
            [28250.00, 28749.99, 1282.50, 2717.50],
            [28750.00, 29249.99, 1305.00, 2765.00],
            [29250.00, 29749.99, 1327.50, 2812.50],
            [29750.00, 999999.99, 1350.00, 2910.00],
        ];

        foreach ($sssBrackets as [$min, $max, $ee, $er]) {
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'sss', 'bracket_min' => $min, 'effective_date' => $sssEffective],
                ['bracket_max' => $max, 'ee_amount' => $ee, 'er_amount' => $er, 'is_active' => true],
            );
        }

        // ─── PhilHealth 2024 ─────────────────────────────────────────────
        // Single rate-based row. ee_amount and er_amount carry the rates (0.0225 each).
        // basis = clamp(salary, 10000, 100000); total_premium = basis * 0.05; split 50/50.
        GovernmentContributionTable::updateOrCreate(
            ['agency' => 'philhealth', 'bracket_min' => 10000.00, 'effective_date' => $philhealthEffective],
            [
                'bracket_max' => 100000.00,
                'ee_amount'   => 0.0225,
                'er_amount'   => 0.0225,
                'is_active'   => true,
            ],
        );

        // ─── Pag-IBIG ───────────────────────────────────────────────────
        // Two brackets, rate-based. Max basis 10,000 ⇒ EE max 200, ER max 200.
        $pagibigBrackets = [
            [   0.00,  1500.00, 0.0100, 0.0200], // EE 1%, ER 2%
            [1500.01, 999999.99, 0.0200, 0.0200], // EE 2%, ER 2%
        ];
        foreach ($pagibigBrackets as [$min, $max, $ee, $er]) {
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'pagibig', 'bracket_min' => $min, 'effective_date' => $pagibigEffective],
                ['bracket_max' => $max, 'ee_amount' => $ee, 'er_amount' => $er, 'is_active' => true],
            );
        }

        // ─── BIR Withholding Tax (TRAIN Law, semi-monthly) ──────────────
        // Convention: ee_amount = fixed_tax (peso), er_amount = rate_on_excess.
        $birBrackets = [
            [     0.00,  10416.00,    0.00, 0.00], // exempt
            [ 10416.01,  16666.00,    0.00, 0.15],
            [ 16666.01,  33332.00,  937.50, 0.20],
            [ 33332.01,  83332.00, 4270.83, 0.25],
            [ 83332.01, 333332.00, 16770.83, 0.30],
            [333332.01, 999999.99, 91770.83, 0.35],
        ];
        foreach ($birBrackets as [$min, $max, $fixed, $rate]) {
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'bir', 'bracket_min' => $min, 'effective_date' => $birEffective],
                ['bracket_max' => $max, 'ee_amount' => $fixed, 'er_amount' => $rate, 'is_active' => true],
            );
        }

        $this->command?->info('Government contribution tables seeded (SSS '.count($sssBrackets).', PhilHealth 1, Pag-IBIG '.count($pagibigBrackets).', BIR '.count($birBrackets).').');
    }
}
