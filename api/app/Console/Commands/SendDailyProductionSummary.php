<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Notifications\DailyProductionSummary;
use App\Modules\Production\Services\ProductionSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Task A10 — Daily 18:00 production summary email.
 */
class SendDailyProductionSummary extends Command
{
    protected $signature   = 'production:send-daily-summary {--date=}';
    protected $description = 'Email today\'s production summary to plant managers (Task A10)';

    public function handle(ProductionSummaryService $svc): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $summary = $svc->forDate($date);

        $users = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['production_manager', 'system_admin']))
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No production_manager/system_admin recipients found.');
            return self::SUCCESS;
        }

        Notification::send($users, new DailyProductionSummary($summary));
        $this->info("Daily production summary sent to {$users->count()} recipient(s) for {$date->toDateString()}.");
        return self::SUCCESS;
    }
}
