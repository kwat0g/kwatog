<?php

declare(strict_types=1);

namespace App\Modules\Production\Notifications;

use App\Common\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Task A10 — Daily 18:00 email to plant_manager / production_manager.
 */
class DailyProductionSummary extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(public readonly array $summary)
    {
        $this->onQueue('mail')->afterCommit();
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $companyName = app(SettingsService::class)->requiredString('company.legal_name');

        return (new MailMessage)
            ->subject('Production Summary — '.$this->summary['date'])
            ->view('emails.production-summary', ['summary' => $this->summary, 'companyName' => $companyName]);
    }
}
