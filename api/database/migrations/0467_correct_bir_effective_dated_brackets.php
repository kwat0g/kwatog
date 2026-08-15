<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct the effective-dated semi-monthly BIR TRAIN tables.
 *
 * Regulatory source: BIR Revenue Regulation No. 11-2018, Annex D
 * (effective 2018-01-01 through 2022) and Annex E (effective 2023-01-01
 * onward). This migration replaces only those two official BIR effective
 * dates and deliberately leaves custom/future effective dates untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = [
            '2018-01-01' => [
                [0.00, 10416.99, 0.00, 0.00],
                [10417.00, 16666.99, 0.00, 0.20],
                [16667.00, 33332.99, 1250.00, 0.25],
                [33333.00, 83332.99, 5416.67, 0.30],
                [83333.00, 333332.99, 20416.67, 0.32],
                [333333.00, 999999999.99, 100416.67, 0.35],
            ],
            '2023-01-01' => [
                [0.00, 10416.99, 0.00, 0.00],
                [10417.00, 16666.99, 0.00, 0.15],
                [16667.00, 33332.99, 937.50, 0.20],
                [33333.00, 83332.99, 4270.70, 0.25],
                [83333.00, 333332.99, 16770.70, 0.30],
                [333333.00, 999999999.99, 91770.70, 0.35],
            ],
        ];

        foreach ($schedules as $effectiveDate => $brackets) {
            DB::table('government_contribution_tables')
                ->where('agency', 'bir')
                ->whereDate('effective_date', $effectiveDate)
                ->delete();

            $now = now();
            $rows = array_map(
                static fn (array $bracket): array => [
                    'agency' => 'bir',
                    'bracket_min' => $bracket[0],
                    'bracket_max' => $bracket[1],
                    'ee_amount' => $bracket[2],
                    'er_amount' => $bracket[3],
                    'effective_date' => $effectiveDate,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $brackets,
            );

            DB::table('government_contribution_tables')->insert($rows);
        }
    }

    public function down(): void
    {
        // Rollback-only compatibility: restore the exact legacy 2018 seed
        // that this migration replaced. The 2023 official schedule did not
        // exist before this migration, so it is removed rather than retained.
        DB::table('government_contribution_tables')
            ->where('agency', 'bir')
            ->whereIn('effective_date', ['2018-01-01', '2023-01-01'])
            ->delete();

        $legacy = [
            [0.00, 10416.00, 0.00, 0.00],
            [10416.01, 16666.00, 0.00, 0.15],
            [16666.01, 33332.00, 937.50, 0.20],
            [33332.01, 83332.00, 4270.83, 0.25],
            [83332.01, 333332.00, 16770.83, 0.30],
            [333332.01, 999999.99, 91770.83, 0.35],
        ];

        $now = now();
        DB::table('government_contribution_tables')->insert(array_map(
            static fn (array $bracket): array => [
                'agency' => 'bir',
                'bracket_min' => $bracket[0],
                'bracket_max' => $bracket[1],
                'ee_amount' => $bracket[2],
                'er_amount' => $bracket[3],
                'effective_date' => '2018-01-01',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $legacy,
        ));
    }
};
