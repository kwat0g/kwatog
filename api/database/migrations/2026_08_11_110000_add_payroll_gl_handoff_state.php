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
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('gl_handoff_status', 20)
                ->default('not_started')
                ->after('journal_entry_id');
            $table->text('gl_handoff_note')->nullable()->after('gl_handoff_status');
            $table->timestamp('gl_handoff_at')->nullable()->after('gl_handoff_note');
            $table->index('gl_handoff_status');
        });

        // Existing finalized runs predate the durable handoff. Preserve already
        // linked entries as posted and surface every other closed run for an
        // explicit operator review instead of leaving it indistinguishable from
        // a payroll that never reached the GL boundary.
        DB::table('payroll_periods')
            ->whereNotNull('journal_entry_id')
            ->update([
                'gl_handoff_status' => 'posted',
                'gl_handoff_note' => null,
                'gl_handoff_at' => now(),
            ]);

        $stuck = DB::raw(Schema::hasColumn('payroll_periods', 'finalized_at')
            ? 'COALESCE(finalized_at, updated_at)'
            : 'updated_at');

        DB::table('payroll_periods')
            ->whereIn('status', ['finalized', 'disbursed'])
            ->whereNull('journal_entry_id')
            ->where('gl_handoff_status', 'not_started')
            ->update([
                'gl_handoff_status' => 'manual_required',
                'gl_handoff_note' => 'GL posting was not recorded for this finalized period. Review Accounting setup and retry the handoff.',
                'gl_handoff_at' => $stuck,
            ]);
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropIndex(['gl_handoff_status']);
            $table->dropColumn(['gl_handoff_status', 'gl_handoff_note', 'gl_handoff_at']);
        });
    }
};
