<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut — the COPQ (cost of poor quality) module was removed. Its
 * snapshot table has no inbound foreign keys, and every consumer was
 * unwired first (dashboard panels, KPI definition, cron, event listener,
 * notification catalog, settings).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('copq_snapshots');
    }

    public function down(): void
    {
        Schema::create('copq_snapshots', function ($table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('scrap_cost', 15, 2)->default(0);
            $table->decimal('rework_cost', 15, 2)->default(0);
            $table->decimal('return_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
        });
    }
};
