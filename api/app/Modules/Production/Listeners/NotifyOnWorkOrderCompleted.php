<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnWorkOrderCompleted implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $wo = $event->workOrder->loadMissing('product:id,name');

            $roles = array_values(array_filter((array) $this->settings->get('production.work_order_completed.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
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
            throw $e;
        }
    }
}
