<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 47.
 * Finished-good products sold by the CRM module. References:
 *   - docs/SCHEMA.md "MRP / products"
 *   - plans/ogami-erp-sprint-6-order-to-cash-part-1-crm-mrp-production-tasks-47-58.md
 *
 * NOTE: schema.md groups this under MRP because the BOM hangs off it, but the
 * product master is owned by the CRM module per the file structure in CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('part_number', 30)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 20)->default('pcs');
            $table->decimal('standard_cost', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
