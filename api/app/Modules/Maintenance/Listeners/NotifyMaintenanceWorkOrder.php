<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Support\Facades\Log;

class NotifyMaintenanceWorkOrder
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(MaintenanceWorkOrderCreated $event): void
    {
        $order = $event->workOrder;
        $supervisors = User::query()->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'maintenance_supervisor'))
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('maintenance.view'))
            ->values();
        if ($supervisors->isEmpty()) {
            Log::error('Maintenance notification setup failure: no active maintenance supervisor.', ['work_order_id' => $order->id]);

            return;
        }
        $this->notifications->sendForSubject($order, $supervisors, 'maintenance.assignment_needed', self::payload(
            $order, 'Maintenance assignment needed: '.$order->mwo_number,
            'Review this maintenance order and assign a technician.',
        ), 'maintenance:new:'.$order->id);
    }

    public static function payload(MaintenanceWorkOrder $order, string $title, string $message): array
    {
        return [
            'title' => $title, 'message' => $message,
            'link_to' => '/maintenance/work-orders/'.$order->hash_id,
            'entity_type' => 'maintenance_work_order', 'entity_id' => $order->hash_id,
        ];
    }
}
