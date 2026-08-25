<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attendance DTR can consume only one holiday type for a date. The previous
 * (date, name) key allowed two active rows on one date and mapWithKeys() then
 * silently selected whichever row the database returned last.
 *
 * Archived rows remain recoverable and do not reserve a date; the partial
 * unique index is the database backstop for the active-record invariant.
 */
return new class extends Migration
{
    private const INDEX = 'holidays_active_date_unique';

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Active holiday date uniqueness requires a partial-index capable driver; received {$driver}."
            );
        }

        $duplicates = DB::table('holidays')
            ->select('date')
            ->whereNull('deleted_at')
            ->groupBy('date')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isNotEmpty()) {
            $dates = $duplicates->pluck('date')->implode(', ');
            throw new RuntimeException(
                'Cannot add '.self::INDEX.': multiple active holidays exist on '.$dates.'. Resolve the business records before retrying; this migration never deletes or deduplicates them.'
            );
        }

        // 0023_create_holidays_table.php declared unique(['date','name']), which
        // Postgres backs with a UNIQUE CONSTRAINT owning the index of the same
        // name. DROP INDEX is refused there (SQLSTATE 2BP01) and must go through
        // ALTER TABLE; SQLite has only the index.
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE holidays DROP CONSTRAINT IF EXISTS holidays_date_name_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS holidays_date_name_unique');
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON holidays (date) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        // Restore the same kind of object up() removed, so a down/up cycle is
        // idempotent rather than leaving a bare index a later up() cannot drop.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE holidays ADD CONSTRAINT holidays_date_name_unique UNIQUE (date, name)');
        } else {
            DB::statement('CREATE UNIQUE INDEX holidays_date_name_unique ON holidays (date, name)');
        }
    }
};
