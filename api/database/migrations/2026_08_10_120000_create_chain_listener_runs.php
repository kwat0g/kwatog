<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listener-level execution ledger for durable domain events.
 *
 * chain_step_runs answers "was the event published?". This table answers
 * "did each queued cross-module listener complete, retry, or dead-letter?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chain_listener_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('outbox_id')->index();
            $table->string('job_uuid', 255)->unique();
            $table->string('event_type', 255);
            $table->string('listener_class', 255);
            $table->string('listener_method', 100)->default('handle');
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['outbox_id', 'status']);
            $table->index(['listener_class', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chain_listener_runs');
    }
};
