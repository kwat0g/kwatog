<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use App\Modules\Production\Services\ProductionSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Task A10 — Weekly Friday 18:00 production summary email.
 */
class SendWeeklyProductionSummary extends Command
{
    protected $signature   = 'production:send-weekly-summary {--end=}';
    protected $description = 'Email weekly production summary to plant managers (Task A10)';

    public function handle(ProductionSummaryService $svc): int
    {
        $end = $this->option('end')
            ? Carbon::parse((string) $this->option('end'))
            : Carbon::today();

        $summary = $svc->forWeek($end);

        $users = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['production_manager', 'system_admin']))
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No production_manager/system_admin recipients found.');
            return self::SUCCESS;
        }

        Notification::send($users, new WeeklyProductionSummary($summary));
        $this->info("Weekly production summary sent to {$users->count()} recipient(s) for week ending {$summary['range_end']}.");
        return self::SUCCESS;
    }
}
