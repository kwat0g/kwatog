<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Make the GRN → Quality incoming-QC trigger explicit and recoverable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->string('incoming_qc_handoff_status', 30)
                ->default('not_started')
                ->after('qc_inspection_id')
                ->index();
            $table->text('incoming_qc_handoff_message')->nullable()->after('incoming_qc_handoff_status');
            $table->timestamp('incoming_qc_handoff_at')->nullable()->after('incoming_qc_handoff_message');
        });

        DB::table('goods_receipt_notes')
            ->whereNotNull('qc_inspection_id')
            ->update([
                'incoming_qc_handoff_status' => 'generated',
                'incoming_qc_handoff_message' => null,
                'incoming_qc_handoff_at' => DB::raw('updated_at'),
            ]);

        // Pending legacy receipts without a QC row are surfaced for recovery;
        // the fail-closed acceptance gate already prevents stock release.
        DB::table('goods_receipt_notes')
            ->where('status', 'pending_qc')
            ->whereNull('qc_inspection_id')
            ->update([
                'incoming_qc_handoff_status' => 'manual_required',
                'incoming_qc_handoff_message' => 'Pending GRN has no incoming Quality inspection.',
                'incoming_qc_handoff_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->dropColumn([
                'incoming_qc_handoff_status',
                'incoming_qc_handoff_message',
                'incoming_qc_handoff_at',
            ]);
        });
    }
};
