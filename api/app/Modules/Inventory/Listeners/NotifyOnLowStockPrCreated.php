<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\LowStockPrCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLowStockPrCreated implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(LowStockPrCreated $event): void
    {
        try {
            $item = $event->item;
            $pr   = $event->purchaseRequest;

            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['purchasing_officer', 'warehouse_staff']))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'inventory.low_stock', [
                'title'       => "Low Stock — {$item->code}",
                'message'     => "{$item->name} below reorder point. Auto-PR {$pr->pr_number} created.",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLowStockPrCreated failed', ['error' => $e->getMessage()]);
        }
    }
}
