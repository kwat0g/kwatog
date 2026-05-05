<?php

declare(strict_types=1);

namespace App\Modules\Production\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Task A10 — Daily 18:00 email to plant_manager / production_manager.
 */
class DailyProductionSummary extends Notification
{
    use Queueable;

    public function __construct(public readonly array $summary) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Production Summary — '.$this->summary['date'])
            ->view('emails.production-summary', ['summary' => $this->summary]);
    }
}
