<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_data_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->unsignedInteger('subgroup_number');
            $table->decimal('subgroup_mean', 15, 6)->nullable();
            $table->decimal('subgroup_range', 15, 6)->nullable();
            $table->decimal('subgroup_std_dev', 15, 6)->nullable();
            $table->decimal('individual_value', 15, 6)->nullable();
            $table->decimal('moving_range', 15, 6)->nullable();
            $table->json('sample_values');
            $table->timestamp('recorded_at');
            $table->json('alerts')->nullable();
            $table->json('inspection_ids')->nullable();
            $table->timestamps();

            $table->index(['control_chart_id', 'subgroup_number']);
            $table->index(['control_chart_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_data_points');
    }
};
