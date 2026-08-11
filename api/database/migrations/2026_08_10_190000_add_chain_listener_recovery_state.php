<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the operator disposition and replay lineage needed to recover a
 * listener without mutating its historical queue result.
 *
 * A replay creates a new chain_listener_runs row. The source row therefore
 * remains an immutable execution fact while resolution notes and replay
 * lineage remain queryable for audit and incident review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chain_listener_runs')) {
            return;
        }

        Schema::table('chain_listener_runs', function (Blueprint $table): void {
            $table->string('resolution_status', 20)->nullable()->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamp('replay_requested_at')->nullable();
            $table->foreignId('replay_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('replayed_from_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chain_listener_runs')) {
            return;
        }

        Schema::table('chain_listener_runs', function (Blueprint $table): void {
            $table->dropForeign(['resolved_by']);
            $table->dropForeign(['replay_requested_by']);
            $table->dropIndex(['resolution_status']);
            $table->dropIndex(['replayed_from_id']);
            $table->dropColumn([
                'resolution_status',
                'resolution_note',
                'resolved_by',
                'resolved_at',
                'replay_count',
                'replay_requested_at',
                'replay_requested_by',
                'replayed_from_id',
            ]);
        });
    }
};
