<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Bill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierInvoiceStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly Bill $bill,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Supplier invoice {$this->bill->bill_number} received");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/supplier/invoice-status',
            with: [
                'bill' => $this->bill,
                'portalUrl' => $base.'/portal/supplier/invoices/'.$this->bill->hash_id,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier invoice email',
            "The confirmation email for supplier invoice {$this->bill->bill_number} could not be delivered. Review the submission and use the supplier portal or an approved channel.",
            [
                'link_to' => '/accounting/bills/'.$this->bill->hash_id,
                'entity_type' => 'bill',
                'entity_id' => $this->bill->hash_id,
                'reason' => 'The supplier email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
