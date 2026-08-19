<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the threshold column nothing reads.
 *
 * `workflow_definitions.amount_threshold` (added by 0009) looked like the money
 * gate on an approval chain: a real decimal(15,2), fillable, cast, and seeded to
 * 50000.00 on purchase_request and purchase_order. It gated nothing. The live
 * gate is the per-step `threshold` key inside the `steps` JSON, which is what
 * ApprovalService::submit() reads; the column was never selected, filtered or
 * compared anywhere in app/, and no API resource or export exposed it. Two
 * mechanisms carrying the same number with one reader is a trap for whoever
 * next tries to raise the VP threshold — they would set the column, see it
 * persist, and change no routing decision.
 *
 * down() restores the column's SHAPE only. It deliberately does not restore the
 * seeded 50000.00 values: nothing ever read them, so re-inserting them would be
 * inventing data rather than recovering it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->dropColumn('amount_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            // Re-added nullable and empty. Postgres appends it after timestamps
            // rather than at its original position; column order is not
            // significant to any query here.
            $table->decimal('amount_threshold', 15, 2)->nullable();
        });
    }
};
