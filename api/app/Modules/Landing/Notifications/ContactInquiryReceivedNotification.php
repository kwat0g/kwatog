<?php

declare(strict_types=1);

namespace App\Modules\Landing\Notifications;

use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactInquiryReceivedNotification extends Notification
{
    public function __construct(private readonly ContactInquiry $inquiry) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $i = $this->inquiry;

        return (new MailMessage())
            ->subject("New Contact Inquiry — {$i->inquiry_no}")
            ->greeting('New Contact Inquiry Received')
            ->line("**Inquiry No:** {$i->inquiry_no}")
            ->line("**Name:** {$i->full_name}")
            ->line('**Company:** ' . ($i->company ?? 'Not specified'))
            ->line("**Email:** {$i->email}")
            ->line('**Phone:** ' . ($i->phone ?? 'Not specified'))
            ->line("**Message:** {$i->message}")
            // This line used to be a lie — the old quote-request notification
            // said the same thing while no such screen existed. It does now.
            ->action('Open in ERP', url("/crm/inquiries/{$i->hash_id}"))
            ->line('Reply directly to the sender, or convert the inquiry to a lead to track it in the CRM pipeline.');
    }
}
