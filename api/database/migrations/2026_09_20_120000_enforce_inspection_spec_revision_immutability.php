<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Protect historical inspection-spec evidence from direct row mutation. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inspection_spec_revisions') || ! Schema::hasTable('inspection_spec_items')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_inspection_spec_revision_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'Inspection-spec revisions are immutable';
END;
$fn$;

CREATE OR REPLACE FUNCTION prevent_inspection_spec_item_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF OLD.inspection_spec_id IS DISTINCT FROM NEW.inspection_spec_id
       OR OLD.inspection_spec_revision_id IS DISTINCT FROM NEW.inspection_spec_revision_id
       OR OLD.parameter_name IS DISTINCT FROM NEW.parameter_name
       OR OLD.parameter_type IS DISTINCT FROM NEW.parameter_type
       OR OLD.unit_of_measure IS DISTINCT FROM NEW.unit_of_measure
       OR OLD.nominal_value IS DISTINCT FROM NEW.nominal_value
       OR OLD.tolerance_min IS DISTINCT FROM NEW.tolerance_min
       OR OLD.tolerance_max IS DISTINCT FROM NEW.tolerance_max
       OR OLD.is_critical IS DISTINCT FROM NEW.is_critical
       OR OLD.sort_order IS DISTINCT FROM NEW.sort_order
       OR OLD.notes IS DISTINCT FROM NEW.notes
    THEN
        RAISE EXCEPTION 'Inspection-spec items are immutable';
    END IF;

    RETURN NEW;
END;
$fn$;
SQL);

            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_update ON inspection_spec_revisions');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_delete ON inspection_spec_revisions');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_item_immutable_update ON inspection_spec_items');
            DB::statement(
                'CREATE TRIGGER inspection_spec_revision_immutable_update '
                .'BEFORE UPDATE ON inspection_spec_revisions '
                .'FOR EACH ROW EXECUTE FUNCTION prevent_inspection_spec_revision_mutation()'
            );
            DB::statement(
                'CREATE TRIGGER inspection_spec_revision_immutable_delete '
                .'BEFORE DELETE ON inspection_spec_revisions '
                .'FOR EACH ROW EXECUTE FUNCTION prevent_inspection_spec_revision_mutation()'
            );
            DB::statement(
                'CREATE TRIGGER inspection_spec_item_immutable_update '
                .'BEFORE UPDATE ON inspection_spec_items '
                .'FOR EACH ROW EXECUTE FUNCTION prevent_inspection_spec_item_mutation()'
            );

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_delete');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_item_immutable_update');
            DB::statement(
                "CREATE TRIGGER inspection_spec_revision_immutable_update "
                .'BEFORE UPDATE ON inspection_spec_revisions '
                .'BEGIN SELECT RAISE(ABORT, \'Inspection-spec revisions are immutable\'); END'
            );
            DB::statement(
                "CREATE TRIGGER inspection_spec_revision_immutable_delete "
                .'BEFORE DELETE ON inspection_spec_revisions '
                .'BEGIN SELECT RAISE(ABORT, \'Inspection-spec revisions are immutable\'); END'
            );
            DB::statement(
                "CREATE TRIGGER inspection_spec_item_immutable_update "
                .'BEFORE UPDATE ON inspection_spec_items '
                .'WHEN NEW.inspection_spec_id IS NOT OLD.inspection_spec_id '
                .'OR NEW.inspection_spec_revision_id IS NOT OLD.inspection_spec_revision_id '
                .'OR NEW.parameter_name IS NOT OLD.parameter_name '
                .'OR NEW.parameter_type IS NOT OLD.parameter_type '
                .'OR NEW.unit_of_measure IS NOT OLD.unit_of_measure '
                .'OR NEW.nominal_value IS NOT OLD.nominal_value '
                .'OR NEW.tolerance_min IS NOT OLD.tolerance_min '
                .'OR NEW.tolerance_max IS NOT OLD.tolerance_max '
                .'OR NEW.is_critical IS NOT OLD.is_critical '
                .'OR NEW.sort_order IS NOT OLD.sort_order '
                .'OR NEW.notes IS NOT OLD.notes '
                ."BEGIN SELECT RAISE(ABORT, 'Inspection-spec items are immutable'); END"
            );

            return;
        }

        throw new RuntimeException('Inspection-spec immutability requires PostgreSQL or SQLite.');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_update ON inspection_spec_revisions');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_delete ON inspection_spec_revisions');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_item_immutable_update ON inspection_spec_items');
            DB::statement('DROP FUNCTION IF EXISTS prevent_inspection_spec_revision_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS prevent_inspection_spec_item_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_revision_immutable_delete');
            DB::statement('DROP TRIGGER IF EXISTS inspection_spec_item_immutable_update');
        }
    }
};
