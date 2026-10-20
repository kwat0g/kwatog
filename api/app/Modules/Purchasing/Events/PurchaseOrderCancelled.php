<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Common\Services\CurrencyDisplayService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3 M-12. Fired by PurchaseOrderService::cancel() or reject() once the
 * PO is marked Cancelled. Mirrors PurchaseOrderApproved so future listeners
 * (e.g. notify supplier, free reservations) can subscribe without the
 * service knowing about them.
 */
class PurchaseOrderCancelled
{
    use Dispatchable, SerializesModels;

    /** @param array<string, mixed>|null $snapshot */
    public function __construct(public PurchaseOrder $purchaseOrder, public ?array $snapshot = null)
    {
        $purchaseOrder->loadMissing('vendor');
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $this->snapshot ??= [
            'po_hash_id' => (string) $purchaseOrder->hash_id,
            'po_number' => (string) $purchaseOrder->po_number,
            'purchase_order_date' => $purchaseOrder->date?->format('M d, Y'),
            'cancelled_at' => now()->format('M d, Y'),
            'display_total' => app(CurrencyDisplayService::class)->format($purchaseOrder->total_amount),
            'supplier_name' => (string) ($purchaseOrder->vendor?->contact_person ?: $purchaseOrder->vendor?->name ?: 'Supplier'),
            'recipient_email' => $purchaseOrder->vendor?->email,
            'sent_to_supplier' => $purchaseOrder->sent_to_supplier_at !== null,
            'portal_url' => $base.'/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id,
        ];
    }
}
