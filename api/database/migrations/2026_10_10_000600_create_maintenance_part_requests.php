<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_part_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->foreignId('work_log_id')->constrained('maintenance_logs')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->uuid('operation_key')->unique();
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_part_requests');
    }
};
