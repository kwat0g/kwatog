<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_provenance_type_check');
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_provenance_type_check CHECK (provenance_type IN ('stock','service','landed_cost'))");
        DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_landed_cost_source_check CHECK (provenance_type <> \'landed_cost\' OR landed_cost_shipment_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_landed_cost_source_check');
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_provenance_type_check');
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_provenance_type_check CHECK (provenance_type IN ('stock','service'))");
    }
};
