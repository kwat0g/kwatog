<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Services\OnboardingService;
use Illuminate\Console\Command;

/**
 * U4 — daily cron. Notifies HR for any onboarding open > 3 days.
 */
class SendOnboardingReminders extends Command
{
    protected $signature = 'hr:onboarding-reminders';

    protected $description = 'Send reminders for incomplete employee onboardings older than 3 days.';

    public function handle(OnboardingService $service): int
    {
        $count = $service->sendRemindersForStaleOnboardings();
        $this->info("Sent {$count} onboarding reminders.");
        return self::SUCCESS;
    }
}
