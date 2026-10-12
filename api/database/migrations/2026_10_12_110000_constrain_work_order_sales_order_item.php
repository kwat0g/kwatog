<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('work_orders as work_orders')
            ->leftJoin('sales_order_items', 'sales_order_items.id', '=', 'work_orders.sales_order_item_id')
            ->whereNotNull('work_orders.sales_order_item_id')
            ->whereNull('sales_order_items.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("Cannot constrain work_orders.sales_order_item_id: {$orphans} orphan rows found.");
        }

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreign('sales_order_item_id')
                ->references('id')->on('sales_order_items')
                ->nullOnDelete();
            $table->index('sales_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_order_item_id']);
            $table->dropIndex(['sales_order_item_id']);
        });
    }
};
