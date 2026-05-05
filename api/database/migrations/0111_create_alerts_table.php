<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A2 — Smart Alert Engine. System monitors thresholds continuously and
 * pushes proactive warnings before problems become critical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);                // see AlertType enum
            $table->string('severity', 20);            // critical | warning | info
            $table->string('title', 200);
            $table->text('message');
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_dismissed')->default(false);
            $table->foreignId('dismissed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('notified_email_at')->nullable();
            $table->timestamps();

            $table->index(['is_dismissed', 'severity']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
