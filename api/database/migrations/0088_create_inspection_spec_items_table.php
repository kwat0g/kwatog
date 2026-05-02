<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 59. Per-spec list of inspection parameters.
 *
 * Each row is one parameter: dimensional (e.g. "shaft OD" with mm tolerance),
 * visual (e.g. "no flash"), or functional (e.g. "click force"). Parameters
 * marked is_critical fail the whole inspection if their measurement falls
 * outside tolerance — non-critical lines accumulate into a defect count
 * but the inspection can still pass when within AQL limits.
 *
 * tolerance_min / tolerance_max are nullable for non-numeric parameters
 * (a visual "no flash" check needs no numeric range — only a pass/fail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_spec_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_spec_id')
                ->constrained('inspection_specs')
                ->cascadeOnDelete();
            $table->string('parameter_name', 150);
            $table->string('parameter_type', 20)->default('dimensional'); // dimensional / visual / functional
            $table->string('unit_of_measure', 20)->nullable();
            $table->decimal('nominal_value', 12, 4)->nullable();
            $table->decimal('tolerance_min', 12, 4)->nullable();
            $table->decimal('tolerance_max', 12, 4)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('inspection_spec_id');
            $table->index('parameter_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_spec_items');
    }
};
