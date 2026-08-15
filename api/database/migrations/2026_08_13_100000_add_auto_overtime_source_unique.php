<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-013 — make biometric overtime replay-safe.
 *
 * Attendance is unique by (employee_id, date), and the auto-detection writer
 * already defines its source as the same employee/date pair. Keep manually
 * filed overtime distinct while allowing at most one auto-detected row for
 * that logical source.
 */
return new class extends Migration
{
    private const INDEX = 'overtime_requests_auto_source_unique';

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Auto-overtime idempotency migration requires a partial-index capable driver; received {$driver}."
            );
        }

        $duplicates = DB::table('overtime_requests')
            ->select('employee_id', 'date')
            ->where('is_auto_detected', true)
            ->groupBy('employee_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(static fn (object $row): string => "employee {$row->employee_id} on {$row->date}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add '.self::INDEX.': duplicate auto-detected overtime rows exist for '.$details.'. Resolve the business records before retrying; this migration never deletes or deduplicates them.'
            );
        }

        $predicate = $driver === 'pgsql' ? 'TRUE' : '1';
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON overtime_requests (employee_id, date) WHERE is_auto_detected = '.$predicate
        );
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
