<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS-D.2 — Chain transition audit log.
 *
 * Records every state change on a chain entity (sales-order confirmed,
 * delivery shipped, NCR closed, …). Replaces ad-hoc audit-log usage for
 * chain transitions so the chain history can be reconstructed for any
 * entity by querying one table:
 *
 *   SELECT * FROM chain_events
 *   WHERE entity_type = 'sales_order' AND entity_id = ?
 *   ORDER BY occurred_at ASC;
 *
 * Idempotency_key lets repeat events (e.g. queued listener firing twice
 * after a retry) be deduped — anything that records via
 * ChainEventRecorder passes a deterministic key per logical event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chain_events', function (Blueprint $table) {
            $table->id();
            $table->string('chain_key', 50);                // sales_order, purchase_order, …
            $table->string('entity_type', 100);             // morphMap or class basename
            $table->unsignedBigInteger('entity_id');
            $table->string('event_type', 100);              // confirmed, shipped, invoiced, …
            $table->string('from_state', 50)->nullable();
            $table->string('to_state', 50)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->unique('idempotency_key', 'chain_events_idempotency_key_unique');
            $table->index(['entity_type', 'entity_id']);
            $table->index(['chain_key', 'occurred_at']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chain_events');
    }
};
