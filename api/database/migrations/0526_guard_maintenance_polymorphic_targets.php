<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GUARDS = [
        'maintenance_schedules' => 'maintenance_schedules_target_check',
        'maintenance_work_orders' => 'maintenance_work_orders_target_check',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::GUARDS as $table => $constraint) {
            DB::statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (maintainable_type IN ('machine', 'mold') AND maintainable_id > 0)",
                $table,
                $constraint,
            ));
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_maintenance_target_reference()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    target_active boolean;
BEGIN
    IF NEW.maintainable_type NOT IN ('machine', 'mold') THEN
        RAISE EXCEPTION 'Invalid maintenance target type %', NEW.maintainable_type
            USING ERRCODE = '23514';
    END IF;

    IF NEW.maintainable_type = 'machine' THEN
        SELECT EXISTS (SELECT 1 FROM machines WHERE id = NEW.maintainable_id AND deleted_at IS NULL)
        INTO target_active;
    ELSE
        SELECT EXISTS (SELECT 1 FROM molds WHERE id = NEW.maintainable_id AND deleted_at IS NULL)
        INTO target_active;
    END IF;

    IF NOT target_active THEN
        RAISE EXCEPTION 'Maintenance target %:% does not exist or is deleted', NEW.maintainable_type, NEW.maintainable_id
            USING ERRCODE = '23503';
    END IF;
    RETURN NEW;
END;
$fn$;

CREATE TRIGGER maintenance_schedules_target_reference
BEFORE INSERT OR UPDATE OF maintainable_type, maintainable_id ON maintenance_schedules
FOR EACH ROW EXECUTE FUNCTION validate_maintenance_target_reference();

CREATE TRIGGER maintenance_work_orders_target_reference
BEFORE INSERT OR UPDATE OF maintainable_type, maintainable_id ON maintenance_work_orders
FOR EACH ROW EXECUTE FUNCTION validate_maintenance_target_reference();

CREATE OR REPLACE FUNCTION prevent_maintenance_target_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF TG_TABLE_NAME = 'machines' THEN
        IF EXISTS (SELECT 1 FROM maintenance_schedules WHERE maintainable_type = 'machine' AND maintainable_id = OLD.id)
            OR EXISTS (SELECT 1 FROM maintenance_work_orders WHERE maintainable_type = 'machine' AND maintainable_id = OLD.id) THEN
            RAISE EXCEPTION 'Machine % is referenced by maintenance history', OLD.id USING ERRCODE = '23503';
        END IF;
    ELSE
        IF EXISTS (SELECT 1 FROM maintenance_schedules WHERE maintainable_type = 'mold' AND maintainable_id = OLD.id)
            OR EXISTS (SELECT 1 FROM maintenance_work_orders WHERE maintainable_type = 'mold' AND maintainable_id = OLD.id) THEN
            RAISE EXCEPTION 'Mold % is referenced by maintenance history', OLD.id USING ERRCODE = '23503';
        END IF;
    END IF;
    RETURN OLD;
END;
$fn$;

CREATE TRIGGER machines_maintenance_target_delete
BEFORE DELETE ON machines FOR EACH ROW EXECUTE FUNCTION prevent_maintenance_target_delete();

CREATE TRIGGER molds_maintenance_target_delete
BEFORE DELETE ON molds FOR EACH ROW EXECUTE FUNCTION prevent_maintenance_target_delete();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::GUARDS as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS maintenance_schedules_target_reference ON maintenance_schedules;
DROP TRIGGER IF EXISTS maintenance_work_orders_target_reference ON maintenance_work_orders;
DROP TRIGGER IF EXISTS machines_maintenance_target_delete ON machines;
DROP TRIGGER IF EXISTS molds_maintenance_target_delete ON molds;
DROP FUNCTION IF EXISTS validate_maintenance_target_reference();
DROP FUNCTION IF EXISTS prevent_maintenance_target_delete();
SQL);
    }
};
