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
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('gl_handoff_status', 30)->default('not_started')->index();
            $table->text('gl_handoff_message')->nullable();
            $table->timestamp('gl_handoff_at')->nullable();
        });

        $notRequiredTypes = [
            'grn_receipt',
            'transfer',
            'opening',
            'delivery',
        ];

        DB::table('stock_movements')
            ->whereNotNull('journal_entry_id')
            ->update([
                'gl_handoff_status' => 'generated',
                'gl_handoff_at' => DB::raw('created_at'),
            ]);

        DB::table('stock_movements')
            ->whereNull('journal_entry_id')
            ->where(function ($query) use ($notRequiredTypes): void {
                $query
                    ->whereIn('movement_type', $notRequiredTypes)
                    ->orWhere('total_cost', '0.00');
            })
            ->update([
                'gl_handoff_status' => 'not_required',
                'gl_handoff_at' => DB::raw('created_at'),
            ]);

        // Legacy value-changing rows have no reliable record of whether the
        // old synchronous post was skipped because Accounting was disabled or
        // because its setup was incomplete. Surface them for reconciliation;
        // new disabled-Accounting movements are explicitly not_required.
        DB::table('stock_movements')
            ->whereNull('journal_entry_id')
            ->whereNotIn('movement_type', $notRequiredTypes)
            ->where('total_cost', '<>', '0.00')
            ->update([
                'gl_handoff_status' => 'manual_required',
                'gl_handoff_message' => 'This historical value-changing movement has no linked journal entry. Confirm the Accounting policy, then retry or resolve it.',
                'gl_handoff_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['gl_handoff_status']);
            $table->dropColumn([
                'gl_handoff_status',
                'gl_handoff_message',
                'gl_handoff_at',
            ]);
        });
    }
};
