<?php

declare(strict_types=1);

namespace App\Common\Notifications;

use App\Common\Models\Alert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $msg = (new MailMessage)
            ->subject('[CRITICAL] '.$this->alert->title)
            ->greeting('Critical alert')
            ->line($this->alert->message);

        if ($this->alert->entity_type) {
            $msg->line('Entity: '.class_basename($this->alert->entity_type).' #'.$this->alert->entity_id);
        }

        return $msg
            ->action('View alerts', config('app.frontend_url', config('app.url')).'/alerts')
            ->line('Time: '.$this->alert->created_at?->toDateTimeString());
    }
}
