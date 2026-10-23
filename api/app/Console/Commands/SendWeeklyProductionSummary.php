<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\EmailDeliveryFailureNotifier;
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
    protected $signature = 'production:send-weekly-summary {--end=}';

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
            ->get()
            ->filter(static fn (User $user): bool => $user->hasPermission('production.work_orders.view'))
            ->values();

        if ($users->isEmpty()) {
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                'admin.settings.manage',
                'Weekly production summary',
                'No active user matches the configured production-summary roles. Review the role setting and recipient access.',
                ['link_to' => '/admin/settings', 'entity_type' => 'production_summary'],
            );
            $this->warn('No active recipients match the configured production-summary roles.');

            return self::SUCCESS;
        }

        $emailUsers = $users->filter(static fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false);
        if ($emailUsers->isEmpty()) {
            app(EmailDeliveryFailureNotifier::class)->notify(
                $users,
                'Weekly production summary',
                "The weekly production summary for {$summary['range_start']} to {$summary['range_end']} could not be emailed because no recipient has a usable email address. Review the production dashboard.",
                ['link_to' => '/production/work-orders', 'entity_type' => 'production_summary'],
            );

            return self::SUCCESS;
        }

        try {
            Notification::send($emailUsers, new WeeklyProductionSummary($summary));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notify(
                $users,
                'Weekly production summary',
                "The weekly production summary for {$summary['range_start']} to {$summary['range_end']} could not be delivered by email. Review the production dashboard.",
                [
                    'link_to' => '/production/work-orders',
                    'entity_type' => 'production_summary',
                    'reason' => 'The email provider rejected or could not deliver the production summary.',
                ],
            );
            $this->error('Weekly production summary email failed; in-app fallback created.');

            return self::FAILURE;
        }
        $this->info("Weekly production summary sent to {$emailUsers->count()} recipient(s) for week ending {$summary['range_end']}.");

        return self::SUCCESS;
    }
}
