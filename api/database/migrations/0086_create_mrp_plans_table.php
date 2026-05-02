<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 52. MRP plans + the deferred FK constraints from Task 48
 * and Task 51 that point at this table.
 *
 * MRP plan flow:
 *  - SalesOrderService::confirm() invokes MrpEngineService::runForSalesOrder()
 *  - The engine creates one row here, links it to the SO, and stamps every
 *    draft work order it produces + every auto PR it creates.
 *  - Re-running supersedes the prior plan (status='superseded') and creates
 *    a new row with version=N+1.
 *
 * Also: purchase_requests.mrp_plan_id is added here so auto-generated PRs
 * can be traced back to their planning context. is_auto_generated already
 * exists on purchase_requests from Sprint 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_plans', function (Blueprint $table) {
            $table->id();
            $table->string('mrp_plan_no', 20)->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('active'); // active / superseded / cancelled
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('shortages_found')->default(0);
            $table->unsignedInteger('auto_pr_count')->default(0);
            $table->unsignedInteger('draft_wo_count')->default(0);
            $table->json('diagnostics')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
        });

        // Deferred FKs from earlier tables now point here.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreign('mrp_plan_id')->references('id')->on('mrp_plans')->nullOnDelete();
        });
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreign('mrp_plan_id')->references('id')->on('mrp_plans')->nullOnDelete();
        });

        // purchase_requests gains mrp_plan_id so auto PRs are traceable.
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('mrp_plan_id')->nullable()->after('department_id')
                ->constrained('mrp_plans')->nullOnDelete();
            $table->index('mrp_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['mrp_plan_id']);
            $table->dropColumn('mrp_plan_id');
        });
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['mrp_plan_id']);
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['mrp_plan_id']);
        });
        Schema::dropIfExists('mrp_plans');
    }
};
