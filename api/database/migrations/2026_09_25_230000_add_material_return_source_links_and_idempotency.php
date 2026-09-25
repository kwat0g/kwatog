<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->unique('idempotency_key', 'stock_movements_idempotency_key_unique');
        });

        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->foreignId('stock_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();
            $table->unique('stock_movement_id', 'mis_items_stock_movement_unique');
        });

        // Old manual issues predate their line-to-movement key. Link only an
        // exact, one-to-one evidence match. Repeated identical historical rows
        // remain null for explicit Warehouse reconciliation rather than
        // assigning stock/cost/lot history by guesswork.
        DB::statement(<<<'SQL'
            WITH exact_matches AS (
                SELECT
                    line.id AS line_id,
                    movement.id AS movement_id,
                    COUNT(*) OVER (PARTITION BY line.id) AS line_match_count,
                    COUNT(*) OVER (PARTITION BY movement.id) AS movement_match_count
                FROM material_issue_slip_items AS line
                JOIN material_issue_slips AS slip
                  ON slip.id = line.material_issue_slip_id
                JOIN stock_movements AS movement
                  ON movement.movement_type = 'material_issue'
                 AND movement.reference_type = 'material_issue_slip'
                 AND movement.reference_id = slip.id
                 AND movement.item_id = line.item_id
                 AND movement.from_location_id = line.location_id
                 AND movement.to_location_id IS NULL
                 AND movement.quantity = line.quantity_issued
                 AND movement.unit_cost = line.unit_cost
                 AND movement.total_cost = line.total_cost
                 AND movement.lot_number IS NOT DISTINCT FROM line.lot_number
                WHERE line.stock_movement_id IS NULL
            )
            UPDATE material_issue_slip_items AS line
               SET stock_movement_id = exact_matches.movement_id
              FROM exact_matches
             WHERE exact_matches.line_id = line.id
               AND exact_matches.line_match_count = 1
               AND exact_matches.movement_match_count = 1
            SQL);
    }

    public function down(): void
    {
        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->dropUnique('mis_items_stock_movement_unique');
            $table->dropForeign(['stock_movement_id']);
            $table->dropColumn('stock_movement_id');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('stock_movements_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
