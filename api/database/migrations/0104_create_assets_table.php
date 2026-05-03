<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 70. Asset register.
 *
 * Each asset depreciates straight-line monthly:
 *   monthly_amount = (acquisition_cost - salvage_value) / (useful_life_years * 12)
 *
 * Categories (machine/mold/vehicle) cross-reference to operational tables via
 * FKs already present (machines.asset_id, molds.asset_id, vehicles.asset_id —
 * the latter added in 0106 below if not yet present).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 32)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('category', 30); // machine | mold | vehicle | equipment | furniture | other
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 15, 2);
            $table->unsignedInteger('useful_life_years');
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->string('status', 20)->default('active'); // active | under_maintenance | disposed
            $table->date('disposed_date')->nullable();
            $table->decimal('disposal_amount', 15, 2)->nullable();
            $table->string('location', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('status');
            $table->index('department_id');
            $table->index('acquisition_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
