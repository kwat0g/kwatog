<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer response / negotiation records (2026-09-12).
 *
 * The customer-side mirror of `purchase_order_responses`. A customer that
 * cannot accept a sales order exactly replies with one of
 * `accept` / `propose` / `decline`. Each reply is immutable evidence: a
 * re-submission supersedes the prior pending response rather than mutating
 * it, and the internal sales team's decision (`accept`/`reject`) is written
 * back onto the same row so the negotiation trail is complete.
 *
 * Timestamp-named because the referenced customer portal users table and the
 * sales orders table are extended by 2026_* migrations on this branch; a
 * `0NNN_` file would sort before them. Dated after the supplier response
 * family (2026_09_11_000005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->string('response_type', 20);
            $table->string('status', 20)->default('pending');
            $table->date('proposed_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('sales_order_response_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_response_id')->constrained('sales_order_responses')->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->cascadeOnDelete();
            $table->decimal('proposed_quantity', 12, 2)->nullable();
            $table->decimal('proposed_unit_price', 15, 2)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index('sales_order_response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_response_items');
        Schema::dropIfExists('sales_order_responses');
    }
};
