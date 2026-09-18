<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE production_schedules
              ADD CONSTRAINT production_schedules_mold_no_overlap
              EXCLUDE USING gist (
                mold_id WITH =,
                tsrange(scheduled_start, scheduled_end) WITH &&
              )
              WHERE (mold_id IS NOT NULL AND status IN ('pending', 'confirmed', 'executed'));
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE production_schedules DROP CONSTRAINT IF EXISTS production_schedules_mold_no_overlap;');
    }
};
