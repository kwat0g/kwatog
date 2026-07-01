<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Dashboard\Services\KpiSnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Task 14 — Monthly KPI snapshot computation.
 *
 * Iterates all active KpiDefinition rows, calls each calculator method,
 * and upserts KpiSnapshot rows for the given (year, month). Idempotent:
 * re-runs overwrite via updateOrCreate keyed on (definition_id, period_year,
 * period_month).
 *
 * By default computes the PRIOR calendar month. Pass --year and --month
 * together for backfill or manual re-computation.
 *
 * Wired into the schedule in routes/console.php at monthlyOn(2, '03:00').
 */
class ComputeMonthlyKpis extends Command
{
    protected $signature = 'kpi:compute-monthly {--year= : Year to compute (1..9999); requires --month} {--month= : Month to compute (1..12); requires --year}';

    protected $description = 'Compute and persist KPI snapshots for all active definitions (defaults to previous month).';

    public function handle(KpiSnapshotService $service): int
    {
        $yearOpt  = $this->option('year');
        $monthOpt = $this->option('month');

        if (($yearOpt && ! $monthOpt) || ($monthOpt && ! $yearOpt)) {
            $this->error('Both --year and --month must be provided together.');
            return self::FAILURE;
        }

        $target = ($yearOpt && $monthOpt)
            ? Carbon::create((int) $yearOpt, (int) $monthOpt, 1)
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $this->info("Computing KPI snapshots for {$target->format('Y-m')}...");

        $service->computeAll($target->year, $target->month);

        $this->info("KPI snapshots computed for {$target->format('Y-m')}.");

        return self::SUCCESS;
    }
}
