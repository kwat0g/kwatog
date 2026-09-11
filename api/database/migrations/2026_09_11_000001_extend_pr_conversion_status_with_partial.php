<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial PR→PO conversion (2026-09-11).
 *
 * A PR whose lines are only partly sourceable is no longer dumped whole to
 * `manual_required`. `convertFromPr()` now creates POs for whatever lines it
 * can and records the remainder, so `po_conversion_status` needs a `partial`
 * value. The CHECK constraint was created by 2026_08_13_220000; this migration
 * must therefore be timestamp-named after it (a 0NNN_ file would sort before
 * every 2026_* migration and run before the constraint exists).
 *
 * Mirrors that migration's dual PostgreSQL/SQLite handling and refuses to
 * narrow the guard while partial rows exist.
 */
return new class extends Migration
{
    private const TABLE = 'purchase_requests';

    private const COLUMN = 'po_conversion_status';

    private const WITH_PARTIAL = ['not_started', 'pending', 'manual_required', 'converted', 'partial'];

    private const WITHOUT_PARTIAL = ['not_started', 'pending', 'manual_required', 'converted'];

    public function up(): void
    {
        $this->replaceConstraint(self::WITH_PARTIAL);
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->where(self::COLUMN, 'partial')->exists()) {
            throw new RuntimeException(
                'Cannot narrow purchase_requests.po_conversion_status: partial rows exist. Convert or reset them first.',
            );
        }

        $this->replaceConstraint(self::WITHOUT_PARTIAL);
    }

    private function replaceConstraint(array $allowed): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $name = substr(self::TABLE.'_'.self::COLUMN.'_lifecycle_check', 0, 63);
        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowed,
        ));

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "'.self::TABLE.'" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            DB::statement('ALTER TABLE "'.self::TABLE.'" ADD CONSTRAINT "'.$name.'" CHECK ("'.self::COLUMN.'" IN ('.$values.') OR "'.self::COLUMN.'" IS NULL)');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
        DB::statement('CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "'.self::TABLE.'" WHEN NEW."'.self::COLUMN.'" IS NOT NULL AND NEW."'.self::COLUMN.'" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid '.self::TABLE.'.'.self::COLUMN.'\'); END');
        DB::statement('CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "'.self::COLUMN.'" ON "'.self::TABLE.'" WHEN NEW."'.self::COLUMN.'" IS NOT NULL AND NEW."'.self::COLUMN.'" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid '.self::TABLE.'.'.self::COLUMN.'\'); END');
    }
};
