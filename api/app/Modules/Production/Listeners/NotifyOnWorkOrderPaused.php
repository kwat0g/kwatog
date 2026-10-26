<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnWorkOrderPaused implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(WorkOrderStatusChanged $event): void
    {
        try {
            if ($event->to !== WorkOrderStatus::Paused->value) {
                return;
            }

            $wo = WorkOrder::query()->find($event->workOrder->id);
            if (! $wo || $wo->status !== WorkOrderStatus::Paused) {
                return;
            }

            $breakdownPause = MachineDowntime::query()
                ->where('work_order_id', $wo->id)
                ->where('category', MachineDowntimeCategory::Breakdown->value)
                ->whereNull('end_time')
                ->exists();
            if ($breakdownPause) {
                return;
            }

            $roles = array_values(array_filter((array) $this->settings->get('production.work_order_paused.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission('production.view') && $user->hasPermission('production.work_orders.view'))
                ->values();

            $reason = $event->reason ? " Reason: {$event->reason}." : '';

            $this->notifications->sendForSubject($wo, $audience, 'production.wo_paused', [
                'title' => "Work Order {$wo->wo_number} Paused",
                'message' => "Production is stopped.{$reason}",
                'link_to' => "/production/work-orders/{$wo->hash_id}",
                'entity_type' => 'work_order',
                'entity_id' => $wo->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnWorkOrderPaused failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
