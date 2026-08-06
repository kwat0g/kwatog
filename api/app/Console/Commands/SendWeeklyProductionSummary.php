<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
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

    public function handle(ProductionSummaryService $svc, SettingsService $settings): int
    {
        $end = $this->option('end')
            ? Carbon::parse((string) $this->option('end'))
            : Carbon::today();

        $summary = $svc->forWeek($end);

        $roles = array_values(array_filter((array) $settings->get('production.summary.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $users = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
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
