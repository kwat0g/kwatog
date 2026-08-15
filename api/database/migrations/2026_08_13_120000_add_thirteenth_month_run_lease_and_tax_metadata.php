<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->uuid('thirteenth_month_run_token')->nullable()->after('processing_token');
            $table->string('thirteenth_month_run_state', 24)->nullable()->after('thirteenth_month_run_token');
            $table->string('tax_reconciliation_hash', 64)->nullable()->after('thirteenth_month_run_state');
            $table->timestamp('tax_reconciliation_signed_at')->nullable()->after('tax_reconciliation_hash');
            $table->index(['is_thirteenth_month', 'period_start', 'thirteenth_month_run_state'], 'payroll_13th_run_state_idx');
        });

        Schema::table('payrolls', function (Blueprint $table): void {
            $table->decimal('thirteenth_month_taxable_excess', 15, 2)->default(0)->after('withholding_tax');
            $table->decimal('thirteenth_month_correction_delta', 15, 2)->default(0)->after('thirteenth_month_taxable_excess');
        });

        Schema::create('thirteenth_month_tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('exemption_amount', 15, 2);
            $table->string('authority_reference', 255);
            $table->timestamps();
        });

        // BIR RR 11-2018 Annex D (2018–2022) and Annex E (2023 onward),
        // annual compensation table. Values are pesos; rate is stored as a
        // decimal fraction (0.20 = 20%).
        Schema::create('payroll_annual_tax_brackets', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from');
            $table->decimal('bracket_min', 15, 2);
            $table->decimal('bracket_max', 15, 2);
            $table->decimal('fixed_tax', 15, 2);
            $table->decimal('rate_on_excess', 8, 6);
            $table->string('authority_reference', 255);
            $table->timestamps();
            $table->unique(['effective_from', 'bracket_min'], 'payroll_annual_tax_bracket_unique');
        });

        $schedules = [
            ['effective' => '2018-01-01', 'reference' => 'BIR RR 11-2018 Annex D', 'brackets' => [
                [0, 250000, 0, 0], [250000.01, 400000, 0, .20],
                [400000.01, 800000, 30000, .25], [800000.01, 2000000, 130000, .30],
                [2000000.01, 8000000, 490000, .32], [8000000.01, 999999999999, 2410000, .35],
            ]],
            ['effective' => '2023-01-01', 'reference' => 'BIR RR 11-2018 Annex E', 'brackets' => [
                [0, 250000, 0, 0], [250000.01, 400000, 0, .15],
                [400000.01, 800000, 22500, .20], [800000.01, 2000000, 102500, .25],
                [2000000.01, 8000000, 402500, .30], [8000000.01, 999999999999, 2202500, .35],
            ]],
        ];
        foreach ($schedules as $schedule) {
            $effective = $schedule['effective'];
            $reference = $schedule['reference'];
            $brackets = $schedule['brackets'];
            foreach ($brackets as [$min, $max, $fixed, $rate]) {
                DB::table('payroll_annual_tax_brackets')->insert([
                    'effective_from' => $effective, 'bracket_min' => $min,
                    'bracket_max' => $max, 'fixed_tax' => $fixed,
                    'rate_on_excess' => $rate, 'authority_reference' => $reference,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('thirteenth_month_tax_rules')->insert([
            'effective_from' => '2018-01-01',
            'exemption_amount' => '90000.00',
            'authority_reference' => 'Republic Act 10963 §9 / Tax Code §32(B)(7)(e), effective 2018-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_annual_tax_brackets');
        Schema::dropIfExists('thirteenth_month_tax_rules');
        Schema::table('payrolls', fn (Blueprint $table) => $table->dropColumn(['thirteenth_month_taxable_excess', 'thirteenth_month_correction_delta']));
        Schema::table('payroll_periods', fn (Blueprint $table) => $table->dropIndex('payroll_13th_run_state_idx')->dropColumn([
            'thirteenth_month_run_token', 'thirteenth_month_run_state', 'tax_reconciliation_hash', 'tax_reconciliation_signed_at',
        ]));
    }
};
