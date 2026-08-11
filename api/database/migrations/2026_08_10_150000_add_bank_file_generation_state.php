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
            $table->string('bank_file_status', 20)
                ->default('not_started')
                ->after('status');
            $table->text('bank_file_note')->nullable()->after('bank_file_status');
            $table->timestamp('bank_file_at')->nullable()->after('bank_file_note');
            $table->index('bank_file_status');
        });

        // Preserve the truth already present in generated-file audit rows.
        // Finalized historical periods without a record become an explicit
        // operator handoff rather than an invisible missing artifact.
        $generated = DB::table('bank_file_records')
            ->select('payroll_period_id', DB::raw('MAX(generated_at) as generated_at'))
            ->groupBy('payroll_period_id')
            ->get();

        foreach ($generated as $row) {
            DB::table('payroll_periods')
                ->where('id', $row->payroll_period_id)
                ->update([
                    'bank_file_status' => 'generated',
                    'bank_file_note' => null,
                    'bank_file_at' => $row->generated_at,
                ]);
        }

        DB::table('payroll_periods')
            ->whereIn('status', ['finalized', 'disbursed'])
            ->where('bank_file_status', 'not_started')
            ->update([
                'bank_file_status' => 'manual_required',
                'bank_file_note' => 'Bank-file generation was not recorded for this finalized period. Generate and review the file manually.',
            ]);
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropIndex(['bank_file_status']);
            $table->dropColumn(['bank_file_status', 'bank_file_note', 'bank_file_at']);
        });
    }
};
