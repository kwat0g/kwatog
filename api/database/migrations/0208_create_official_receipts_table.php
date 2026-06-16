<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-008 — BIR Official Receipt.
 *
 * An OR acknowledges actual cash received (a collection/payment), distinct from
 * the Sales Invoice which records the sale. One OR is issued per collection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('or_number', 30)->unique();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('collection_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_receipts');
    }
};
