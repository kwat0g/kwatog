<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['shipments', 'shipment_documents', 'containers', 'shipment_landed_costs'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_shipment_parent_immutability()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status IN ('customs', 'cleared', 'received', 'cancelled') THEN
            RAISE EXCEPTION 'Customs and terminal shipments are immutable';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'cancelled' THEN
        RAISE EXCEPTION 'Received and cancelled shipments are immutable';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM goods_receipt_notes receipt
        JOIN grn_items line ON line.goods_receipt_note_id = receipt.id
        WHERE receipt.shipment_id = OLD.id
          AND line.quantity_accepted > 0
    ) AND (to_jsonb(OLD) - ARRAY['updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['updated_at']) THEN
        RAISE EXCEPTION 'Shipment metadata is immutable after GRN acceptance';
    END IF;

    IF OLD.status = 'received' THEN
        IF EXISTS (
            SELECT 1
            FROM goods_receipt_notes receipt
            JOIN grn_items line ON line.goods_receipt_note_id = receipt.id
            WHERE receipt.shipment_id = OLD.id
              AND line.quantity_accepted > 0
        ) OR (to_jsonb(OLD) - ARRAY[
            'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee',
            'other_charges', 'landed_cost_total', 'allocation_method',
            'landed_cost_calculated_at', 'updated_at'
        ]) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY[
            'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee',
            'other_charges', 'landed_cost_total', 'allocation_method',
            'landed_cost_calculated_at', 'updated_at'
        ]) THEN
            RAISE EXCEPTION 'Received shipment evidence is immutable';
        END IF;
    END IF;

    IF OLD.status = 'cleared' THEN
        IF NEW.status IN ('received', 'cancelled') THEN
            IF (to_jsonb(OLD) - ARRAY['status', 'ata', 'notes', 'updated_at'])
                IS DISTINCT FROM
               (to_jsonb(NEW) - ARRAY['status', 'ata', 'notes', 'updated_at']) THEN
                RAISE EXCEPTION 'Cleared shipment evidence is immutable';
            END IF;
        ELSIF NEW.status = 'cleared' AND NOT EXISTS (
            SELECT 1
            FROM goods_receipt_notes receipt
            JOIN grn_items line ON line.goods_receipt_note_id = receipt.id
            WHERE receipt.shipment_id = OLD.id
              AND line.quantity_accepted > 0
        ) THEN
            IF (to_jsonb(OLD) - ARRAY[
                'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee',
                'other_charges', 'landed_cost_total', 'allocation_method',
                'landed_cost_calculated_at', 'updated_at'
            ]) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY[
                'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee',
                'other_charges', 'landed_cost_total', 'allocation_method',
                'landed_cost_calculated_at', 'updated_at'
            ]) THEN
                RAISE EXCEPTION 'Cleared shipment evidence is immutable';
            END IF;
        ELSE
            RAISE EXCEPTION 'Cleared shipment evidence is immutable';
        END IF;
    END IF;

    IF OLD.status = 'customs' AND NEW.status = 'cleared' THEN
        IF EXISTS (
            SELECT 1
            FROM (VALUES
                ('bill_of_lading'),
                ('commercial_invoice'),
                ('packing_list'),
                ('import_entry'),
                ('boc_release')
            ) AS required(document_type)
            WHERE NOT EXISTS (
                SELECT 1
                FROM shipment_documents document
                WHERE document.shipment_id = OLD.id
                  AND document.document_type = required.document_type
                  AND document.deleted_at IS NULL
            )
        ) OR NOT EXISTS (
            SELECT 1
            FROM containers container
            WHERE container.shipment_id = OLD.id
              AND container.deleted_at IS NULL
        ) THEN
            RAISE EXCEPTION 'Shipment customs evidence is incomplete';
        END IF;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE OR REPLACE FUNCTION enforce_shipment_child_immutability()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    shipment_status text;
    shipment_id_value bigint;
