<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'purchase_requests';
    private const COLUMN = 'po_conversion_status';
    private const VALUES = ['not_started', 'pending', 'manual_required', 'converted', 'partial', 'sourcing_pending'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }
        $values = implode(', ', array_map(static fn (string $value): string => "'{$value}'", self::VALUES));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_requests DROP CONSTRAINT IF EXISTS purchase_requests_po_conversion_status_lifecycle_check');
            DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_po_conversion_status_lifecycle_check CHECK (po_conversion_status IN ({$values}) OR po_conversion_status IS NULL)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_requests DROP CONSTRAINT IF EXISTS purchase_requests_po_conversion_status_lifecycle_check');
            DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_po_conversion_status_lifecycle_check CHECK (po_conversion_status IN ('not_started', 'pending', 'manual_required', 'converted', 'partial') OR po_conversion_status IS NULL)");
        }
    }
};
