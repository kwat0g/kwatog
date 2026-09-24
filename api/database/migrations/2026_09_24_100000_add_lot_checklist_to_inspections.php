<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add lot-checklist mode to inspections for incoming QC with large batches.
 *
 * inspection_mode: 'per_unit' (default, backward-compatible) or 'lot_checklist'
 *   - per_unit: scaffold sample_size × parameters rows (existing behavior)
 *   - lot_checklist: scaffold checklist rows + measured_pieces piece rows
 *
 * sample_defect_count: count of defective pieces in the AQL sample (lot_checklist only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('inspection_mode', 20)->default('per_unit')->after('status');
            $table->integer('sample_defect_count')->nullable()->after('defect_count');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "inspections" ADD CONSTRAINT "inspections_inspection_mode_check" '.
                'CHECK (inspection_mode IN (\'per_unit\', \'lot_checklist\'))'
            );
            DB::statement(
                'ALTER TABLE "inspections" ADD CONSTRAINT "inspections_sample_defect_count_check" '.
                'CHECK (sample_defect_count IS NULL OR sample_defect_count >= 0)'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "inspections" DROP CONSTRAINT IF EXISTS "inspections_inspection_mode_check"');
            DB::statement('ALTER TABLE "inspections" DROP CONSTRAINT IF EXISTS "inspections_sample_defect_count_check"');
        }

        Schema::table('inspections', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropColumn(['inspection_mode', 'sample_defect_count']);
        });
    }
};
