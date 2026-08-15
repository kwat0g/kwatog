<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerComplaintUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly CustomerComplaint $complaint,
        public readonly string $action,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Complaint {$this->complaint->complaint_number} update");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/complaint-update',
            with: [
                'complaint' => $this->complaint,
                'action' => $this->action,
                'portalUrl' => $base.'/portal/customer/complaints',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer complaint email',
            "The update email for complaint {$this->complaint->complaint_number} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/crm/complaints/'.$this->complaint->hash_id,
                'entity_type' => 'customer_complaint',
                'entity_id' => $this->complaint->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
