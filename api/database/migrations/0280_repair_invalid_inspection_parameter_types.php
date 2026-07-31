<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ComprehensiveDemoSeeder wrote parameter_type = 'weight', which is not a case
 * of App\Modules\Quality\Enums\InspectionParameterType. Reading any affected
 * row back through the model cast threw
 *
 *   "weight" is not a valid backing value for enum InspectionParameterType
 *
 * which 500'd every inspection-spec and inspection detail endpoint that touched
 * one. A weight check is numeric with a tolerance window, so it belongs on the
 * Dimensional path (see the enum's docblock). Repair the rows in place; the
 * seeder itself is fixed in the same commit.
 */
return new class extends Migration
{
    private const TABLES = ['inspection_spec_items', 'inspection_measurements'];

    private const VALID = ['dimensional', 'visual', 'functional'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'parameter_type')) {
                continue;
            }

            DB::table($table)
                ->whereNotIn('parameter_type', self::VALID)
                ->update(['parameter_type' => 'dimensional']);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the original values were invalid and the
        // mapping back to them is not recoverable. No-op rather than
        // re-introducing rows that crash the enum cast.
    }
};
