<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the newest active copy. Older copies remain auditable as soft-deleted rows.
        DB::statement(<<<'SQL'
            UPDATE shipment_documents AS older
            SET deleted_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE older.deleted_at IS NULL
              AND EXISTS (
                  SELECT 1
                  FROM shipment_documents AS newer
                  WHERE newer.shipment_id = older.shipment_id
                    AND newer.document_type = older.document_type
                    AND newer.deleted_at IS NULL
                    AND newer.id > older.id
              )
        SQL);

        DB::statement(
            'CREATE UNIQUE INDEX shipment_documents_active_type_unique '
            .'ON shipment_documents (shipment_id, document_type) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shipment_documents_active_type_unique');
    }
};
