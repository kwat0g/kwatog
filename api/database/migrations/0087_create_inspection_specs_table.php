<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 59. Inspection specifications per product.
 *
 * One spec per product (UNIQUE constraint). Spec items live in the
 * companion table 0088_create_inspection_spec_items_table.php.
 *
 * Specs back the IATF 16949 quality flow: incoming QC verifies materials,
 * in-process QC samples between operations, outgoing QC enforces AQL 0.65
 * Level II tolerances. Every measurement on `inspection_measurements`
 * (Sprint 7 Task 60) references a spec_item_id and pass/fail is computed
 * server-side from tolerance_min/tolerance_max.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_specs');
    }
};
