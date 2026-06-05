<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnPurchaseRequestApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $pr = $event->purchaseRequest;

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.pr_approved', [
                'title'       => "PR {$pr->pr_number} Approved",
                'message'     => "Ready to convert to PO.",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseRequestApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
