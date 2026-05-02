<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->string('item_type', 20); // raw_material/finished_good/packaging/spare_part
            $table->string('unit_of_measure', 20);
            $table->decimal('standard_cost', 15, 4)->default(0);
            $table->string('reorder_method', 20)->default('fixed_quantity'); // fixed_quantity/days_of_supply
            $table->decimal('reorder_point', 15, 3)->default(0);
            $table->decimal('safety_stock', 15, 3)->default(0);
            $table->decimal('minimum_order_quantity', 15, 3)->default(1);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('item_type');
            $table->index('is_active');
            $table->index('is_critical');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
