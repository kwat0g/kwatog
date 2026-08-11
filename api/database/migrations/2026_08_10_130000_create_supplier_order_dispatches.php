<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_order_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->string('channel', 60)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique('purchase_order_id');
            $table->index(['status', 'last_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_dispatches');
    }
};
