<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add the read-side SLA for finalized payroll periods without a GL entry. */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'dashboard.chain_bottlenecks')->first();
        if (! $row) {
            return;
        }

        $current = json_decode((string) $row->value, true);
        if (! is_array($current)) {
            return;
        }

        $updated = $current + [
            'payroll_gl_without_journal' => [
                'label' => 'Finalized payroll awaiting GL posting',
                'hours' => 4,
                'audience' => 'finance_officer',
            ],
        ];

        if ($updated === $current) {
            return;
        }

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($updated, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'dashboard.chain_bottlenecks')->first();
        if (! $row) {
            return;
        }

        $current = json_decode((string) $row->value, true);
        if (! is_array($current)) {
            return;
        }

        unset($current['payroll_gl_without_journal']);

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
