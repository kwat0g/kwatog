<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Make the CRM complaint → Quality NCR handoff durable and recoverable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->string('ncr_handoff_status', 30)
                ->default('not_started')
                ->after('ncr_id')
                ->index();
            $table->text('ncr_handoff_message')->nullable()->after('ncr_handoff_status');
            $table->timestamp('ncr_handoff_at')->nullable()->after('ncr_handoff_message');
        });

        DB::table('customer_complaints')
            ->whereNotNull('ncr_id')
            ->update([
                'ncr_handoff_status' => 'generated',
                'ncr_handoff_at' => DB::raw('updated_at'),
                'ncr_handoff_message' => null,
            ]);

        // Legacy complaints without an NCR are not treated as harmless old
        // data: they need a visible Quality recovery action before closure.
        DB::table('customer_complaints')
            ->whereNull('ncr_id')
            ->update([
                'ncr_handoff_status' => 'manual_required',
                'ncr_handoff_message' => 'Legacy complaint has no linked Non-Conformance Report.',
                'ncr_handoff_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->dropColumn([
                'ncr_handoff_status',
                'ncr_handoff_message',
                'ncr_handoff_at',
            ]);
        });
    }
};
