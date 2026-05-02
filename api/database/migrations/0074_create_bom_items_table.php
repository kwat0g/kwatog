<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 49. BOM rows: raw-material consumption per finished unit.
 * waste_factor is a percentage (e.g. 5.00 for 5% scrap allowance).
 * MRP engine (Task 52) reads this via BomService::explode().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('bill_of_materials')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 10, 4);
            $table->string('unit', 20);
            $table->decimal('waste_factor', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('bom_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
