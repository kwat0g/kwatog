<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_control_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('spec_item_id')->constrained('inspection_spec_items')->cascadeOnDelete();
            $table->string('chart_type', 20);
            $table->unsignedSmallInteger('subgroup_size')->default(5);
            $table->decimal('ucl', 15, 6)->nullable();
            $table->decimal('lcl', 15, 6)->nullable();
            $table->decimal('center_line', 15, 6)->nullable();
            $table->decimal('ucl_range', 15, 6)->nullable();
            $table->decimal('lcl_range', 15, 6)->nullable();
            $table->decimal('center_range', 15, 6)->nullable();
            $table->boolean('limits_locked')->default(false);
            $table->unsignedInteger('limits_sample_count')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['product_id', 'spec_item_id', 'chart_type'], 'spc_charts_product_spec_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_control_charts');
    }
};
