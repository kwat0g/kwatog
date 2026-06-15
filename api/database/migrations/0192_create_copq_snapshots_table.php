<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.6.A — COPQ rollup snapshots. One row per calendar month.
 * Written by CopqService::snapshot() and by the copq:snap-monthly cron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copq_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('prevention_cost',         15, 2)->default(0);
            $table->decimal('appraisal_cost',          15, 2)->default(0);
            $table->decimal('internal_scrap_cost',     15, 2)->default(0);
            $table->decimal('internal_rework_cost',    15, 2)->default(0);
            $table->decimal('external_return_cost',    15, 2)->default(0);
            $table->decimal('external_complaint_cost', 15, 2)->default(0);
            $table->decimal('total_cost',              15, 2)->default(0);
            $table->jsonb('breakdown')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
            $table->index('total_cost');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copq_snapshots');
    }
};
