<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Services\SalaryAdjustmentService;
use Illuminate\Console\Command;

/**
 * HR-05 — deferred live salary updates.
 *
 * An adjustment approved with a future effective date writes its
 * effective-dated salary-history row at approval but leaves the LIVE employee
 * pay columns alone, so payroll periods predating the raise cannot pay it
 * early. Once the effective date arrives, this command flips the live row.
 *
 * Failures propagate (exit non-zero) — a missed live update must be visible,
 * not flattened into a zero-count success.
 */
class ApplyDueSalaryAdjustments extends Command
{
    protected $signature = 'hr:apply-due-salary-adjustments';

    protected $description = 'Apply approved salary adjustments whose effective date has arrived to the live employee row.';

    public function handle(SalaryAdjustmentService $svc): int
    {
        $applied = $svc->applyDueLiveAdjustments();

        $this->info("Applied {$applied} due salary adjustment(s) to the live employee row.");

        return self::SUCCESS;
    }
}
