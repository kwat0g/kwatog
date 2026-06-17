<?php

declare(strict_types=1);

namespace App\Modules\Landing\Notifications;

use App\Modules\Landing\Models\QuoteRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteRequestReceivedNotification extends Notification
{
    public function __construct(private readonly QuoteRequest $quote) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $q = $this->quote;
        $hasDrawing = $q->drawing_original_name !== null;

        return (new MailMessage())
            ->subject("New Quote Request — {$q->request_no}")
            ->greeting('New Quote Request Received')
            ->line("**Request No:** {$q->request_no}")
            ->line("**Name:** {$q->full_name}")
            ->line("**Company:** {$q->company}")
            ->line("**Email:** {$q->email}")
            ->line("**Part Description:** {$q->part_description}")
            ->line('**Annual Volume:** ' . ($q->annual_volume !== null ? number_format($q->annual_volume) . ' pcs/year' : 'Not specified'))
            ->line('**Drawing Attached:** ' . ($hasDrawing ? "Yes ({$q->drawing_original_name})" : 'No'))
            ->line('Please log in to the ERP to review and respond to this request.');
    }
}
