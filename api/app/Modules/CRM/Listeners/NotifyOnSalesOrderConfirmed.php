<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSalesOrderConfirmed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SalesOrderConfirmed $event): void
    {
        try {
            $so = $event->salesOrder->loadMissing('customer:id,name');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['ppc_head', 'production_manager']))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.so_confirmed', [
                'title'       => "SO {$so->so_number} Confirmed",
                'message'     => "Sales order confirmed for {$so->customer?->name}. MRP run completed.",
                'link_to'     => "/crm/sales-orders/{$so->hash_id}",
                'entity_type' => 'sales_order',
                'entity_id'   => $so->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSalesOrderConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
