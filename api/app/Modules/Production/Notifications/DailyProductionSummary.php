<?php

declare(strict_types=1);

namespace App\Modules\Production\Notifications;

use App\Common\Services\EmailBrandingService;
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
        $brandName = app(EmailBrandingService::class)->data()['name'];

        return (new MailMessage)
            ->subject('['.$brandName.'] Daily production summary: '.$this->summary['date'])
            ->markdown('emails.production-summary', [
                'summary' => $this->summary,
                'preheader' => 'Daily production results and active exceptions.',
                'title' => 'Daily production summary',
                'workOrdersUrl' => rtrim(config('app.frontend_url', config('app.url')), '/').'/production/work-orders',
            ]);
    }
}
