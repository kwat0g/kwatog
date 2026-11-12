<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Models\PriceAgreement;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PriceAgreementTerminationNoticeMail extends QueuedMailable
{
    use SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $customerName,
        public readonly string $productName,
        public readonly string $partNumber,
        public readonly string $agreementId,
        public readonly string $documentName,
        public readonly string $event,
        public readonly string $kind,
        public readonly ?string $lastValidDate,
        public readonly array $fallbackUserIds,
        public readonly PriceAgreement $agreement,
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();
        $subject = match ($this->event) {
            'signature_request' => 'Signature requested: price agreement change',
            'request_cancelled' => 'Price agreement signature request cancelled',
            default => 'Price agreement updated',
        };

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] {$subject} · {$this->partNumber}"));
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $scheduled = $this->kind === 'scheduled_cancellation';
        $title = match ($this->event) {
            'signature_request' => $scheduled ? 'Review a scheduled price agreement cancellation' : 'Review a price agreement termination',
            'request_cancelled' => 'A price agreement signature request was cancelled',
            default => $scheduled ? 'Scheduled price agreement cancelled' : 'Price agreement termination signed',
        };

        return new Content(
            markdown: 'emails.customer.price-agreement-termination',
            with: [
                'brand' => $brand,
                'title' => $title,
                'preheader' => $this->event === 'signature_request'
                    ? 'An authorized customer signatory needs to review a price agreement document.'
                    : 'A customer price agreement document has been updated.',
                'notice' => [
                    'customer_name' => $this->customerName,
                    'product_name' => $this->productName,
                    'part_number' => $this->partNumber,
                    'document_name' => $this->documentName,
                    'event' => $this->event,
                    'scheduled' => $scheduled,
                    'last_valid_date' => $this->lastValidDate,
                    'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/agreements',
                ],
            ],
        );
    }

    public function failed(\Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Price agreement customer notice',
            "The notice for {$this->partNumber} could not be delivered. Contact the customer using an approved channel.",
            [
                'link_to' => '/crm/price-agreements?agreement='.$this->agreementId,
                'entity_type' => 'price_agreement',
                'entity_id' => $this->agreementId,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
            $this->agreement,
        );
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Price agreement update');
    }
}
