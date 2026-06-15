<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\CopqService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * T3.6.C — Monthly COPQ rollup snapshot.
 *
 * Persists a `copq_snapshots` row for the PRIOR calendar month via
 * CopqService::snapshot(). Idempotent: re-runs `updateOrCreate` against
 * the (period_year, period_month) unique key so numbers refresh if late
 * NCRs closed in the meantime.
 *
 * Backfill: pass --year= AND --month= to snap any arbitrary month.
 *
 * Wired into the schedule in routes/console.php at monthlyOn(1, '02:30').
 */
class SnapCopqMonthly extends Command
{
    protected $signature = 'copq:snap-monthly {--year= : Year to snap (1..9999); requires --month} {--month= : Month to snap (1..12); requires --year}';

    protected $description = 'Persist a COPQ snapshot row for the previous calendar month (idempotent).';

    public function handle(CopqService $copq): int
    {
        $yearOpt  = $this->option('year');
        $monthOpt = $this->option('month');

        if (($yearOpt && ! $monthOpt) || ($monthOpt && ! $yearOpt)) {
            $this->error('Both --year and --month must be provided together.');
            return self::FAILURE;
        }

        $target = ($yearOpt && $monthOpt)
            ? Carbon::create((int) $yearOpt, (int) $monthOpt, 1)
            // subMonthNoOverflow so a Mar 1 run doesn't land on Feb 29 in non-leap years.
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $snap = $copq->snapshot($target->year, $target->month);

        $this->info("Snapped COPQ for {$target->format('Y-m')} — total ₱{$snap->total_cost}");

        return self::SUCCESS;
    }
}
