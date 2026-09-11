<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-return provenance for system-opened RMAs.
 *
 * A rejected incoming receipt, an NCR close and an MRB return-to-supplier all
 * feed the SAME supplier-return engine now. Each of those paths needs:
 *
 *   source_key                  — the stable dedupe key so a replayed event
 *                                 (a redelivered queued listener, an operator
 *                                 double-click, a re-run command) can never
 *                                 open a second RMA and therefore a second
 *                                 supplier credit. Unique when present.
 *   goods_receipt_note_id       — the receipt the return is grounded in, so the
 *                                 RMA keeps GRN lineage even when it is opened
 *                                 outside the SPA's manual supplier-return form.
 *   reversal_already_applied    — the caller already reduced the GRN/PO
 *                                 received+accepted quantities (a rejected
 *                                 incoming receipt does exactly that in
 *                                 GrnService::reversePoReceipt()). Without this
 *                                 flag the same reduction would run a second
 *                                 time when the RMA is disposed.
 *
 * The per-line flag on return_request_items is the authoritative one read by
 * processSupplierDisposition(); the header flag is a summary so a list/detail
 * read does not have to aggregate the lines.
 *
 * Timestamp-named rather than `NNNN_` because it must run after the 2026_*
 * migrations already on this branch (the mixed-prefix ordering rule in
 * CLAUDE.md), and specifically after 2026_09_11_000004.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->string('source_key', 191)->nullable()->after('rma_number');
            $table->foreignId('goods_receipt_note_id')->nullable()
                ->after('purchase_order_id')
                ->constrained('goods_receipt_notes')
                ->nullOnDelete();
            $table->boolean('reversal_already_applied')->default(false)->after('finance_only_approved_by');
            $table->unique('source_key', 'return_requests_source_key_unique');
        });

        Schema::table('return_request_items', function (Blueprint $table) {
            $table->boolean('reversal_already_applied')->default(false)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropUnique('return_requests_source_key_unique');
            $table->dropConstrainedForeignId('goods_receipt_note_id');
            $table->dropColumn(['source_key', 'reversal_already_applied']);
        });

        Schema::table('return_request_items', function (Blueprint $table) {
            $table->dropColumn('reversal_already_applied');
        });
    }
};
