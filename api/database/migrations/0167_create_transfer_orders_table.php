<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number', 30)->unique();
            $table->foreignId('from_location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('reason', 200)->nullable();
            $table->string('status', 20)->default('pending');      // pending, transferred, cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('transferred_by')->nullable()->constrained('users');
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_orders');
    }
};
