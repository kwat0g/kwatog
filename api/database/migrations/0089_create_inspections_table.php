<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 60. Quality inspections root row.
 *
 * One inspection ties a stage (incoming/in_process/outgoing) to a product
 * and the entity it is gating (GRN, work order, or delivery). The
 * sample_size column is populated by AQL Level II 0.65 lookup for
 * outgoing batches; for incoming + in_process it equals batch_quantity
 * (full check) by default — the inspector may override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_number', 32)->unique();
            $table->string('stage', 20); // incoming | in_process | outgoing
            $table->string('status', 20)->default('draft'); // draft | in_progress | passed | failed | cancelled

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inspection_spec_id')->nullable()->constrained('inspection_specs')->nullOnDelete();

            // Polymorphic link to gated entity (grn / work_order / delivery)
            $table->string('entity_type', 30)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->unsignedInteger('batch_quantity');
            $table->unsignedInteger('sample_size');
            $table->string('aql_code', 4)->nullable();   // Level II letter code (G, H, J ...)
            $table->unsignedInteger('accept_count')->default(0); // Ac
            $table->unsignedInteger('reject_count')->default(0); // Re
            $table->unsignedInteger('defect_count')->default(0);

            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('stage');
            $table->index('status');
            $table->index('product_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('inspector_id');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
