<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Make the RMA → Quality inspection handoff durable and recoverable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('inspection_handoff_status', 30)
                ->default('not_started')
                ->after('inspection_id')
                ->index();
            $table->text('inspection_handoff_message')->nullable()->after('inspection_handoff_status');
            $table->timestamp('inspection_handoff_at')->nullable()->after('inspection_handoff_message');
        });

        DB::table('return_requests')
            ->whereNotNull('inspection_id')
            ->update([
                'inspection_handoff_status' => 'generated',
                'inspection_handoff_at' => DB::raw('COALESCE(inspected_at, updated_at)'),
                'inspection_handoff_message' => null,
            ]);

        // Legacy rows that were marked inspected without a linked Quality
        // inspection are explicitly actionable. They must not silently pass
        // into dispose/complete after the new gate is deployed.
        DB::table('return_requests')
            ->where('status', 'inspected')
            ->whereNull('inspection_id')
            ->update([
                'inspection_handoff_status' => 'manual_required',
                'inspection_handoff_message' => 'Legacy RMA was marked inspected without a linked Quality inspection.',
                'inspection_handoff_at' => DB::raw('COALESCE(inspected_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'inspection_handoff_status',
                'inspection_handoff_message',
                'inspection_handoff_at',
            ]);
        });
    }
};
