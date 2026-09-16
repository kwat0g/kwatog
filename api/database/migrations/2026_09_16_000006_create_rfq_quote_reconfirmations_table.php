<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_quote_reconfirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('supplier_quote_id')->constrained('supplier_quotes')->restrictOnDelete();
            $table->foreignId('supplier_portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('terms_snapshot');
            $table->text('reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('purchase_order_id', 'rfq_reconfirmation_po_unique');
            $table->index(['supplier_quote_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_quote_reconfirmations');
    }
};
