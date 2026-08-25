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
        Schema::table('bank_file_records', function (Blueprint $table): void {
            $table->string('artifact_key', 150)->nullable()->after('payroll_period_id');
        });

        // Keep the newest historical row for each period/format as the
        // durable current artifact. Older rows intentionally remain valid
        // audit history but keep NULL so the unique index does not collapse
        // that history into a single record.
        $seen = [];
        DB::table('bank_file_records')
            ->orderBy('payroll_period_id')
            ->orderBy('format')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get(['id', 'payroll_period_id', 'format'])
            ->each(function (object $record) use (&$seen): void {
                $format = (string) ($record->format ?: 'generic');
                $key = sprintf('payroll-period:%s:bank-format:%s', $record->payroll_period_id, $format);
                if (isset($seen[$key])) {
                    return;
                }

                DB::table('bank_file_records')
                    ->where('id', $record->id)
                    ->update(['artifact_key' => $key]);
                $seen[$key] = true;
            });

        Schema::table('bank_file_records', function (Blueprint $table): void {
            $table->unique('artifact_key', 'bank_file_records_artifact_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_file_records', function (Blueprint $table): void {
            $table->dropUnique('bank_file_records_artifact_key_unique');
            $table->dropColumn('artifact_key');
        });
    }
};
