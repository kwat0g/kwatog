<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-004 — per-item UOM conversion factors.
 *
 * factor = how many `to_uom` (base) units there are per ONE `from_uom` unit.
 *   e.g. 1 BAG = 25 KG  →  from_uom=BAG, to_uom=KG, factor=25.000000
 *
 * Quantities are always STORED in the item base UOM. Conversions are applied
 * only at the edges (receiving, issuing) to translate an alternate purchase /
 * issue UOM into the base before it ever touches a stock movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->timestamps();

            $table->unique(['item_id', 'from_uom_id', 'to_uom_id'], 'uq_item_uom_conv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_uom_conversions');
    }
};
