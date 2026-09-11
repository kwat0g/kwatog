<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier response/negotiation records (2026-09-11).
 *
 * A supplier that cannot fulfil a sent PO exactly replies with one of
 * `accept` / `propose` / `decline`. Each reply is immutable evidence: a
 * re-submission supersedes the prior pending response rather than mutating
 * it, and purchasing's decision (`accept`/`reject`) is written back onto
 * the same row so the negotiation trail is complete.
 *
 * Timestamp-named because the referenced supplier portal users table was
 * created by a 0NNN_ migration but the response rows also join
 * `purchase_orders` statuses extended by 2026_09_11_000003 — keeping the
 * 2026_* family together guarantees ordering against any 0NNN_ file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->string('response_type', 20);
            $table->string('status', 20)->default('pending');
            $table->date('proposed_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
            $table->index('vendor_id');
        });

        Schema::create('purchase_order_response_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_response_id')->constrained('purchase_order_responses')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->decimal('proposed_quantity', 12, 2)->nullable();
            $table->decimal('proposed_unit_price', 15, 2)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index('purchase_order_response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_response_items');
        Schema::dropIfExists('purchase_order_responses');
    }
};
