<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSalesOrderConfirmed implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(SalesOrderConfirmed $event): void
    {
        try {
            $so = $event->salesOrder->loadMissing('customer:id,name');

            $roles = array_values(array_filter((array) $this->settings->get('crm.sales_order_confirmed.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
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
            throw $e;
        }
    }
}
