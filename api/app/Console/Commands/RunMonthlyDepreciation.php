<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SystemActorService;
use App\Modules\Assets\Services\DepreciationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Run or backfill one asset-depreciation period from the command line.
 *
 * The scheduler stages a durable request for the previous month. This command
 * remains the synchronous operator path when a period must be rebuilt or an
 * external backfill needs immediate output.
 */
class RunMonthlyDepreciation extends Command
{
    protected $signature = 'assets:run-monthly-depreciation
        {--year= : Target year (requires --month)}
        {--month= : Target month 1..12 (requires --year)}';

    protected $description = 'Run idempotent asset depreciation for a month (defaults to the previous month).';

    public function handle(DepreciationService $depreciation, SystemActorService $actors): int
    {
        $yearOption = $this->option('year');
        $monthOption = $this->option('month');

        if (($yearOption !== null) !== ($monthOption !== null)) {
            $this->error('Both --year and --month must be provided together.');

            return self::FAILURE;
        }

        if ($yearOption !== null) {
            $year = (int) $yearOption;
            $month = (int) $monthOption;
            if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
                $this->error('Target year must be 2020..2100 and target month must be 1..12.');

                return self::FAILURE;
            }
        } else {
            $target = CarbonImmutable::now()->subMonthNoOverflow();
            $year = $target->year;
            $month = $target->month;
        }

        $actor = $actors->resolve();
        if (! $actor) {
            $this->error('Asset depreciation cannot run without an automation actor.');

            return self::FAILURE;
        }

        $result = $depreciation->runForMonth($year, $month, $actor);
        $this->info(sprintf(
            'Asset depreciation for %04d-%02d: posted=%d total=%s journal_entry=%s.',
            $year,
            $month,
            $result['posted_count'],
            $result['total_amount'],
            $result['journal_entry_id'] === null ? 'none' : (string) $result['journal_entry_id'],
        ));

        return self::SUCCESS;
    }
}
