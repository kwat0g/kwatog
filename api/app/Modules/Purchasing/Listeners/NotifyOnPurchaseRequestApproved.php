<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C2. After a PR is fully approved, notify Purchasing
 * Officer that it can be converted into one or more POs (per vendor).
 *
 * Auto-PO consolidation deferred: AutoPurchaseOrderService.createForCriticalShortage
 * is a single-item entry point. A multi-item, multi-vendor consolidator that
 * reads "all newly approved PRs" needs a small extension to that service +
 * a vendor-grouping policy that's currently not codified. Captured in the
 * follow-up backlog; this listener surfaces the to-do without auto-acting.
 *
 * Best-effort.
 */
class NotifyOnPurchaseRequestApproved implements ShouldQueue
{
    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $pr = $event->purchaseRequest;

            User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get()
                ->each(function (User $user) use ($pr) {
                    $user->notifications()->create([
                        'id'              => (string) Str::uuid(),
                        'type'            => 'chain.pr_approved',
                        'notifiable_type' => $user::class,
                        'notifiable_id'   => $user->id,
                        'data'            => [
                            'pr_id'     => $pr->hash_id,
                            'pr_number' => $pr->pr_number,
                            'message'   => "PR {$pr->pr_number} approved — ready to convert to PO.",
                            'link'      => "/purchasing/purchase-requests/{$pr->hash_id}",
                        ],
                        'read_at'         => null,
                    ]);
                });
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseRequestApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
