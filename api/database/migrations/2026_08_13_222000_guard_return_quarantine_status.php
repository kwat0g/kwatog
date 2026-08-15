<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-033/F-008 bridge for installations that applied the lifecycle inventory
 * before the return quarantine lineage migration was present.
 */
return new class extends Migration
{
    private const NAME = 'return_request_items_quarantine_status_lifecycle_check';

    public function up(): void
    {
        if (! Schema::hasTable('return_request_items') || ! Schema::hasColumn('return_request_items', 'quarantine_status')) {
            return;
        }

        $driver = DB::getDriverName();
        $invalid = DB::table('return_request_items')
            ->whereNotNull('quarantine_status')
            ->whereNotIn('quarantine_status', ['held', 'released', 'scrapped'])
            ->distinct()
            ->pluck('quarantine_status');
        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Cannot guard return quarantine status; unsupported values: '.$invalid->implode(', '));
        }

        if ($driver === 'pgsql') {
            $exists = DB::table('pg_constraint as c')
                ->join('pg_class as r', 'r.oid', '=', 'c.conrelid')
                ->where('r.relname', 'return_request_items')
                ->where('c.conname', self::NAME)
                ->exists();
            if (! $exists) {
                DB::statement("ALTER TABLE return_request_items ADD CONSTRAINT ".self::NAME." CHECK (quarantine_status IS NULL OR quarantine_status IN ('held', 'released', 'scrapped'))");
            }
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER IF NOT EXISTS ".self::NAME."_insert_guard BEFORE INSERT ON return_request_items WHEN NEW.quarantine_status IS NOT NULL AND NEW.quarantine_status NOT IN ('held', 'released', 'scrapped') BEGIN SELECT RAISE(ABORT, 'invalid return_request_items.quarantine_status'); END");
            DB::statement("CREATE TRIGGER IF NOT EXISTS ".self::NAME."_update_guard BEFORE UPDATE OF quarantine_status ON return_request_items WHEN NEW.quarantine_status IS NOT NULL AND NEW.quarantine_status NOT IN ('held', 'released', 'scrapped') BEGIN SELECT RAISE(ABORT, 'invalid return_request_items.quarantine_status'); END");
            return;
        }

        throw new RuntimeException("Lifecycle status constraints require PostgreSQL or SQLite; received {$driver}.");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE return_request_items DROP CONSTRAINT IF EXISTS '.self::NAME);
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::NAME.'_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS '.self::NAME.'_update_guard');
        }
    }
};
