<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-015 — liveness columns for the MRP run reaper.
 *
 * `started_at` stamps when a run row transitions to Running; the
 * `mrp:reap-stale-runs` command marks rows whose started_at is older than the
 * stale threshold (default 2h) as Failed and cancels their orphan draft
 * auto-PRs. `heartbeat_at` is reserved for finer-grained liveness signalling
 * from long-running batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrp_runs', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('run_at');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('mrp_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'started_at']);
            $table->dropColumn(['started_at', 'heartbeat_at']);
        });
    }
};
