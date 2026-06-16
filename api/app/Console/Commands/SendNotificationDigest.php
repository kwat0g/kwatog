<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationDigestService;
use Illuminate\Console\Command;

/**
 * OGAMI-016 — batch unread notifications per user into a summary email.
 *
 * Idempotent: re-runs simply re-summarise whatever is still unread; read
 * state is never mutated. Orchestrator schedules this (see report) — the
 * command class itself does not register a schedule entry.
 */
class SendNotificationDigest extends Command
{
    protected $signature   = 'notifications:send-digest';
    protected $description = 'Email each opted-in user a summary of their unread notifications';

    public function handle(NotificationDigestService $svc): int
    {
        $r = $svc->run();
        $this->info(sprintf(
            'Notification digest: %d users evaluated, %d emails sent, %d notifications summarised.',
            $r['users_evaluated'],
            $r['emails_sent'],
            $r['notifications_summarised'],
        ));

        return self::SUCCESS;
    }
}
