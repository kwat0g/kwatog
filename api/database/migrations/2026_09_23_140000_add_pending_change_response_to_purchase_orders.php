<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add pending_change_response_id FK to purchase_orders (2026-09-23).
 *
 * When a supplier counter-offer (propose response) crosses the VP threshold
 * on an already-approved PO, the proposal is NOT applied immediately. Instead,
 * the PO resets to pending_approval with pending_change_response_id set, and
 * the approval chain re-runs against the proposed total. This prevents buyers
 * from bypassing approval by accepting a price increase without re-authorization.
 *
 * The response row is marked 'pending_approval' (new enum value) rather than
 * 'pending' during this re-approval window, so it cannot be overwritten by
 * another supplier reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('pending_change_response_id')
                ->nullable()
                ->after('request_for_quote_id')
                ->constrained('purchase_order_responses')
                ->nullOnDelete();
            $table->index('pending_change_response_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropForeignIdFor('purchase_order_responses', 'pending_change_response_id');
            $table->dropIndex(['pending_change_response_id']);
        });
    }
};
