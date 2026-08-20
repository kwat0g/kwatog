<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
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

    public function handle(ProductionSummaryService $svc, SettingsService $settings): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $summary = $svc->forDate($date);

        $roles = array_values(array_filter((array) $settings->get('production.summary.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $users = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No production_manager/system_admin recipients found.');
            return self::SUCCESS;
        }

        // `!== false` is load-bearing, not defensive. filter_var returns the
        // ADDRESS on success and false only on failure, so a closure typed
        // `: bool` under strict_types threw a TypeError for every VALID
        // recipient — the happy path was the broken one.
        $emailUsers = $users->filter(static fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false);
        if ($emailUsers->isEmpty()) {
            app(EmailDeliveryFailureNotifier::class)->notify(
                $users,
                'Daily production summary',
                "The daily production summary for {$date->toDateString()} could not be emailed because no recipient has a usable email address. Review the production dashboard.",
                ['link_to' => '/production/work-orders', 'entity_type' => 'production_summary'],
            );
            return self::SUCCESS;
        }

        try {
            Notification::send($emailUsers, new DailyProductionSummary($summary));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notify(
                $users,
                'Daily production summary',
                "The daily production summary for {$date->toDateString()} could not be delivered by email. Review the production dashboard.",
                [
                    'link_to' => '/production/work-orders',
                    'entity_type' => 'production_summary',
                    'reason' => 'The email provider rejected or could not deliver the production summary.',
                ],
            );
            $this->error('Daily production summary email failed; in-app fallback created.');
            return self::FAILURE;
        }
        $this->info("Daily production summary sent to {$emailUsers->count()} recipient(s) for {$date->toDateString()}.");
        return self::SUCCESS;
    }
}
