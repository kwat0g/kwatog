<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 47.
 * Per-customer pricing windows. PriceAgreementService::resolve() is the only
 * sanctioned price entry point in the codebase — SO line items pull from here
 * at create time and freeze the resolved unit_price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('price', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to');
            $table->timestamps();

            // Composite lookup index for the resolve() query.
            $table->index(['product_id', 'customer_id', 'effective_from'], 'ppa_lookup_idx');
            $table->index('effective_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_agreements');
    }
};
