<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnCaseUpdateMail extends QueuedMailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $reference,
        public readonly string $statusLabel,
        public readonly string $realm,
        public readonly string $caseHash,
        public readonly array $fallbackUserIds,
        public readonly ?string $deliveryId = null,
    ) {
        $this->onQueue('mail');
        $this->deliveryKey = $deliveryId;
        $this->afterCommit();
    }

    public static function deliveryIdFor(int $eventId, string $email): string
    {
        return hash('sha256', 'return-case-update|'.$eventId.'|'.mb_strtolower(trim($email)));
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] Return case update: {$this->reference}"));
    }

    public function content(): Content
    {
        $title = 'A problem report has been updated';
        $statusLabel = $this->safeMarkdownText($this->statusLabel, 'Updated');

        return new Content(markdown: 'emails.returns.case-update', with: [
            'brand' => app(EmailBrandingService::class)->data(),
            'title' => $title,
            'preheader' => $this->safeMarkdownText("{$this->reference} status: {$statusLabel}.", 'Problem report updated.'),
            'recipient_realm' => $this->realm === 'supplier' ? 'supplier' : 'customer',
            'reference' => $this->safeMarkdownText($this->reference, 'Problem report'),
            'status_label' => $statusLabel,
            'portalUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/'.$this->realm.'/problems/'.$this->caseHash,
        ]);
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Return case update');
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
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds($this->fallbackUserIds,
            'Return case', $this->reference.' email could not be delivered. Contact the customer or supplier to follow up.',
            ['link_to' => '/return-management/cases/'.$this->caseHash, 'entity_type' => 'return_case', 'entity_id' => $this->caseHash]);
    }
}
