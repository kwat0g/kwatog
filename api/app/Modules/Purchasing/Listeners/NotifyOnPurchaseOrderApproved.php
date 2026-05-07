<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C2. After a PO is fully approved, notify Purchasing
 * staff that it's ready to be sent to the supplier (PDF flow).
 *
 * The actual "mark as sent" action remains a deliberate Purchasing-side
 * step (current flow). This listener is informational — it surfaces the
 * to-do without auto-emailing the vendor (auto-email belongs to a
 * separate listener once vendor.email validation is in place).
 *
 * Best-effort.
 */
class NotifyOnPurchaseOrderApproved implements ShouldQueue
{
    public function handle(PurchaseOrderApproved $event): void
    {
        try {
            $po = $event->purchaseOrder->loadMissing('vendor:id,name');

            User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get()
                ->each(function (User $user) use ($po) {
                    $user->notifications()->create([
                        'id'              => (string) Str::uuid(),
                        'type'            => 'chain.po_approved',
                        'notifiable_type' => $user::class,
                        'notifiable_id'   => $user->id,
                        'data'            => [
                            'po_id'     => $po->hash_id,
                            'po_number' => $po->po_number,
                            'vendor'    => $po->vendor?->name,
                            'message'   => "PO {$po->po_number} approved — ready to send to {$po->vendor?->name}.",
                            'link'      => "/purchasing/purchase-orders/{$po->hash_id}",
                        ],
                        'read_at'         => null,
                    ]);
                });
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseOrderApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
