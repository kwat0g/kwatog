<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnRequestUpdateMail extends QueuedMailable
{
    use SerializesModels;

    public array $snapshot = [];

    public ?string $deliveryId = null;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $action,
        public readonly array $fallbackUserIds = [],
        array $snapshot = [],
        ?string $deliveryId = null,
    ) {
        $this->onQueue('mail');
        $this->snapshot = $snapshot;
        $this->deliveryId = $deliveryId;
        $this->deliveryKey = $deliveryId;
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $reference = $this->snapshot['rma_number'] ?? $this->returnRequest->rma_number;

        return new Envelope(subject: "Return request {$reference} update");
    }

    public function content(): Content
    {
        $snapshot = $this->snapshot !== [] ? $this->snapshot : $this->legacySnapshot();

        return new Content(
            markdown: 'emails/returns/return-update',
            with: [
                'snapshot' => $snapshot,
                'companyName' => app(\App\Common\Services\EmailBrandingService::class)->data()['name'],
                'action' => $snapshot['action'] ?? $this->action,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function legacySnapshot(): array
    {
        $isCustomer = $this->returnRequest->type?->value === 'customer_return';
        $party = $isCustomer ? $this->returnRequest->customer : $this->returnRequest->vendor;

        return [
            'rma_number' => $this->returnRequest->rma_number,
            'type_label' => $this->returnRequest->type?->label() ?? 'Return Request',
            'status_label' => $this->returnRequest->status?->label() ?? 'Updated',
            'return_date' => $this->returnRequest->return_date?->toDateString(),
            'reason_description' => $this->returnRequest->reason_description,
            'resolution' => $this->returnRequest->resolution,
            'refund_amount' => $this->returnRequest->refund_amount === null ? null : (string) $this->returnRequest->refund_amount,
            'party_name' => $party?->name,
            'party_contact_name' => $party?->contact_person,
        ];
    }

    public function failed(\Throwable $e): void
    {
        $reference = $this->snapshot['rma_number'] ?? $this->returnRequest->rma_number;

        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Return request email',
            "The update email for return request {$reference} could not be delivered. Contact the customer or supplier through an approved channel.",
            [
                'link_to' => '/return-management/'.$this->returnRequest->hash_id,
                'entity_type' => 'return_request',
                'entity_id' => $this->returnRequest->hash_id,
                'reason' => 'The party email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
