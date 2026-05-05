<?php

declare(strict_types=1);

namespace App\Modules\Production\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Task A10 — Weekly Friday 18:00 trend email.
 */
class WeeklyProductionSummary extends Notification
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
            ->subject('Weekly Production Summary — '.$this->summary['range_start'].' to '.$this->summary['range_end'])
            ->view('emails.production-summary-weekly', ['summary' => $this->summary]);
    }
}
