<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
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
        $brand = app(EmailBrandingService::class)->data();
        $reference = $this->safeHeaderText((string) ($this->snapshot['rma_number'] ?? $this->returnRequest->rma_number));

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] Return request update: {$reference}"));
    }

    public function content(): Content
    {
        $snapshot = $this->snapshot !== [] ? $this->snapshot : $this->legacySnapshot();
        $snapshot['rma_number'] = $this->safeMarkdownText($snapshot['rma_number'] ?? null, 'Return request');
        $snapshot['status_label'] = $this->safeMarkdownText($snapshot['status_label'] ?? null, 'Updated');
        $snapshot['party_contact_name'] = $this->safeMarkdownText($snapshot['party_contact_name'] ?? null, '');
        $snapshot['party_name'] = $this->safeMarkdownText($snapshot['party_name'] ?? null, '');
        $brand = app(EmailBrandingService::class)->data();
        $isCustomer = ($snapshot['type'] ?? null) === 'customer_return';
        $portalUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').($isCustomer
            ? '/portal/customer/returns/'.($snapshot['hash_id'] ?? $this->returnRequest->hash_id)
            : '/portal/supplier');
        $notice = $this->notice($snapshot);

        return new Content(
            markdown: 'emails/returns/return-update',
            with: [
                'brand' => $brand,
                'title' => $notice['title'],
                'preheader' => $notice['preheader'],
                'notice' => $notice,
                'portalLabel' => $isCustomer ? 'customer' : 'supplier',
                'portalButtonLabel' => $isCustomer ? 'View return request' : 'Open supplier portal',
                'portalInstruction' => $isCustomer
                    ? 'Sign in to review the full return record and any action available to your portal account.'
                    : 'Sign in to your supplier portal. For questions about this update, use the company contact details in the footer.',
                'portalUrl' => $portalUrl,
                'emailSnapshot' => $snapshot,
                'action' => $snapshot['action'] ?? $this->action,
            ],
        );
    }

    /** @return array{title:string,preheader:string,message:string} */
    private function notice(array $snapshot): array
    {
        $message = match ($snapshot['action'] ?? $this->action) {
            'submitted for approval' => 'Your return request was submitted for review.',
            'supplier return sent' => 'The supplier return was sent for processing.',
            'approved' => 'Your return request was approved.',
            'received' => 'The returned items were recorded as received.',
            'partial receipt recorded' => 'A partial receipt was recorded for this return.',
            'inspection completed' => 'Inspection was completed for this return.',
            'return line split' => 'The return quantity allocation was updated.',
            'disposition recorded' => 'The returned items’ disposition was recorded.',
            'completed' => 'The return request was completed.',
            'rejected' => 'The return request was rejected.',
            'cancelled' => 'The return request was cancelled.',
            default => 'Your return request record was updated.',
        };

        return [
            'title' => 'Return request update',
            'preheader' => $this->safeMarkdownText((string) ($snapshot['rma_number'] ?? 'Return request').' · '.(string) ($snapshot['status_label'] ?? 'Updated'), 'Return request updated.'),
            'message' => $message,
        ];
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Return request update');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $value = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $value) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback;
    }

    /** @return array<string, mixed> */
    private function legacySnapshot(): array
    {
        $isCustomer = $this->returnRequest->type?->value === 'customer_return';
        $party = $isCustomer ? $this->returnRequest->customer : $this->returnRequest->vendor;

        return [
            'rma_number' => $this->returnRequest->rma_number,
            'hash_id' => $this->returnRequest->hash_id,
            'type' => $this->returnRequest->type?->value,
            'type_label' => $this->returnRequest->type?->label() ?? 'Return Request',
            'status_label' => $this->returnRequest->status?->label() ?? 'Updated',
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
