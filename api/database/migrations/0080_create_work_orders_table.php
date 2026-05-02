<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 51. Work order master.
 *
 * Schema reconciliation per Sprint 6 plan:
 *  - status enum extended with 'cancelled'
 *  - parent_wo_id, parent_ncr_id, mrp_plan_id, sales_order_item_id added
 *    (all nullable for forward compatibility; parent_ncr_id and mrp_plan_id
 *    have no FK constraint here — Sprint 7 Task 61 / Sprint 6 Task 52 add
 *    them when those tables exist)
 *  - batch_code on outputs (added in 0082)
 *
 * Adds the deferred FK from machines.current_work_order_id back to this
 * table inline so both directions are constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_number', 20)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->unsignedBigInteger('sales_order_item_id')->nullable();
            // mrp_plan_id is reserved for Task 52; FK added then.
            $table->unsignedBigInteger('mrp_plan_id')->nullable();
            $table->foreignId('parent_wo_id')->nullable()->constrained('work_orders')->nullOnDelete();
            // parent_ncr_id reserved for Sprint 7 Task 61.
            $table->unsignedBigInteger('parent_ncr_id')->nullable();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('mold_id')->nullable()->constrained('molds')->nullOnDelete();
            $table->decimal('quantity_target', 10, 0);
            $table->decimal('quantity_produced', 10, 0)->default(0);
            $table->decimal('quantity_good', 10, 0)->default(0);
            $table->decimal('quantity_rejected', 10, 0)->default(0);
            $table->decimal('scrap_rate', 5, 2)->default(0);
            $table->dateTime('planned_start');
            $table->dateTime('planned_end');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->string('status', 20)->default('planned');
            $table->string('pause_reason', 200)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('sales_order_id');
            $table->index('machine_id');
            $table->index('mrp_plan_id');
            $table->index('planned_start');
        });

        // Now add the deferred FK on machines.current_work_order_id.
        Schema::table('machines', function (Blueprint $table) {
            $table->foreign('current_work_order_id')
                ->references('id')->on('work_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropForeign(['current_work_order_id']);
        });
        Schema::dropIfExists('work_orders');
    }
};
