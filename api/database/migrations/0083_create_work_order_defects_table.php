<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 51 / 55. Per-defect breakdown of an output recording.
 * Sum of count for a given output_id must <= work_order_outputs.reject_count
 * (validated server-side, not via DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('output_id')->constrained('work_order_outputs')->cascadeOnDelete();
            $table->foreignId('defect_type_id')->constrained('defect_types')->restrictOnDelete();
            $table->unsignedInteger('count');

            $table->index('output_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_defects');
    }
};
