<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_actuals_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('outbox_id')->unique();
            $table->uuid('request_id')->unique();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('processed_lines')->default(0);
            $table->unsignedInteger('total_lines')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('outbox_id')->references('id')->on('event_outbox')->cascadeOnDelete();
            $table->index(['fiscal_year_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_actuals_sync_runs');
    }
};
