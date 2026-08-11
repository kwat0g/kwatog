<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\SupplierDispatchResult;

/**
 * Pull-based supplier portal adapter.
 *
 * The portal already lists approved purchase orders for the owning vendor.
 * This adapter records that the PO has a reachable portal audience; it does
 * not claim an email or an external transmission. The internal send action
 * remains the proof boundary for the `sent` PO state.
 */
class SupplierPortalDispatchGateway implements SupplierDispatchGateway
{
    public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult
    {
        $recipientCount = SupplierPortalUser::query()
            ->where('vendor_id', $purchaseOrder->vendor_id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->count();

        if ($recipientCount < 1) {
            return SupplierDispatchResult::manualRequired(
                'No active supplier portal user is available. Send the approved PO PDF through an approved channel, then confirm transmission.',
                [
                    'idempotency_key' => $idempotencyKey,
                    'next_action' => 'send_pdf_and_confirm',
                ],
            );
        }

        return SupplierDispatchResult::portalAvailable($recipientCount, [
            'idempotency_key' => $idempotencyKey,
            'delivery_mode' => 'pull_based_portal',
        ]);
    }
}
