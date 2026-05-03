<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 65. Inbound shipment tracker for imported POs.
 *
 * Status flow: ordered → shipped → in_transit → customs → cleared → received
 *
 * The ImpEx Officer drives status transitions and uploads BOC / shipping
 * paperwork. On `received` we expect a GRN to follow (handled by Inventory
 * module — back-link by purchase_order_id, no FK needed here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_number', 32)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('status', 20)->default('ordered'); // ordered | shipped | in_transit | customs | cleared | received | cancelled
            $table->string('carrier', 100)->nullable();
            $table->string('vessel', 100)->nullable();
            $table->string('container_number', 32)->nullable();
            $table->string('bl_number', 32)->nullable();      // Bill of Lading
            $table->date('etd')->nullable();                   // Estimated time of departure
            $table->date('atd')->nullable();                   // Actual time of departure
            $table->date('eta')->nullable();                   // Estimated time of arrival
            $table->date('ata')->nullable();                   // Actual time of arrival
            $table->date('customs_clearance_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('status');
            $table->index('purchase_order_id');
            $table->index('eta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
