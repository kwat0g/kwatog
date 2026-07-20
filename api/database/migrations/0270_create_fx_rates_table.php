<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-12 (core) — FX rate table for statement translation.
 *
 * The functional currency of the books is PHP. A rate row expresses how many
 * units of the FUNCTIONAL currency (PHP) one unit of `currency_code` buys on
 * `rate_date` — i.e. rate_to_functional. For JPY→PHP this is ~0.38; a JPY
 * amount is divided by this to translate INTO JPY for the parent pack, and a
 * foreign amount is multiplied by it to translate INTO PHP.
 *
 * Only translation (read-side) uses this table today; transaction-currency
 * capture on documents + realized FX on settlement is deferred (see
 * CurrencyTranslationService docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency_code', 3);                 // ISO 4217, e.g. JPY, USD
            $table->date('rate_date');
            // How many PHP one unit of currency_code is worth on rate_date.
            $table->decimal('rate_to_functional', 18, 8);
            $table->string('source', 40)->nullable();         // 'manual', 'bsp', vendor feed, …
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency_code', 'rate_date'], 'fx_rates_ccy_date_unique');
            $table->index('currency_code');
            $table->index('rate_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
