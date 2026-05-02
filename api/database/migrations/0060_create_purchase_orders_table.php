<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 20)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->date('date');
            $table->date('expected_delivery_date')->nullable();
            $table->decimal('subtotal',     15, 2)->default(0);
            $table->decimal('vat_amount',   15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->boolean('is_vatable')->default(true);
            $table->string('status', 30)->default('draft'); // draft/pending_approval/approved/sent/partially_received/received/closed/cancelled
            $table->boolean('requires_vp_approval')->default(false);
            $table->unsignedTinyInteger('current_approval_step')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_to_supplier_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('vendor_id');
            $table->index('purchase_request_id');
            $table->index('expected_delivery_date');
            $table->index('requires_vp_approval');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
