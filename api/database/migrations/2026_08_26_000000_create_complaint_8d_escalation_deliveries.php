<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_8d_escalation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')
                ->constrained('customer_complaints')
                ->cascadeOnDelete();
            $table->string('tier', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('idempotency_key', 160)->unique();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['complaint_id', 'tier'],
                'complaint_8d_escalation_delivery_unique',
            );
            $table->index(
                ['status', 'last_attempted_at'],
                'complaint_8d_escalation_delivery_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_8d_escalation_deliveries');
    }
};
