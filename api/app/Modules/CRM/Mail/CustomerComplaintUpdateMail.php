<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CustomerComplaintUpdateMail extends QueuedMailable
{
    /**
     * @param array<string, mixed> $snapshot
     * @param list<int> $fallbackUserIds
     */
    public function __construct(
        public readonly array $snapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = str_replace(["\r", "\n"], ' ', (string) app(EmailBrandingService::class)->data()['name']);
        $number = str_replace(["\r", "\n"], '', $this->snapshot['complaint_number']);

        return new Envelope(subject: '['.$brand.'] Customer complaint update: '.$number);
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Content(
            markdown: 'emails/customer/complaint-update',
            with: [
                'brand' => $brand,
                'preheader' => 'Status update for complaint '.$this->snapshot['complaint_number'].'.',
                'complaint' => $this->snapshot,
                'portalUrl' => $this->snapshot['portal_url'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer complaint email',
            "The update email for complaint {$this->snapshot['complaint_number']} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/crm/complaints/'.$this->snapshot['complaint_hash_id'],
                'entity_type' => 'customer_complaint',
                'entity_id' => $this->snapshot['complaint_hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
