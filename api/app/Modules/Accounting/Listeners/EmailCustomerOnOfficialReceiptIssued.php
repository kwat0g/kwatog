<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Events\OfficialReceiptIssued;
use App\Modules\Accounting\Mail\CustomerOfficialReceiptMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCustomerOnOfficialReceiptIssued implements ShouldQueue
{
    public int $tries = 3;

    public function handle(OfficialReceiptIssued $event): void
    {
        $receipt = $event->officialReceipt;
        $receipt->loadMissing('invoice');
        $invoice = $receipt->invoice;
        $snapshot = $event->snapshot;
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/accounting/invoices/'.($snapshot['invoice_hash_id'] ?? ''),
            'entity_type' => 'official_receipt',
            'entity_id' => $snapshot['receipt_hash_id'],
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];

        if (! filter_var($snapshot['customer_email'], FILTER_VALIDATE_EMAIL)) {
            if ($invoice) {
                $fallback->notifyPermission(
                    'accounting.invoices.view',
                    'Official receipt email',
                    "Official receipt {$snapshot['or_number']} was issued, but the customer has no usable email address. Send the receipt through an approved channel.",
                    $context,
                    $invoice,
                );
            } else {
                Log::warning('Official receipt email fallback skipped: linked invoice is unavailable.', [
                    'official_receipt_id' => $receipt->id,
                ]);
            }

            return;
        }

        try {
            Mail::to($snapshot['customer_email'])->queue(new CustomerOfficialReceiptMail(
                $receipt,
                $snapshot,
                $fallback->userIdsWithPermission('accounting.invoices.view'),
            ));
        } catch (\Throwable $e) {
            if ($invoice) {
                $fallback->notifyPermission(
                    'accounting.invoices.view',
                    'Official receipt email',
                    "Official receipt {$snapshot['or_number']} could not be accepted by the email provider. Send the receipt through an approved channel.",
                    $context,
                    $invoice,
                );
            } else {
                Log::warning('Official receipt email fallback skipped: linked invoice is unavailable.', [
                    'official_receipt_id' => $receipt->id,
                ]);
            }
            Log::warning('Official receipt email enqueue failed', [
                'official_receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
