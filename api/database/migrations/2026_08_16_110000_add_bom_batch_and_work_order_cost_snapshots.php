<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->decimal('cost_batch_size', 15, 3)->default(1)->after('product_id');
        });

        Schema::table('mrp_plans', function (Blueprint $table): void {
            $table->json('cost_summary')->nullable()->after('diagnostics');
        });

        Schema::table('work_order_materials', function (Blueprint $table): void {
            $table->decimal('standard_unit_cost', 15, 4)->default(0)->after('bom_quantity');
            $table->decimal('standard_cost', 15, 2)->default(0)->after('standard_unit_cost');
            $table->decimal('actual_cost', 15, 2)->default(0)->after('actual_quantity_issued');
            $table->decimal('cost_variance', 15, 2)->default(0)->after('actual_cost');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table): void {
            $table->dropColumn(['standard_unit_cost', 'standard_cost', 'actual_cost', 'cost_variance']);
        });

        Schema::table('mrp_plans', function (Blueprint $table): void {
            $table->dropColumn('cost_summary');
        });

        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->dropColumn('cost_batch_size');
        });
    }
};
