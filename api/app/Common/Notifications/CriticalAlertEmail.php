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
        $safeTitle = Str::limit($this->safeText($this->alert->title), 120, '…');

        return (new MailMessage)
            ->subject('['.$brandName.'] Critical alert: '.$safeTitle)
            ->markdown('emails.critical-alert', [
                'preheader' => 'A critical system alert needs review.',
                'title' => 'Critical alert needs review',
                'alertTitle' => $this->escapeMarkdown($this->safeText($this->alert->title)),
                'alertType' => $this->alert->type->label(),
                'severity' => $this->alert->severity->label(),
                'message' => $this->escapeMarkdown($this->safeText($this->alert->message)),
                'entityType' => $this->alert->entity_type ? Str::headline(class_basename($this->alert->entity_type)) : null,
                'createdAt' => $this->alert->created_at?->copy()->timezone('Asia/Manila')->format('j M Y, g:i A P'),
                'alertsUrl' => rtrim(config('app.frontend_url', config('app.url')), '/').'/alerts',
            ]);
    }

    private function safeText(?string $value): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('~(?:https?://|www\.|mailto:)\S+~iu', '[link removed]', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function escapeMarkdown(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_',
            '{' => '\\{', '}' => '\\}', '[' => '\\[', ']' => '\\]',
            '(' => '\\(', ')' => '\\)', '#' => '\\#', '+' => '\\+',
            '-' => '\\-', '.' => '\\.', '!' => '\\!', '<' => '\\<',
            '>' => '\\>', '|' => '\\|',
        ]);
    }
}
