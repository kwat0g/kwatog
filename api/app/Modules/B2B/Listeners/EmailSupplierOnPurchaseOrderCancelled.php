<?php

declare(strict_types=1);

namespace App\Modules\B2B\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notify supplier when their PO is cancelled.
 *
 * Only sends to POs that were already sent to the supplier (sent_to_supplier_at
 * not null). Never-sent POs do not notify externally.
 */
class EmailSupplierOnPurchaseOrderCancelled implements ShouldQueue
{
    public int $tries = 3;

    public function handle(PurchaseOrderCancelled $event): void
    {
        $po = $event->purchaseOrder->loadMissing('vendor');

        // Only notify if the PO was actually sent to the supplier
        if ($po->sent_to_supplier_at === null) {
            return;
        }

        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/purchasing/purchase-orders/'.$po->hash_id,
            'entity_type' => 'purchase_order',
            'entity_id' => $po->hash_id,
            'reason' => 'The supplier email was missing, invalid, unreachable, or rejected by the email provider.',
        ];

        if (! filter_var($po->vendor?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'purchasing.view',
                'Supplier purchase order cancellation',
                "PO {$po->po_number} was cancelled, but the supplier has no usable email address. Contact the supplier directly.",
                $context,
            );
            return;
        }

        try {
            Mail::to($po->vendor->email)->queue(new SupplierPurchaseOrderCancelledMail(
                $po,
                $fallback->userIdsWithPermission('purchasing.view'),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'purchasing.view',
                'Supplier purchase order cancellation',
                "The cancellation email for PO {$po->po_number} could not be queued. Notify the supplier directly.",
                $context,
            );
            Log::warning('Supplier purchase order cancellation email enqueue failed', [
                'purchase_order_id' => $po->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
