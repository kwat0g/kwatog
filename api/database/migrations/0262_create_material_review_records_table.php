<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-08 — Material Review Board (MRB) hold/quarantine records.
 *
 * Ties a nonconforming lot (NCR / inspection fail) to the physical quarantine
 * stock movement, its disposition, and the release movement — IATF §8.7
 * traceability for held/segregated nonconforming stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_review_records', function (Blueprint $table) {
            $table->id();
            $table->string('mrb_number')->unique();

            $table->foreignId('ncr_id')->nullable()
                ->constrained('non_conformance_reports')->nullOnDelete();
            $table->foreignId('inspection_id')->nullable()
                ->constrained('inspections')->nullOnDelete();

            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);

            $table->foreignId('source_location_id')
                ->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('quarantine_location_id')
                ->constrained('warehouse_locations')->restrictOnDelete();

            // Mirrors NcrDisposition values; null until released.
            $table->string('disposition', 30)->nullable();
            $table->string('status', 20)->default('held'); // held|released|scrapped|returned

            $table->foreignId('hold_movement_id')->nullable()
                ->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('release_movement_id')->nullable()
                ->constrained('stock_movements')->nullOnDelete();

            $table->foreignId('held_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('held_at');

            $table->foreignId('released_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('release_location_id')->nullable()
                ->constrained('warehouse_locations')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_review_records');
    }
};
