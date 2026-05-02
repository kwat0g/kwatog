<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_id')->nullable(); // FK in Sprint 6
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('status', 20)->default('reserved'); // reserved/issued/released
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index('item_id');
            $table->index('work_order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_reservations');
    }
};
