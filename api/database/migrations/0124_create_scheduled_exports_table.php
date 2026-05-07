<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series E (Task E2) — scheduled export jobs. A console command
 * (`exports:run-due`) ticks every 5 minutes, dispatches a job per
 * due row, attaches the produced file to a Mailable, and updates
 * last_run_at + next_run_at.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 100);
            // e.g. 'hr.employees', 'payroll.register', 'inventory.valuation'
            $table->string('module', 50);

            // Persisted column selection + filter snapshot.
            $table->json('columns');
            $table->json('filters')->nullable();

            // 'csv' | 'xlsx'
            $table->string('format', 10)->default('xlsx');

            // 'daily' | 'weekly' | 'monthly'
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('day_of_week')->nullable();   // 0–6 for weekly
            $table->unsignedTinyInteger('day_of_month')->nullable();  // 1–31 for monthly
            // HH:MM, runner respects this on each due check.
            $table->string('time_of_day', 5)->default('06:00');

            // Comma-separated list is too brittle — JSON is safer.
            $table->json('recipients');

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('owner_id');
            $table->index('next_run_at');
            $table->index(['is_active', 'next_run_at'], 'scheduled_exports_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_exports');
    }
};
