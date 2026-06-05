<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnWorkOrderCompleted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $wo = $event->workOrder->loadMissing('product:id,name');

            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['ppc_head', 'production_manager']))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'production.wo_completed', [
                'title'       => "Work Order {$wo->wo_number} Completed",
                'message'     => "{$wo->product?->name} — {$wo->quantity_good} units produced. Ready for outgoing QC.",
                'link_to'     => "/production/work-orders/{$wo->hash_id}",
                'entity_type' => 'work_order',
                'entity_id'   => $wo->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnWorkOrderCompleted failed', ['error' => $e->getMessage()]);
        }
    }
}