BEGIN
    shipment_id_value := CASE WHEN TG_OP = 'DELETE' THEN OLD.shipment_id ELSE NEW.shipment_id END;
    SELECT status INTO shipment_status FROM shipments WHERE id = shipment_id_value;
    IF shipment_status = 'cancelled'
       OR (shipment_status = 'cleared' AND TG_TABLE_NAME <> 'shipment_landed_costs')
       OR (shipment_status = 'received' AND TG_TABLE_NAME <> 'shipment_landed_costs') THEN
        RAISE EXCEPTION 'Shipment child evidence is immutable after customs clearance';
    END IF;

    IF shipment_status = 'received' AND TG_TABLE_NAME = 'shipment_landed_costs' AND EXISTS (
        SELECT 1
        FROM goods_receipt_notes receipt
        JOIN grn_items line ON line.goods_receipt_note_id = receipt.id
        WHERE receipt.shipment_id = shipment_id_value
          AND line.quantity_accepted > 0
    ) THEN
        RAISE EXCEPTION 'Landed cost is immutable after GRN acceptance';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$fn$;
SQL);

            foreach (['update', 'delete'] as $operation) {
                DB::statement("DROP TRIGGER IF EXISTS shipment_parent_immutable_{$operation} ON shipments");
            }
            DB::statement('CREATE TRIGGER shipment_parent_immutable_update BEFORE UPDATE ON shipments FOR EACH ROW EXECUTE FUNCTION enforce_shipment_parent_immutability()');
            DB::statement('CREATE TRIGGER shipment_parent_immutable_delete BEFORE DELETE ON shipments FOR EACH ROW EXECUTE FUNCTION enforce_shipment_parent_immutability()');

            foreach (['shipment_documents', 'containers', 'shipment_landed_costs'] as $table) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::statement("DROP TRIGGER IF EXISTS shipment_child_immutable_{$operation} ON {$table}");
                    $verb = strtoupper($operation);
                    DB::statement("CREATE TRIGGER shipment_child_immutable_{$operation} BEFORE {$verb} ON {$table} FOR EACH ROW EXECUTE FUNCTION enforce_shipment_child_immutability()");
                }
            }

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_delete');
            DB::statement("CREATE TRIGGER shipment_parent_immutable_update BEFORE UPDATE ON shipments WHEN OLD.status IN ('cleared','received','cancelled') AND NOT (OLD.status = 'cleared' AND NEW.status = 'received') BEGIN SELECT RAISE(ABORT, 'Shipment evidence is immutable'); END");
            DB::statement("CREATE TRIGGER shipment_parent_immutable_delete BEFORE DELETE ON shipments WHEN OLD.status IN ('customs','cleared','received','cancelled') BEGIN SELECT RAISE(ABORT, 'Shipment evidence is immutable'); END");

            foreach (['shipment_documents', 'containers', 'shipment_landed_costs'] as $table) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::statement("DROP TRIGGER IF EXISTS shipment_child_immutable_{$operation}");
                }
                DB::statement("CREATE TRIGGER shipment_child_immutable_insert BEFORE INSERT ON {$table} WHEN (SELECT status FROM shipments WHERE id = NEW.shipment_id) IN ('cleared','received','cancelled') BEGIN SELECT RAISE(ABORT, 'Shipment child evidence is immutable'); END");
                DB::statement("CREATE TRIGGER shipment_child_immutable_update BEFORE UPDATE ON {$table} WHEN (SELECT status FROM shipments WHERE id = NEW.shipment_id) IN ('cleared','received','cancelled') BEGIN SELECT RAISE(ABORT, 'Shipment child evidence is immutable'); END");
                DB::statement("CREATE TRIGGER shipment_child_immutable_delete BEFORE DELETE ON {$table} WHEN (SELECT status FROM shipments WHERE id = OLD.shipment_id) IN ('cleared','received','cancelled') BEGIN SELECT RAISE(ABORT, 'Shipment child evidence is immutable'); END");
            }

            return;
        }

        throw new RuntimeException('Shipment immutability requires PostgreSQL or SQLite.');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_update ON shipments');
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_delete ON shipments');
            foreach (['shipment_documents', 'containers', 'shipment_landed_costs'] as $table) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::statement("DROP TRIGGER IF EXISTS shipment_child_immutable_{$operation} ON {$table}");
                }
            }
            DB::statement('DROP FUNCTION IF EXISTS enforce_shipment_parent_immutability()');
            DB::statement('DROP FUNCTION IF EXISTS enforce_shipment_child_immutability()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS shipment_parent_immutable_delete');
            foreach (['shipment_documents', 'containers', 'shipment_landed_costs'] as $table) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::statement("DROP TRIGGER IF EXISTS shipment_child_immutable_{$operation}");
                }
            }
        }
    }
};
