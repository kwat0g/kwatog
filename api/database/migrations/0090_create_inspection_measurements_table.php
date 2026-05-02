<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 60. Per-sample-unit measurement rows.
 *
 * One row per (inspection × spec_item × sample_index). For dimensional
 * parameters the inspector enters measured_value and the service auto-
 * evaluates pass/fail vs the spec item's tolerance window. For visual
 * checks measured_value is null and is_pass is set manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')
                ->constrained('inspections')
                ->cascadeOnDelete();

            // Nullable: inspector can add ad-hoc rows beyond the spec
            $table->foreignId('inspection_spec_item_id')
                ->nullable()
                ->constrained('inspection_spec_items')
                ->nullOnDelete();

            $table->unsignedInteger('sample_index')->default(1); // 1..sample_size
            $table->string('parameter_name', 150); // denormalised for history
            $table->string('parameter_type', 20);  // dimensional | visual | functional
            $table->string('unit_of_measure', 20)->nullable();
            $table->decimal('nominal_value', 12, 4)->nullable();
            $table->decimal('tolerance_min', 12, 4)->nullable();
            $table->decimal('tolerance_max', 12, 4)->nullable();
            $table->decimal('measured_value', 12, 4)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_pass')->nullable(); // null = not yet evaluated
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['inspection_id', 'sample_index']);
            $table->index('inspection_spec_item_id');
            $table->index('is_pass');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_measurements');
    }
};
