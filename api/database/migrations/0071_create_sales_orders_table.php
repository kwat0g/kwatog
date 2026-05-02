<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 48.
 *
 * Note (schema reconciliation per Sprint 6 plan):
 *  - status enum extended with 'draft' (created via form, then explicitly
 *    confirmed); plan also lists 'in_production', 'partially_delivered',
 *    'delivered', 'invoiced', 'cancelled'.
 *  - payment_terms_days, delivery_terms, notes added (not in SCHEMA.md but
 *    needed for invoicing + chain visualization).
 *  - mrp_plan_id is nullable and intentionally NOT FK-constrained at this
 *    point — Task 52 introduces the mrp_plans table and adds the FK there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('so_number', 20)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('date');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('delivery_terms', 50)->nullable();
            $table->text('notes')->nullable();
            // FK constraint added by Task 52 migration once mrp_plans exists.
            $table->unsignedBigInteger('mrp_plan_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
            $table->index('date');
            $table->index('mrp_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
