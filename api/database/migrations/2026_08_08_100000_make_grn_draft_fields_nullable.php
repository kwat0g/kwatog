<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-08 — Draft (expected) GRNs.
 *
 * When a PO is marked sent, a GRN in `draft` status is auto-created with the
 * PO lines pre-filled — a receipt *expectation*, not a receipt. Goods have not
 * arrived yet, so none of these are known at draft time:
 *   - received_date   (goods not physically received)
 *   - received_by     (no warehouse user performed a receipt)
 *   - grn_items.location_id (no bin assigned until the goods land)
 * The warehouse assigns bins + actual quantities on finalize, which flips the
 * GRN to pending_qc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->date('received_date')->nullable()->change();
            $table->unsignedBigInteger('received_by')->nullable()->change();
        });

        Schema::table('grn_items', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->date('received_date')->nullable(false)->change();
            $table->unsignedBigInteger('received_by')->nullable(false)->change();
        });
    }
};
