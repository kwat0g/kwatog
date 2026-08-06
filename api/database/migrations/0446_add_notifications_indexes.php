<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the two `notifications` query shapes nothing covered, and drop one
 * index that had become dead weight.
 *
 * Existing state before this migration:
 *   0011  morphs()  → (notifiable_type, notifiable_id)
 *   0108            → (notifiable_id, read_at)
 *   0171            → (notifiable_type, notifiable_id, read_at)
 *
 * That leaves two hot paths unserved:
 *
 *   1. The bell and the list page:
 *        WHERE notifiable_type = ? AND notifiable_id = ? ORDER BY created_at DESC
 *      0171 finds the user's rows, then Postgres sorts every one of them to
 *      return 8. Appending created_at turns the ORDER BY into an index walk,
 *      so cost tracks the page size instead of the user's history.
 *
 *   2. `notifications:prune --days=90`, nightly:
 *        WHERE read_at IS NOT NULL AND created_at < ?
 *      No index leads with created_at, so this was a full sequential scan of
 *      the entire table every night. Partial on the read rows — the only rows
 *      prune can ever delete — lets the planner reach just the aged tail: on a
 *      60k-row table with a realistic 1% backlog, a bitmap scan touching 10
 *      heap blocks rather than every page.
 *
 *      Verified via EXPLAIN. Note the planner still (correctly) picks a
 *      sequential scan on the *first* run against a large never-pruned
 *      backlog, where most of the table qualifies and random I/O would cost
 *      more. The index earns its keep from the second run onward, which is
 *      every run in steady state.
 *
 * The unread badge (`read_at IS NULL`, refetched every 30s per open tab) is
 * already served by 0171 and deliberately gets nothing new here; a partial
 * unread index would only duplicate it, and every extra index is write
 * amplification on an insert path that fans out one row per recipient.
 *
 * For the same reason 0011's morphs index is dropped: it is an exact leading
 * prefix of both 0171's index and the composite added here, so it can serve no
 * query they cannot while still costing an update on every insert and delete.
 * `down()` restores it.
 */
return new class extends Migration
{
    private const COMPOSITE = 'notifications_notifiable_created_at_index';
    private const READ_AGE  = 'notifications_read_created_at_partial_index';
    private const MORPHS    = 'notifications_notifiable_type_notifiable_id_index';

    public function up(): void
    {
        Schema::table('notifications', function ($table): void {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], self::COMPOSITE);
        });

        // Partial indexes are Postgres-only. On any other driver the prune
        // scan stays as it was rather than paying for a full created_at index
        // that only a nightly command reads.
        if ($this->isPostgres()) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON notifications (created_at) WHERE read_at IS NOT NULL',
                self::READ_AGE,
            ));
        }

        $this->dropIndexIfExists(self::MORPHS);
    }

    public function down(): void
    {
        if (! $this->indexExists(self::MORPHS)) {
            Schema::table('notifications', function ($table): void {
                $table->index(['notifiable_type', 'notifiable_id'], self::MORPHS);
            });
        }

        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::READ_AGE);
        }

        $this->dropIndexIfExists(self::COMPOSITE);
    }

    private function dropIndexIfExists(string $name): void
    {
        if (! $this->indexExists($name)) {
            return;
        }

        Schema::table('notifications', function ($table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $name): bool
    {
        foreach (Schema::getIndexes('notifications') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
