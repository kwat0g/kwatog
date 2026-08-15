<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnRequestUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $action,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Return request {$this->returnRequest->rma_number} update");
    }

    public function content(): Content
    {
        $isCustomer = $this->returnRequest->type?->value === 'customer_return';
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/returns/return-update',
            with: [
                'returnRequest' => $this->returnRequest,
                'action' => $this->action,
                'party' => $isCustomer ? $this->returnRequest->customer : $this->returnRequest->vendor,
                'portalUrl' => $base.($isCustomer ? '/portal/customer' : '/portal/supplier'),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Return request email',
            "The update email for return request {$this->returnRequest->rma_number} could not be delivered. Contact the customer or supplier through an approved channel.",
            [
                'link_to' => '/return-management/return-requests/'.$this->returnRequest->hash_id,
                'entity_type' => 'return_request',
                'entity_id' => $this->returnRequest->hash_id,
                'reason' => 'The party email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
