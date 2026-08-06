<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * F-05 / F-16 / F-19 — movement GL back-link, QC FK, and sequence keys.
 *
 * 1. F-05 — stock_movements.journal_entry_id back-links every value-changing
 *    movement to the journal entry it posts, so the inventory ledger can never
 *    drift from the GL without a trace.
 * 2. F-19 — goods_receipt_notes.qc_inspection_id gains a real FK (it was a
 *    plain integer, so a deleted inspection could leave an orphan reference).
 * 3. F-16 — documents.sequence_config gains the missing `material_issue` and
 *    `transfer_order` keys. MaterialIssueService was burning a GRN number via
 *    `sequences->generate('grn')` because its own key did not exist, and
 *    TransferOrderService::generate('transfer_order') would throw "Unknown
 *    document type" outright.
 * 4. F-05 — the two offset accounts used by MovementGlPostingService are
 *    seeded with COA defaults (5010 material consumption, 5000 cost of goods).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')
                ->references('id')->on('journal_entries')
                ->nullOnDelete();
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->foreign('qc_inspection_id')
                ->references('id')->on('inspections')
                ->nullOnDelete();
        });

        $config = json_decode((string) DB::table('settings')
            ->where('key', 'documents.sequence_config')
            ->value('value'), true) ?? [];

        $monthly = static fn (string $prefix): array => ['prefix' => $prefix, 'reset' => 'monthly', 'pad' => 4];
        $config['material_issue'] = $monthly('MI');
        $config['transfer_order'] = $monthly('TO');

        DB::table('settings')->where('key', 'documents.sequence_config')
            ->update(['value' => json_encode($config), 'updated_at' => now()]);

        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.accounts.material_consumption_code',
            'value' => '"5010"',
            'group' => 'accounting',
            'label' => 'Material Consumption Account',
            'description' => 'Offset account debited when raw materials are issued to or scrapped by production.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.accounts.inventory_adjustment_code',
            'value' => '"5000"',
            'group' => 'accounting',
            'label' => 'Inventory Adjustment Account',
            'description' => 'Offset account used when stock is adjusted in or out (cycle counts, corrections).',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn('journal_entry_id');
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropForeign(['qc_inspection_id']);
        });

        DB::table('settings')->whereIn('key', [
            'accounting.accounts.material_consumption_code',
            'accounting.accounts.inventory_adjustment_code',
        ])->delete();
    }
};
