<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use Illuminate\Support\Carbon;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupplierRfqLifecycleMail extends QueuedMailable
{
    use SerializesModels;

    public array $snapshot = [];

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly RequestForQuote $rfq,
        public readonly RequestForQuoteInvitation $invitation,
        public readonly string $kind,
        public readonly array $fallbackUserIds = [],
        array $snapshot = [],
    ) {
        $this->snapshot = $snapshot;
        // Retries of the same outbox event must reuse its delivery-ledger key.
        $this->deliveryKey = hash('sha256', implode('|', [
            'rfq-lifecycle',
            (string) $rfq->getKey(),
            $kind,
            (string) ($snapshot['closes_at'] ?? ''),
            (string) $invitation->getKey(),
        ]));
        $this->afterCommit();
    }

    public function rfqNumber(): string
    {
        return (string) ($this->snapshot['rfq_number'] ?? $this->rfq->rfq_number);
    }

    public function envelope(): Envelope
    {
        $notice = $this->notice();
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] {$notice['subject']}: {$this->rfqNumber()}"));
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $notice = $this->notice();
        $brand = app(EmailBrandingService::class)->data();
        $deadline = $snapshot['closes_at']
            ? Carbon::parse($snapshot['closes_at'])->setTimezone('Asia/Manila')->format('M d, Y g:i A').' Philippine time'
            : null;
        $recipientName = ($snapshot['contact_person'] ?? null) ?: ($snapshot['vendor_name'] ?? $this->invitation->vendor?->contact_person ?: $this->invitation->vendor?->name);

        return new Content(
            markdown: 'emails/supplier/rfq-lifecycle',
            with: [
                'brand' => $brand,
                'title' => $notice['title'],
                'preheader' => $notice['preheader'],
                'kind' => $this->kind,
                'notice' => [
                    ...$notice,
                    'rfq_number' => $this->safeMarkdownText($snapshot['rfq_number'] ?? $this->rfq->rfq_number, 'RFQ'),
                    'rfq_title' => $this->safeMarkdownText($snapshot['title'] ?? $this->rfq->title, 'Request for quotation'),
                    'recipient_name' => $this->safeMarkdownText($recipientName, 'Supplier'),
                    'deadline' => $deadline,
                ],
                'portalUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/supplier/rfqs/'.($snapshot['rfq_hash_id'] ?? $this->rfq->hash_id),
            ],
        );
    }

    /** @return array{subject:string,title:string,preheader:string,status:string,action:string,message:string} */
    private function notice(): array
    {
        return match ($this->kind) {
            'published' => [
                'subject' => 'RFQ invitation',
                'title' => 'You are invited to submit a quotation',
                'preheader' => 'Review the request and submit your quotation by the deadline.',
                'status' => 'Open for quotation',
                'action' => 'Review the request and submit your quotation by the deadline.',
                'message' => 'Review the request details and quotation requirements in the supplier portal.',
            ],
            'extended' => [
                'subject' => 'RFQ deadline extended',
                'title' => 'The quotation deadline has changed',
                'preheader' => 'Review the revised quotation deadline in your supplier portal.',
                'status' => 'Deadline extended',
                'action' => 'Submit your quotation by the revised deadline.',
                'message' => 'Use the revised deadline shown below when planning your response.',
            ],
            'awarded' => ($this->snapshotData()['status'] ?? null) === 'awarded'
                ? [
                    'subject' => 'RFQ result',
                    'title' => 'Your quotation was selected',
                    'preheader' => 'Your quotation was selected for one or more request lines.',
                    'status' => 'Selected',
                    'action' => 'No further action is needed while the purchase order completes internal approval.',
                    'message' => 'Your quotation was selected for one or more lines. Ogami Purchasing will send the purchase order after internal approval.',
                ]
                : [
                    'subject' => 'RFQ result',
                    'title' => 'The request has been awarded',
                    'preheader' => 'Your quotation was not selected for this request.',
                    'status' => 'Not selected',
                    'action' => 'No further action is required for this request.',
                    'message' => 'Thank you for submitting a quotation. It was not selected for this request.',
                ],
            'cancelled' => [
                'subject' => 'RFQ cancelled',
                'title' => 'The request for quotation was cancelled',
                'preheader' => 'No award will be made for this request.',
                'status' => 'Cancelled',
                'action' => 'No further action is required for this request.',
                'message' => 'No award will be made for this request. Please do not submit a quotation.',
            ],
            default => [
                'subject' => 'RFQ update',
                'title' => 'There is an update to your request',
                'preheader' => 'Review the request in the supplier portal.',
                'status' => 'Updated',
                'action' => 'Review the request in the supplier portal.',
                'message' => 'Open the supplier portal to review the latest request details.',
            ],
        };
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        if ($this->snapshot !== []) {
            return $this->snapshot;
        }

        return [
            'rfq_number' => (string) $this->rfq->rfq_number,
            'rfq_hash_id' => (string) $this->rfq->hash_id,
            'title' => (string) $this->rfq->title,
            'closes_at' => $this->rfq->closes_at?->toIso8601String(),
            'contact_person' => $this->invitation->vendor?->contact_person,
            'vendor_name' => $this->invitation->vendor?->name,
            'status' => $this->invitation->status?->value ?? $this->invitation->status,
        ];
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'RFQ update');
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

    public function failed(\Throwable $exception): void
    {
        $this->invitation->forceFill(['last_notification_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier RFQ',
            "Supplier RFQ {$this->rfq->rfq_number} email delivery failed; review portal delivery state.",
            ['link_to' => '/purchasing/rfqs/'.$this->rfq->hash_id, 'entity_type' => 'request_for_quote', 'entity_id' => $this->rfq->hash_id],
        );
    }
}
