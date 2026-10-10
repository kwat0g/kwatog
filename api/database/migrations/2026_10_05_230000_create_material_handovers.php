<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('received_by_employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('recipient_name');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestampTz('handed_over_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_handovers');
    }
};
