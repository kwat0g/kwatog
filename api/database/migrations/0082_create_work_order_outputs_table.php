<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 51 + Task 55. Output recordings for a WO.
 * batch_code is added per the Sprint 6 plan (used by Sprint 7 CoC).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedInteger('good_count');
            $table->unsignedInteger('reject_count');
            $table->string('shift', 20)->nullable();
            $table->string('batch_code', 30)->nullable();
            $table->text('remarks')->nullable();

            $table->index(['work_order_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_outputs');
    }
};
