<?php

declare(strict_types=1);

namespace App\Common\Notifications;

use App\Common\Models\Alert;
use App\Common\Services\EmailBrandingService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Task A2 — Email-only notification handed to the configured mail transport
 * for severity=critical alerts. The alert row owns bounded retry state, so the
 * handoff remains synchronous and its outcome is recorded before retrying.
 */
class CriticalAlertEmail extends Notification
{
    public function __construct(public readonly Alert $alert) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $brandName = app(EmailBrandingService::class)->data()['name'];
        $safeTitle = Str::limit(Str::squish(str_replace(["\r", "\n"], ' ', $this->alert->title)), 120, '…');

        return (new MailMessage)
            ->subject('['.$brandName.'] Critical alert: '.$safeTitle)
            ->markdown('emails.critical-alert', [
                'preheader' => 'A critical system alert needs review.',
                'title' => 'Critical alert needs review',
                'alertTitle' => $this->alert->title,
                'alertType' => $this->alert->type->label(),
                'severity' => $this->alert->severity->label(),
                'message' => $this->alert->message,
                'entityType' => $this->alert->entity_type ? class_basename($this->alert->entity_type) : null,
                'createdAt' => $this->alert->created_at?->copy()->timezone('Asia/Manila')->format('j M Y, g:i A P'),
                'alertsUrl' => rtrim(config('app.frontend_url', config('app.url')), '/').'/alerts',
            ]);
    }
}
