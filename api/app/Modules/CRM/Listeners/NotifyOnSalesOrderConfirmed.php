<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C1. After SO is confirmed (and the MRP run has already
 * fired synchronously inside SalesOrderService::confirm()), notify the
 * PPC head + Production Manager that a new order is in their queue.
 *
 * Idempotent: notifications carry the SO hash so duplicate dispatches
 * just create extra rows — they don't double-process anything downstream.
 *
 * Best-effort: any throw is swallowed and logged. We never want a flaky
 * notification path to roll back an order confirmation.
 */
class NotifyOnSalesOrderConfirmed implements ShouldQueue
{
    public function handle(SalesOrderConfirmed $event): void
    {
        try {
            $so = $event->salesOrder->loadMissing('customer:id,name');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['ppc_head', 'production_manager']))
                ->where('is_active', true)
                ->get();

            $message = "Sales order {$so->so_number} confirmed for {$so->customer?->name}. MRP run completed.";

            foreach ($audience as $user) {
                $user->notifications()->create([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'chain.so_confirmed',
                    'notifiable_type' => $user::class,
                    'notifiable_id'   => $user->id,
                    'data'            => [
                        'so_id'     => $so->hash_id,
                        'so_number' => $so->so_number,
                        'customer'  => $so->customer?->name,
                        'message'   => $message,
                        'link'      => "/crm/sales-orders/{$so->hash_id}",
                    ],
                    'read_at'         => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSalesOrderConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
