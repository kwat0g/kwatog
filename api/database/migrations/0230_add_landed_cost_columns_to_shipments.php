<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-104 — Landed cost tracking for inbound shipments.
 *
 * Adds cost-allocation columns to shipments and a new table for per-PO-line
 * breakdown of allocated freight, insurance, duties, brokerage, and other charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('freight_cost', 15, 2)->nullable()->default(0)->after('customs_clearance_date');
            $table->decimal('insurance_cost', 15, 2)->nullable()->default(0)->after('freight_cost');
            $table->decimal('duties_amount', 15, 2)->nullable()->default(0)->after('insurance_cost');
            $table->decimal('brokerage_fee', 15, 2)->nullable()->default(0)->after('duties_amount');
            $table->decimal('other_charges', 15, 2)->nullable()->default(0)->after('brokerage_fee');
            $table->decimal('landed_cost_total', 15, 2)->nullable()->after('other_charges');
            $table->string('allocation_method', 20)->nullable()->default('by_value')->after('landed_cost_total');
            $table->timestamp('landed_cost_calculated_at')->nullable()->after('allocation_method');
        });

        Schema::create('shipment_landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('allocated_freight', 15, 2)->default(0);
            $table->decimal('allocated_insurance', 15, 2)->default(0);
            $table->decimal('allocated_duties', 15, 2)->default(0);
            $table->decimal('allocated_brokerage', 15, 2)->default(0);
            $table->decimal('allocated_other', 15, 2)->default(0);
            $table->decimal('total_allocated', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'purchase_order_item_id'], 'shipment_landed_cost_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_landed_costs');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'freight_cost',
                'insurance_cost',
                'duties_amount',
                'brokerage_fee',
                'other_charges',
                'landed_cost_total',
                'allocation_method',
                'landed_cost_calculated_at',
            ]);
        });
    }
};
