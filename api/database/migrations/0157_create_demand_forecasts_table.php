<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV11 — Demand & Sales Forecasting.
 *
 * One row per (product, customer-or-NULL, year, month). NULL customer_id
 * means total demand across all customers. Forecasted_quantity is the
 * computed/manual prediction; actual_quantity is filled in once the month
 * has elapsed (allowing forecast-accuracy reporting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('forecast_month');     // 1..12
            $table->unsignedSmallInteger('forecast_year');     // e.g. 2026
            $table->string('method', 30);                      // moving_avg, weighted_avg, manual
            $table->decimal('forecasted_quantity', 12, 2);
            $table->decimal('confidence_level', 5, 2)->nullable();   // %
            $table->decimal('actual_quantity', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One forecast per scope per period.
            $table->unique(
                ['product_id', 'customer_id', 'forecast_year', 'forecast_month'],
                'demand_forecasts_scope_unique'
            );
            $table->index(['forecast_year', 'forecast_month'], 'demand_forecasts_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
