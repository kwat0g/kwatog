<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Material Review Board (MRB) fields to NCR for incoming QC disposition tracking.
 *
 * - mrb_accepted_quantity: decimal(15,3) — for RTV with sorting: how many good pieces are kept
 * - mrb_decided_by: FK to users — who made the MRB disposition decision (maker-checker)
 * - mrb_decided_at: timestamp — when the MRB disposition was decided
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->decimal('mrb_accepted_quantity', 15, 3)->nullable()
                ->comment('For RTV/scrap: quantity of good pieces accepted after sorting. NULL for other dispositions.');
            $table->foreignId('mrb_decided_by')->nullable()
                ->references('id')->on('users')->nullOnDelete()
                ->comment('User who decided the MRB disposition (for incoming failures, may differ from inspector).');
            $table->timestamp('mrb_decided_at')->nullable()
                ->comment('Timestamp when MRB disposition was decided.');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->dropColumn(['mrb_accepted_quantity', 'mrb_decided_by', 'mrb_decided_at']);
        });
    }
};
