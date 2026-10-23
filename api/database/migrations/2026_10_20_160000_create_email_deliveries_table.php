<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_key', 64)->unique();
            $table->string('email_type', 255);
            $table->jsonb('recipients');
            $table->text('subject')->nullable();
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 255)->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
