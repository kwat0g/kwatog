<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-012 — Stock-adjustment reason codes + value-threshold approval gate.
 *
 * The codebase had NO dedicated `stock_adjustments` table: adjustments were
 * only ever written as `stock_movements` rows with reference_type =
 * 'stock_adjustment'. This migration introduces the table that records the
 * adjustment intent + reason code + approval state, with the resulting
 * stock_movement linked once applied.
 *
 * `reason_code` is the structured (enum-backed) reason; the free-text `reason`
 * stays on the linked stock_movement.remarks. `status` drives the value-
 * threshold approval gate: adjustments above the configured peso threshold
 * are created `pending` and must be approved before the stock movement posts.
 *
 * Guard: if a `stock_adjustments` table somehow already exists, only the
 * `reason_code` column is added (idempotent / forward-compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_adjustments')) {
            Schema::create('stock_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
                $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
                $table->string('direction', 3);                 // in | out
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('value', 15, 2)->default(0);    // abs(quantity * unit_cost)
                $table->string('reason_code', 30)->nullable();  // StockAdjustmentReason enum
                $table->text('reason')->nullable();             // free-text audit detail
                $table->string('status', 20)->default('approved'); // pending | approved
                $table->foreignId('stock_movement_id')->nullable()
                    ->constrained('stock_movements')->nullOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['item_id', 'location_id']);
                $table->index('status');
                $table->index('reason_code');
            });

            return;
        }

        // Table pre-exists (forward-compat) — just ensure reason_code is present.
        if (! Schema::hasColumn('stock_adjustments', 'reason_code')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->string('reason_code', 30)->nullable()->after('reason');
                $table->index('reason_code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
