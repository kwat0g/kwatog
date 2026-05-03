<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Events;

use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 8 — Tasks 69 + 78. Fires when a new maintenance WO is created
 * (preventive auto from cron, or corrective from the UI). Drives the
 * maintenance dashboard's live tile updates over the
 * `maintenance.dashboard` private channel (registered in routes/channels.php).
 */
class MaintenanceWorkOrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MaintenanceWorkOrder $workOrder) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('maintenance.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'maintenance.wo_created';
    }

    public function broadcastWith(): array
    {
        $wo = $this->workOrder;
        return [
            'id'                => $wo->hash_id,
            'mwo_number'        => $wo->mwo_number,
            'maintainable_type' => $wo->maintainable_type instanceof \BackedEnum ? $wo->maintainable_type->value : $wo->maintainable_type,
            'type'              => $wo->type instanceof \BackedEnum ? $wo->type->value : $wo->type,
            'priority'          => $wo->priority instanceof \BackedEnum ? $wo->priority->value : $wo->priority,
            'status'            => $wo->status instanceof \BackedEnum ? $wo->status->value : $wo->status,
            'description'       => $wo->description,
            'created_at'        => optional($wo->created_at)?->toISOString(),
        ];
    }
}
