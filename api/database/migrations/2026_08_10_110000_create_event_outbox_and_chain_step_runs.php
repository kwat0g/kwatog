<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable publication boundary for cross-module domain events.
 *
 * Business transactions write the outbox row before commit. A queue outage or
 * worker crash can therefore delay publication, but cannot make the event
 * disappear between the business write and the queue push. chain_step_runs is
 * the durable operational ledger for the chain transitions represented by
 * those messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 255);
            $table->json('payload');
            $table->string('dedupe_key', 255)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });

        Schema::create('chain_step_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('outbox_id')->unique();
            $table->string('chain', 80);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_hash_id', 80)->nullable();
            $table->string('step', 100);
            $table->string('event_type', 255);
            $table->string('event_key', 255);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            // A step may legitimately recur (for example pause → resume →
            // pause), so the event key—not only the step name—is unique.
            $table->unique(
                ['chain', 'entity_type', 'entity_id', 'step', 'event_key'],
                'chain_step_runs_event_unique',
            );
            $table->index(['entity_type', 'entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chain_step_runs');
        Schema::dropIfExists('event_outbox');
    }
};
