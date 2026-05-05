<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payroll\Services\AutoPayrollPeriodService;
use Illuminate\Console\Command;

/**
 * Task A3 — Run on the 14th @ 23:00 (second half) and last day of month
 * @ 23:00 (first half of next month).
 *
 *   php artisan payroll:auto-create-period --half=second
 *   php artisan payroll:auto-create-period --half=first
 */
class CreateAutoPayrollPeriod extends Command
{
    protected $signature   = 'payroll:auto-create-period {--half=second}';
    protected $description = 'Auto-create the next payroll period and queue computation (Task A3)';

    public function handle(AutoPayrollPeriodService $svc): int
    {
        $half = $this->option('half') === 'first' ? 'first' : 'second';

        $period = $half === 'first'
            ? $svc->createForFirstHalfOfNextMonth()
            : $svc->createForSecondHalfOfCurrentMonth();

        if ($period === null) {
            $this->info("Auto payroll period skipped — already exists for the requested range ({$half} half).");
            return self::SUCCESS;
        }

        $this->info("Auto-created payroll period #{$period->id} ({$period->period_start} – {$period->period_end}). Queued computation.");
        return self::SUCCESS;
    }
}
