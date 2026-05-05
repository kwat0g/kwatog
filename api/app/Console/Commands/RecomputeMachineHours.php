<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Maintenance\Services\MachineHoursService;
use Illuminate\Console\Command;

/**
 * Task A5 — Recompute machine running hours daily before the existing
 * preventive-maintenance generator runs (Sprint 8 Task 69).
 */
class RecomputeMachineHours extends Command
{
    protected $signature   = 'maintenance:recompute-hours';
    protected $description = 'Recompute machine running_hours_total from work-order outputs and downtimes (Task A5)';

    public function handle(MachineHoursService $svc): int
    {
        $count = $svc->recompute();
        $this->info("Recomputed running hours for {$count} machine(s).");
        return self::SUCCESS;
    }
}
