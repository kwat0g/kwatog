<?php

declare(strict_types=1);

use App\Common\Models\ChainListenerRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate queue lifecycle from the business result of a stateful listener.
 * A completed queue job may be a real side-effect, a safe idempotent no-op, or
 * an explicit manual handoff; operators need those meanings independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chain_listener_runs')) {
            return;
        }

        if (! Schema::hasColumn('chain_listener_runs', 'outcome_status')) {
            Schema::table('chain_listener_runs', function (Blueprint $table): void {
                $table->string('outcome_status', 30)->nullable()->index();
                $table->string('outcome_code', 100)->nullable();
                $table->text('outcome_message')->nullable();
                $table->timestamp('outcome_at')->nullable();
            });
        }

        $now = now();
        DB::table('chain_listener_runs')
            ->whereNull('outcome_status')
            ->where('status', ChainListenerRun::STATUS_COMPLETED)
            ->update([
                'outcome_status' => ChainListenerRun::OUTCOME_COMPLETED,
                'outcome_code' => 'legacy_completed',
                'outcome_at' => DB::raw('completed_at'),
                'updated_at' => $now,
            ]);

        DB::table('chain_listener_runs')
            ->whereNull('outcome_status')
            ->where('status', ChainListenerRun::STATUS_FAILED)
            ->update([
                'outcome_status' => ChainListenerRun::OUTCOME_FAILED,
                'outcome_code' => 'legacy_failed',
                'outcome_message' => DB::raw('last_error'),
                'outcome_at' => DB::raw('failed_at'),
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('chain_listener_runs')
            || ! Schema::hasColumn('chain_listener_runs', 'outcome_status')) {
            return;
        }

        Schema::table('chain_listener_runs', function (Blueprint $table): void {
            $table->dropIndex(['outcome_status']);
            $table->dropColumn([
                'outcome_status',
                'outcome_code',
                'outcome_message',
                'outcome_at',
            ]);
        });
    }
};
