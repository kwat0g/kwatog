<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database guard for concurrent scheduler invocations.
 *
 * A nullable key preserves the ability to create multiple human-managed
 * scoped periods for one cutoff while making each auto-period window unique.
 * The application precheck remains useful for the normal path; this index is
 * the race-proof boundary when two scheduler processes pass that precheck at
 * the same time.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('payroll_periods')
            ->where('is_auto_created', true)
            ->select('period_start', 'is_thirteenth_month')
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('period_start', 'is_thirteenth_month')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add auto payroll idempotency guard: duplicate auto-created payroll windows exist. Resolve them before deploying.',
            );
        }

        Schema::table('payroll_periods', static function (Blueprint $table): void {
            $table->string('auto_idempotency_key', 80)
                ->nullable()
                ->after('auto_created_at');
        });

        DB::table('payroll_periods')
            ->where('is_auto_created', true)
            ->whereNull('auto_idempotency_key')
            ->get(['id', 'period_start', 'is_thirteenth_month'])
            ->each(static function (object $period): void {
                DB::table('payroll_periods')
                    ->where('id', $period->id)
                    ->update([
                        'auto_idempotency_key' => sprintf(
                            '%s:%s',
                            $period->period_start,
                            $period->is_thirteenth_month ? '13th' : 'regular',
                        ),
                    ]);
            });

        Schema::table('payroll_periods', static function (Blueprint $table): void {
            $table->unique('auto_idempotency_key', 'payroll_periods_auto_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', static function (Blueprint $table): void {
            $table->dropUnique('payroll_periods_auto_idempotency_unique');
            $table->dropColumn('auto_idempotency_key');
        });
    }
};
