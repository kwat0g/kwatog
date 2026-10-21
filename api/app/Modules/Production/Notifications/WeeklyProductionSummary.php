<?php

declare(strict_types=1);

namespace App\Modules\Production\Notifications;

use App\Common\Services\EmailBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Task A10 — Weekly Friday 18:00 trend email.
 */
class WeeklyProductionSummary extends Notification implements ShouldQueue
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
            ->subject('['.$brandName.'] Weekly production summary: '.$this->summary['range_start'].' to '.$this->summary['range_end'])
            ->markdown('emails.production-summary-weekly', [
                'summary' => $this->summary,
                'preheader' => 'Weekly production totals and comparison with the prior week.',
                'title' => 'Weekly production summary',
                'workOrdersUrl' => rtrim(config('app.frontend_url', config('app.url')), '/').'/production/work-orders',
            ]);
    }
}
