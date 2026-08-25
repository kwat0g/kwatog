<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->unique()->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->date('shipped_date')->nullable();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->date('estimated_arrival')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_shipment_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_shipment_id')->constrained('supplier_shipments')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['supplier_shipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_shipment_updates');
        Schema::dropIfExists('supplier_shipments');
    }
};
