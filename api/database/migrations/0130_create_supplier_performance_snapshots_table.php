<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series F — Task F4. Supplier performance snapshots.
 *
 * One row per (vendor, year, month). Refreshed by the monthly job
 * `RecomputeSupplierPerformanceJob`. Persisted (vs computed on the
 * fly) so the supplier list can show the score chip cheaply and the
 * trend chart can render last-N-months without recomputing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')
                ->constrained('vendors')
                ->cascadeOnDelete();
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');

            // Rates expressed as 0–100 percentages.
            $table->decimal('on_time_delivery_rate', 5, 2)->nullable();
            $table->decimal('quality_pass_rate',     5, 2)->nullable();
            $table->decimal('price_variance_pct',    5, 2)->nullable();
            $table->decimal('lead_time_variance_days', 5, 2)->nullable();
            $table->decimal('overall_score',         5, 2)->nullable();

            // Sample sizes — used to show "N/A — too few data points" in the UI.
            $table->integer('po_count')->default(0);
            $table->integer('grn_count')->default(0);

            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['vendor_id', 'period_year', 'period_month']);
            $table->index(['period_year', 'period_month']);
            $table->index('overall_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_performance_snapshots');
    }
};
