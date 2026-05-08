<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series F — Task F7. Company-wide activity events.
 *
 * Captures business-relevant high-level events (chain milestones,
 * automation runs, approval terminations) that the audit_logs table
 * does not naturally surface. Read-mostly: written by listeners and
 * the ActivityFeedService::record() helper, queried by the admin
 * activity dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();

            // Classification
            $table->string('type', 30);              // transaction|approval|automation|alert|auth
            $table->string('action', 50);            // free-form verb (e.g. "payroll_finalized")

            // Actor (nullable for system-emitted events)
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('user'); // 'user'|'system'

            // Subject (the record this event is about). NOT a real FK —
            // it can point at any of dozens of tables.
            $table->string('subject_type', 100)->nullable();
            $table->bigInteger('subject_id')->nullable();

            // Display
            $table->string('summary', 200);
            $table->json('detail')->nullable();
            $table->string('link', 200)->nullable();
            $table->string('severity', 10)->default('info'); // info|success|warning|danger

            // Audit context
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('type');
            $table->index('actor_user_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
