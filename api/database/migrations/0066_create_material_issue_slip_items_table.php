<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issue_slip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_slip_id')->constrained('material_issue_slips')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('quantity_issued', 15, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('total_cost', 15, 2);
            $table->unsignedBigInteger('material_reservation_id')->nullable();
            $table->string('remarks', 200)->nullable();
            $table->timestamps();

            $table->index('material_issue_slip_id');
            $table->index('item_id');
            $table->index('material_reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_slip_items');
    }
};
