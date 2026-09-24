<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $overlap = DB::selectOne(<<<'SQL'
            SELECT a.id AS first_id, b.id AS second_id
            FROM fiscal_years a
            JOIN fiscal_years b ON a.id < b.id
              AND a.start_date <= b.end_date
              AND b.start_date <= a.end_date
            LIMIT 1
        SQL);
        if ($overlap) {
            throw new RuntimeException(sprintf(
                'Cannot prevent fiscal-year overlap: rows %s and %s overlap.',
                $overlap->first_id,
                $overlap->second_id,
            ));
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years
            ADD CONSTRAINT fiscal_years_date_range_no_overlap
            EXCLUDE USING gist (
                daterange(start_date, end_date + 1, '[)') WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_years DROP CONSTRAINT IF EXISTS fiscal_years_date_range_no_overlap');
        }
    }
};
