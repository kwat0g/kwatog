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
 * so re-runs simply overwrite (via a database upsert inside the service).
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
        $yearOption = $this->option('year');
        $monthOption = $this->option('month');
        $year = $yearOption === null || $yearOption === ''
            ? $now->year
            : filter_var((string) $yearOption, FILTER_VALIDATE_INT);
        $month = $monthOption === null || $monthOption === ''
            ? $now->month
            : filter_var((string) $monthOption, FILTER_VALIDATE_INT);

        if ($year === false || $month === false) {
            $this->error('Year and month must be whole numbers.');

            return self::INVALID;
        }

        if ($year < SupplierPerformanceService::MIN_PERIOD_YEAR || $year > SupplierPerformanceService::MAX_PERIOD_YEAR) {
            $this->error(sprintf(
                'Year must be between %d and %d.',
                SupplierPerformanceService::MIN_PERIOD_YEAR,
                SupplierPerformanceService::MAX_PERIOD_YEAR,
            ));

            return self::INVALID;
        }

        if ($month < 1 || $month > 12) {
            $this->error('Month must be between 1 and 12.');

            return self::INVALID;
        }

        $this->info("Recomputing supplier performance for {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT).'…');

        $result = $service->recomputeAll($year, $month);

        $this->info(sprintf(
            'Supplier snapshots: computed=%d failed=%d.',
            $result['computed'],
            count($result['failed']),
        ));

        if ($result['failed'] !== []) {
            foreach ($result['failed'] as $failure) {
                $this->error("Vendor {$failure['vendor_id']} failed: {$failure['error']}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
