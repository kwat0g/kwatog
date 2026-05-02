<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 50. M:N compatibility — which molds fit which machines.
 * MRP II capacity planner (Task 53) joins through this pivot to choose
 * machine assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mold_machine_compatibility', function (Blueprint $table) {
            $table->foreignId('mold_id')->constrained('molds')->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->primary(['mold_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mold_machine_compatibility');
    }
};
