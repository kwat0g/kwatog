<?php

declare(strict_types=1);

namespace App\Modules\B2B\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
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
        $bill = $event->bill->loadMissing(['vendor', 'purchaseOrder']);
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/accounting/bills/'.$bill->hash_id,
            'entity_type' => 'bill',
            'entity_id' => $bill->hash_id,
            'reason' => 'The supplier email was missing, invalid, unreachable, or rejected by the email provider.',
        ];

        if (! filter_var($bill->vendor?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'accounting.vendors.view',
                'Supplier invoice email',
                "Supplier invoice {$bill->bill_number} was submitted, but the supplier has no usable email address. Confirm receipt through the supplier portal or an approved channel.",
                $context,
            );
            return;
        }

        try {
            Mail::to($bill->vendor->email)->queue(new SupplierInvoiceStatusMail(
                $bill,
                $fallback->userIdsWithPermission('accounting.vendors.view'),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'accounting.vendors.view',
                'Supplier invoice email',
                "The confirmation email for supplier invoice {$bill->bill_number} could not be accepted by the email provider. Review the submission in Accounts Payable.",
                $context,
            );
            Log::warning('Supplier invoice email enqueue failed', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
