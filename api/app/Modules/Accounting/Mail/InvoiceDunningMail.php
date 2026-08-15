<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceDunningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $tier,
        public readonly int $daysOverdue,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        // Never enqueue a reminder that belongs to a rolled-back invoice scan.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $no = $this->invoice->invoice_number ?? 'INV';

        return new Envelope(
            subject: "Reminder: Invoice {$no} is {$this->daysOverdue} days overdue",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-dunning',
            with: [
                'invoice' => $this->invoice,
                'customer' => $this->invoice->customer,
                'daysOverdue' => $this->daysOverdue,
                'tier' => $this->tier,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer AR reminder',
            "The overdue invoice reminder for {$this->invoice->invoice_number} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/accounting/invoices',
                'entity_type' => 'invoice',
                'entity_id' => $this->invoice->hash_id,
                'reason' => 'The email provider rejected or could not deliver the reminder.',
            ],
        );
    }
}
