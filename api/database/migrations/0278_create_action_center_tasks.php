<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_center_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 190)->unique();
            $table->string('state', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['state', 'snoozed_until']);
            $table->index('assigned_to');
        });

        Schema::create('action_center_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('action_center_tasks')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('acted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_center_task_events');
        Schema::dropIfExists('action_center_tasks');
    }
};
