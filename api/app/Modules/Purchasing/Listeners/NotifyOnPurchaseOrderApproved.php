<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnPurchaseOrderApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PurchaseOrderApproved $event): void
    {
        try {
            $po = $event->purchaseOrder->loadMissing('vendor:id,name');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.po_approved', [
                'title'       => "PO {$po->po_number} Approved",
                'message'     => "Ready to send to {$po->vendor?->name}.",
                'link_to'     => "/purchasing/purchase-orders/{$po->hash_id}",
                'entity_type' => 'purchase_order',
                'entity_id'   => $po->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseOrderApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
