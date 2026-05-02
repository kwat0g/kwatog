<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 50);
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            // purchase_order_id stays nullable — Sprint 5 will wire 3-way match.
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->date('date');
            $table->date('due_date');
            $table->boolean('is_vatable')->default(true);
            $table->decimal('subtotal',     15, 2)->default(0);
            $table->decimal('vat_amount',   15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_paid',  15, 2)->default(0);
            $table->decimal('balance',      15, 2)->default(0);
            $table->string('status', 20)->default('unpaid'); // unpaid | partial | paid | cancelled
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'bill_number'], 'bills_vendor_no_unique');
            $table->index('status');
            $table->index('due_date');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
