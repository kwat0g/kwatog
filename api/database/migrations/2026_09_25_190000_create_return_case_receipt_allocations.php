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
        Schema::create('return_case_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_case_id')->constrained('return_cases')->restrictOnDelete();
            $table->foreignId('return_case_line_id')->constrained('return_case_lines')->restrictOnDelete();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->restrictOnDelete();
            $table->foreignId('grn_item_id')->constrained('grn_items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['return_case_line_id', 'grn_item_id'], 'return_case_receipt_line_unique');
            $table->index('return_case_id');
            $table->index('grn_item_id');
            $table->index('goods_receipt_note_id');
        });
        DB::statement('ALTER TABLE return_case_receipt_allocations ADD CONSTRAINT return_case_receipt_positive CHECK (quantity > 0)');

        // Preserve historical single-receipt settlements without invoking
        // application services whose behaviour may change after this migration.
        foreach (DB::table('return_cases')->whereNotNull('resolution_goods_receipt_note_id')->orderBy('id')->get() as $case) {
            $grn = DB::table('goods_receipt_notes')->find($case->resolution_goods_receipt_note_id);
            $items = DB::table('grn_items')->where('goods_receipt_note_id', $grn->id)->orderBy('id')->get();
            foreach (DB::table('return_case_lines')->where('return_case_id', $case->id)->orderBy('id')->get() as $line) {
                $remaining = bcadd((string) ($line->verified_missing_quantity ?? 0), (string) ($line->verified_defective_quantity ?? 0), 3);
                foreach ($items as $item) {
                    if ((int) $item->item_id !== (int) $line->item_id
                        || ((int) $grn->purchase_order_id === (int) $case->purchase_order_id && (int) $item->purchase_order_item_id !== (int) $line->source_po_item_id)) {
                        continue;
                    }
                    $used = (string) DB::table('return_case_receipt_allocations')->where('grn_item_id', $item->id)->sum('quantity');
                    $available = bcsub((string) $item->quantity_accepted, $used, 3);
                    $quantity = bccomp($remaining, $available, 3) <= 0 ? $remaining : $available;
                    if (bccomp($quantity, '0', 3) <= 0) {
                        continue;
                    }
                    DB::table('return_case_receipt_allocations')->insert([
                        'return_case_id' => $case->id, 'return_case_line_id' => $line->id,
                        'goods_receipt_note_id' => $grn->id, 'grn_item_id' => $item->id,
                        'quantity' => $quantity, 'created_by' => $case->created_by,
                        'created_at' => $case->updated_at, 'updated_at' => $case->updated_at,
                    ]);
                    $remaining = bcsub($remaining, $quantity, 3);
                }
                if (bccomp($remaining, '0', 3) > 0) {
                    throw new RuntimeException('Review legacy receipt coverage for '.$case->case_number.' before migrating its settlement.');
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::table('return_case_receipt_allocations')->exists()) {
            throw new RuntimeException('Receipt allocations contain settlement history and cannot be discarded by rollback.');
        }
        Schema::dropIfExists('return_case_receipt_allocations');
    }
};
