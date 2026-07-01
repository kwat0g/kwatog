<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('product_routings')->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('operation_name', 100);
            $table->string('work_center', 100)->nullable();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('mold_id')->nullable()->constrained('molds')->nullOnDelete();
            $table->decimal('setup_time_minutes', 8, 2)->default(0);
            $table->decimal('cycle_time_minutes', 8, 2);
            $table->text('description')->nullable();
            $table->boolean('qc_required')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
    }
};
