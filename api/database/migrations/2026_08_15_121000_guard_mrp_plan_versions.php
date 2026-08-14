<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent concurrent MRP requests from creating duplicate plan versions or
 * more than one active plan for a sales order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrp_plans', function (Blueprint $table): void {
            $table->unique(['sales_order_id', 'version'], 'mrp_plans_sales_order_version_unique');
        });

        DB::statement(
            "CREATE UNIQUE INDEX mrp_plans_one_active_per_sales_order "
            . "ON mrp_plans (sales_order_id) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mrp_plans_one_active_per_sales_order');

        Schema::table('mrp_plans', function (Blueprint $table): void {
            $table->dropUnique('mrp_plans_sales_order_version_unique');
        });
    }
};
