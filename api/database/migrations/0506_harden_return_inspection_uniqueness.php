<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Allow one return inspection per product while retaining idempotency. */
return new class extends Migration
{
    public function up(): void
    {
        $this->requirePartialIndexDriver();

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement(
            'CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique '
            .'ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0)) '
            ."WHERE stage <> 'incoming' AND stage <> 'outgoing'"
        );
    }

    public function down(): void
    {
        $this->requirePartialIndexDriver();

        $duplicate = DB::table('inspections')
            ->whereRaw("stage <> 'incoming' AND stage <> 'outgoing'")
            ->select('stage', 'entity_type', 'entity_id')
            ->groupBy('stage', 'entity_type', 'entity_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException(
                'Cannot restore entity-only inspection uniqueness: multiple product inspections exist for one return entity.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement(
            'CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique '
            .'ON inspections (stage, entity_type, entity_id) '
            ."WHERE stage <> 'incoming' AND stage <> 'outgoing'"
        );
    }

    private function requirePartialIndexDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Return inspection uniqueness requires PostgreSQL or SQLite.');
        }
    }
};
