<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Mail\CustomerInvoiceFinalizedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCustomerOnInvoiceFinalized implements ShouldQueue
{
    public int $tries = 3;

    public function handle(InvoiceFinalized $event): void
    {
        $invoice = $event->invoice->loadMissing([
            'customer',
            'items',
            'salesOrder',
        ]);

        app(NotificationService::class)->sendPortalInApp(CustomerPortalUser::where('customer_id', $invoice->customer_id)->where('is_active', true)->get(), 'invoice.finalized', [
            'title' => 'Invoice '.$invoice->invoice_number.' is ready', 'message' => 'Review your invoice and payment terms in the customer portal.',
            'link_to' => '/portal/customer/invoices/'.$invoice->hash_id, 'entity_type' => 'invoice', 'entity_id' => $invoice->hash_id,
        ], 'invoice:'.$invoice->id.':finalized');
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/accounting/invoices/'.$invoice->hash_id,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->hash_id,
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $feature = 'Customer invoice delivery';

        $recipient = $event->snapshot['customer_email'] ?? null;
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'accounting.invoices.view',
                $feature,
                "Invoice {$invoice->invoice_number} was finalized, but the customer has no usable email address. Review the invoice and contact the customer through an approved channel.",
                $context,
                $invoice,
            );

            return;
        }

        try {
            Mail::to($recipient)->queue(new CustomerInvoiceFinalizedMail(
                $invoice,
                $event->snapshot,
                $this->fallbackUserIds(),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'accounting.invoices.view',
                $feature,
                "The finalized invoice {$invoice->invoice_number} could not be accepted by the email provider. Contact the customer through an approved channel.",
                $context,
                $invoice,
            );
            Log::warning('Customer invoice email enqueue failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<int> */
    private function fallbackUserIds(): array
    {
        return app(EmailDeliveryFailureNotifier::class)
            ->userIdsWithPermission('accounting.invoices.view');
    }
}
