<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV10 — B2B Portals. Customer monthly delivery requirement submissions.
 *
 * Customers submit their expected delivery quantities for a given month.
 * These can be used to generate draft Sales Orders or inform production planning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('month', 7); // YYYY-MM format
            $table->string('status', 20)->default('submitted');
            // JSON array of { product_name, quantity, notes? }
            $table->json('lines');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedules');
    }
};
