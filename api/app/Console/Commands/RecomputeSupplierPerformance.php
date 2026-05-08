<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Series F — Task F4. Monthly batch recompute of supplier performance.
 *
 * Idempotent: snapshots use UNIQUE(vendor_id, period_year, period_month)
 * so re-runs simply overwrite (via updateOrCreate inside the service).
 *
 * Default: recompute the previous calendar month (so we run on the 1st
 * and the month is fully closed). Pass --year=2026 --month=4 to
 * recompute a specific month.
 */
class RecomputeSupplierPerformance extends Command
{
    protected $signature = 'purchasing:recompute-supplier-performance
        {--year= : Year to compute (default = previous month)}
        {--month= : Month to compute (default = previous month)}';

    protected $description = 'Recompute supplier performance snapshots for every vendor for a given month.';

    public function handle(SupplierPerformanceService $service): int
    {
        $now = Carbon::now()->subMonth();
        $year  = (int) ($this->option('year')  ?: $now->year);
        $month = (int) ($this->option('month') ?: $now->month);

        $this->info("Recomputing supplier performance for {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT).'…');

        $count = $service->recomputeAll($year, $month);

        $this->info("Computed {$count} vendor snapshot(s).");
        return self::SUCCESS;
    }
}
