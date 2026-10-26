<?php

declare(strict_types=1);

namespace App\Modules\B2B\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Bill;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\B2B\Mail\SupplierInvoiceStatusMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailSupplierOnInvoiceSubmitted implements ShouldQueue
{
    public int $tries = 3;

    public function handle(SupplierInvoiceSubmitted $event): void
    {
        $bill = $event->bill;
        $snapshot = $event->snapshot;
        $invoiceNumber = $snapshot['invoice_number'];
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/accounting/bills/'.$bill->hash_id,
            'entity_type' => 'bill',
            'entity_id' => $bill->hash_id,
            'reason' => 'The supplier email was missing, invalid, unreachable, or rejected by the email provider.',
        ];

        if (! filter_var($snapshot['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            $this->notifyBillCreator(
                $bill,
                $fallback,
                "Supplier invoice {$invoiceNumber} was submitted, but the supplier has no usable email address. Confirm receipt through the supplier portal or an approved channel.",
                $context,
            );

            return;
        }

        try {
            Mail::to($snapshot['recipient_email'])->queue(new SupplierInvoiceStatusMail($snapshot));
        } catch (\Throwable $e) {
            $this->notifyBillCreator(
                $bill,
                $fallback,
                "The confirmation email for supplier invoice {$invoiceNumber} could not be accepted by the email provider. Review the submission in Accounts Payable.",
                $context,
            );
            Log::warning('Supplier invoice email enqueue failed', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array{link_to?: string, entity_type?: string, entity_id?: string, reason?: string} $context */
    private function notifyBillCreator(Bill $bill, EmailDeliveryFailureNotifier $fallback, string $message, array $context): void
    {
        $creator = $bill->creator()->where('is_active', true)->first();
        if (! $creator) {
            Log::warning('Supplier invoice email fallback skipped: no active bill creator.', ['bill_id' => $bill->id]);

            return;
        }

        $fallback->notify($creator, 'Supplier invoice email', $message, $context, $bill);
    }
}
