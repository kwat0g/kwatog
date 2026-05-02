<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government contribution tables (SSS / PhilHealth / Pag-IBIG / BIR).
 *
 * The two amount columns are reinterpreted per agency to avoid a 6-column
 * schema for what is fundamentally the same lookup pattern:
 *
 *   sss        : ee_amount = flat peso, er_amount = flat peso
 *   philhealth : ee_amount = rate (e.g. 0.0225), er_amount = rate
 *   pagibig    : ee_amount = rate (e.g. 0.01),  er_amount = rate
 *   bir        : ee_amount = fixed_tax peso,    er_amount = rate_on_excess
 *
 * Decimal precision is 4 so rates fit cleanly; UI renders 2 places for money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('government_contribution_tables', function (Blueprint $table) {
            $table->id();
            $table->string('agency', 20); // sss | philhealth | pagibig | bir
            $table->decimal('bracket_min', 15, 2);
            $table->decimal('bracket_max', 15, 2);
            $table->decimal('ee_amount', 15, 4);
            $table->decimal('er_amount', 15, 4);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['agency', 'is_active']);
            $table->index(['agency', 'effective_date']);
            $table->index(['agency', 'bracket_min', 'bracket_max']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('government_contribution_tables');
    }
};
