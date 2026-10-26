<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\ApprovalService;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderSubmitted;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class NotifyOnPurchaseOrderSubmitted implements ShouldQueue
{
    public function handle(PurchaseOrderSubmitted $event): void
    {
        try {
            $po = $event->purchaseOrder->fresh(['vendor:id,name']) ?? $event->purchaseOrder;
            $next = app(ApprovalService::class)->nextStep($po);
            if ($next === null) {
                return;
            }
            $notifications = app(NotificationService::class);
            $access = app(PurchaseOrderAccessPolicy::class);
            $audience = $notifications->usersWithPermission('purchasing.po.approve')
                ->filter(fn (User $user): bool => $user->role?->slug === $next->role_slug
                    && $user->hasPermission('purchasing.view')
                    && $access->canView($user, $po))
                ->values();
            $notifications->sendForSubject($po, $audience, 'purchasing.po_pending_approval', [
                'title' => "PO {$po->po_number} pending approval",
                'message' => "Purchase order {$po->po_number} is waiting for {$next->role_slug} review.",
                'link_to' => '/purchasing/purchase-orders/'.$po->hash_id,
                'entity_type' => 'purchase_order',
                'entity_id' => $po->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PO pending-approval notification failed', ['purchase_order_id' => $event->purchaseOrder->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
