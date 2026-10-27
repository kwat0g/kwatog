<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Modules\Accounting\Models\CreditNote;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartyCreditNoteMail extends QueuedMailable
{
    private readonly array $snapshot;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        array $eventSnapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();

        $currency = app(CurrencyDisplayService::class);
        $isCustomer = ($eventSnapshot['type'] ?? null) === 'customer';
        $this->snapshot = [
            'type' => $isCustomer ? 'customer' : 'supplier',
            'type_label' => $this->safeMarkdownText($eventSnapshot['type_label'] ?? null, 'Credit note'),
            'party_name' => $this->safeMarkdownText(
                ($eventSnapshot['party_contact'] ?? null) ?: ($eventSnapshot['party_name'] ?? null),
                'there',
            ),
            'credit_note_number' => $this->safeMarkdownText($eventSnapshot['credit_note_number'] ?? null),
            'credit_note_number_subject' => $this->safeHeaderText($eventSnapshot['credit_note_number'] ?? null),
            'date' => $this->formatDate($eventSnapshot['date'] ?? null),
            'subtotal' => $currency->format($eventSnapshot['subtotal'] ?? null),
            'vat_amount' => $currency->format($eventSnapshot['vat_amount'] ?? null),
            'total_amount' => $currency->format($eventSnapshot['total_amount'] ?? null),
            'balance' => $currency->format($eventSnapshot['balance'] ?? null),
            'applied_amount' => $currency->format($eventSnapshot['applied_amount'] ?? null),
            'invoice_number' => $this->safeMarkdownText($eventSnapshot['invoice_number'] ?? null, 'Not linked'),
            'bill_number' => $this->safeMarkdownText($eventSnapshot['bill_number'] ?? null, 'Not linked'),
            'hash_id' => (string) ($eventSnapshot['hash_id'] ?? ''),
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').($isCustomer ? '/portal/customer' : '/portal/supplier'),
        ];
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();
        $partyType = $this->snapshot['type'] === 'customer' ? 'Customer' : 'Supplier';
        $subject = '['.$brand['name'].'] '.$partyType.' credit note finalized: '.$this->snapshot['credit_note_number_subject'].' · '.$this->snapshot['total_amount'];

        return new Envelope(subject: $this->safeHeaderText($subject));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/finance/credit-note-finalized',
            with: [
                'preheader' => $this->snapshot['type_label'].' note '.$this->snapshot['credit_note_number_subject'].' finalized; total '.$this->snapshot['total_amount'].'.',
                'snapshot' => $this->snapshot,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $creditNote = CreditNote::query()->whereHash($this->snapshot['hash_id'])->first();
        if ($creditNote === null) {
            return;
        }

        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Credit note email',
            "Credit note {$this->snapshot['credit_note_number_subject']} could not be delivered to the party. Send it through an approved channel.",
            [
                'link_to' => '/accounting/credit-notes/'.$this->snapshot['hash_id'],
                'entity_type' => 'credit_note',
                'entity_id' => $this->snapshot['hash_id'],
                'reason' => 'The party email was unreachable or the email provider rejected the message.',
            ],
            $creditNote,
        );
    }

    private function safeMarkdownText(?string $value, string $fallback = '—'): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\`*_{}\[\]()#+.!|>])/u', '\\$1', $text) ?? $fallback;
    }

    private function safeHeaderText(?string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '');
    }

    private function formatDate(?string $value): string
    {
        return $value === null || $value === ''
            ? 'Not specified'
            : \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
    }
}
