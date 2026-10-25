<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceDunningMail extends QueuedMailable
{
    use SerializesModels;

    private readonly array $snapshot;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $tier,
        public readonly int $daysOverdue,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        // Never enqueue a reminder that belongs to a rolled-back invoice scan.
        $this->afterCommit();

        $invoice->loadMissing('customer');
        $this->snapshot = [
            'invoice_number' => $this->safeMarkdownText($invoice->invoice_number),
            'invoice_number_subject' => $this->safeHeaderText($invoice->invoice_number),
            'customer_email' => $invoice->customer?->email,
            'customer_name' => $this->safeMarkdownText(
                ($invoice->customer?->contact_person ?: $invoice->customer?->name),
                'there',
            ),
            'invoice_date' => $this->formatDate($invoice->date?->toDateString()),
            'due_date' => $this->formatDate($invoice->due_date?->toDateString()),
            'balance' => app(CurrencyDisplayService::class)->format($invoice->balance),
            'hash_id' => (string) $invoice->hash_id,
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/invoices/'.$invoice->hash_id,
        ];
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();
        $subject = "Payment reminder: {$this->snapshot['invoice_number_subject']} · {$this->snapshot['balance']} · {$this->daysOverdue} days overdue";

        return new Envelope(
            subject: $this->safeHeaderText('['.$brand['name'].'] '.$subject),
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.invoice-dunning',
            with: [
                'snapshot' => $this->snapshot,
                'daysOverdue' => $this->daysOverdue,
                'preheader' => 'Invoice '.$this->snapshot['invoice_number_subject'].' is '.$this->daysOverdue.' days overdue. Balance at reminder generation: '.$this->snapshot['balance'].'.',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer AR reminder',
            "The overdue invoice reminder for {$this->snapshot['invoice_number_subject']} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/accounting/invoices',
                'entity_type' => 'invoice',
                'entity_id' => $this->snapshot['hash_id'],
                'reason' => 'The email provider rejected or could not deliver the reminder.',
            ],
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
