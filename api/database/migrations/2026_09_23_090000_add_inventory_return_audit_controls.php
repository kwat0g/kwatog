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
        Schema::table('stock_count_items', function (Blueprint $table): void {
            $table->foreignId('verified_by')->nullable()->after('counted_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->json('idempotency_response')->nullable();
            $table->unique(['received_by', 'idempotency_key'], 'goods_receipt_notes_receiver_idempotency_unique');
        });

        Schema::table('material_issue_slips', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->unique(['created_by', 'idempotency_key'], 'material_issue_slips_creator_idempotency_unique');
        });

        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
        });

        // Backfill only unambiguous historical line-to-movement matches. Some
        // older customer returns have both quarantine and release movements at
        // the same quantity; those remain unlinked rather than guessing.
        DB::statement(<<<'SQL'
            WITH candidates AS (
                SELECT line.id AS line_id, movement.id AS movement_id,
                       COUNT(*) OVER (PARTITION BY line.id) AS line_matches,
                       COUNT(*) OVER (PARTITION BY movement.id) AS movement_matches
                FROM return_request_items AS line
                JOIN stock_movements AS movement
                  ON movement.reference_type = 'return_request'
                 AND movement.reference_id = line.return_request_id
                 AND movement.item_id = line.item_id
                 AND movement.quantity = line.stock_movement_quantity
                WHERE line.stock_movement_quantity > 0
                  AND line.stock_movement_id IS NULL
            )
            UPDATE return_request_items AS line
               SET stock_movement_id = candidates.movement_id
              FROM candidates
             WHERE candidates.line_id = line.id
               AND candidates.line_matches = 1
               AND candidates.movement_matches = 1
        SQL);
    }

    public function down(): void
    {
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_movement_id');
        });

        Schema::table('material_issue_slips', function (Blueprint $table): void {
            $table->dropUnique('material_issue_slips_creator_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_notes_receiver_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint', 'idempotency_response']);
        });

        Schema::table('stock_count_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
        });
    }
};
