<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Services\AutoPayrollPeriodService;
use Illuminate\Console\Command;

/**
 * Task A3 — Run on the 14th @ 23:00 (second half) and last day of month
 *
 * @ 23:00 (first half of next month).
 *
 *   php artisan payroll:auto-create-period --half=second
 *   php artisan payroll:auto-create-period --half=first
 *   php artisan payroll:auto-create-period --half=first --year=2026 --month=8
 */
class CreateAutoPayrollPeriod extends Command
{
    protected $signature = 'payroll:auto-create-period {--half=second} {--year= : Target period year (requires --month)} {--month= : Target period month (requires --year)}';

    protected $description = 'Auto-create the next payroll period and queue computation (Task A3)';

    public function handle(AutoPayrollPeriodService $svc): int
    {
        $half = $this->option('half') === 'first' ? 'first' : 'second';

        $yearOption = $this->option('year');
        $monthOption = $this->option('month');
        if (($yearOption !== null) !== ($monthOption !== null)) {
            $this->error('Both --year and --month must be provided together.');

            return self::FAILURE;
        }

        if ($yearOption !== null) {
            $year = (int) $yearOption;
            $month = (int) $monthOption;
            if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                $this->error('Target year must be 2000..2100 and target month must be 1..12.');

                return self::FAILURE;
            }
        }

        $period = $yearOption !== null
            ? ($half === 'first'
                ? $svc->createForFirstHalfOfMonth($year, $month)
                : $svc->createForSecondHalfOfMonth($year, $month))
            : ($half === 'first'
                ? $svc->createForFirstHalfOfNextMonth()
                : $svc->createForSecondHalfOfCurrentMonth());

        if ($period === null) {
            $this->info("Auto payroll period skipped — already exists for the requested range ({$half} half).");

            return self::SUCCESS;
        }

        if ($period->fresh()->status !== PayrollPeriodStatus::Processing) {
            $this->error("Auto-created payroll period #{$period->id}, but its durable compute request was not staged; HR action is required.");

            return self::FAILURE;
        }

        $this->info("Auto-created payroll period #{$period->id} ({$period->period_start} – {$period->period_end}) and staged durable computation.");

        return self::SUCCESS;
    }
}
