<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut (2026-08-07) — drop `fx_rates` and the parent-pack translation.
 *
 * REC-12 let an operator record a daily rate per currency and re-express the
 * trial balance, income statement and balance sheet in JPY for the Japanese
 * parent. CLAUDE.md states the system is Philippine Peso only, and every
 * money column is a plain decimal with no currency discriminator — so the
 * translated statements were a presentation layer over single-currency data,
 * not real multi-currency accounting.
 *
 * The two demo rows (JPY, USD) existed solely to keep the parent-pack screen
 * populated; the seeder block that wrote them is removed in the same commit.
 *
 * `CurrencyDisplayService` is NOT part of this cut — it is the ₱ formatter used
 * by loans, alerts, budgets, dunning and sales orders, and reads no FX rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fx_rates');
    }

    public function down(): void
    {
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('currency_code', 3);
            $table->date('rate_date');
            $table->decimal('rate_to_functional', 18, 8);
            $table->string('source', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency_code', 'rate_date']);
        });
    }
};
