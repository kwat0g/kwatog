<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 50. Injection-mold tooling — keyed to a single product.
 *
 * Note: asset_id FK is intentionally absent here; Sprint 8 Task 70 introduces
 * the assets table and adds the constraint in its own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molds', function (Blueprint $table) {
            $table->id();
            $table->string('mold_code', 20)->unique();
            $table->string('name', 100);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('cavity_count');
            $table->unsignedSmallInteger('cycle_time_seconds');
            $table->unsignedInteger('output_rate_per_hour');
            $table->unsignedSmallInteger('setup_time_minutes')->default(90);
            $table->unsignedInteger('current_shot_count')->default(0);
            $table->unsignedInteger('max_shots_before_maintenance');
            $table->unsignedInteger('lifetime_total_shots')->default(0);
            $table->unsignedInteger('lifetime_max_shots');
            $table->string('status', 20)->default('available'); // available/in_use/maintenance/retired
            $table->string('location', 50)->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molds');
    }
};
