<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\MachineBreakdownDetected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnMachineBreakdown implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(MachineBreakdownDetected $event): void
    {
        try {
            $machine = $event->machine;

            $roles = array_values(array_filter((array) $this->settings->get('maintenance.machine_breakdown.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $woInfo = $event->pausedWorkOrder
                ? " WO {$event->pausedWorkOrder->wo_number} was paused."
                : '';

            $reason = $event->reason ? " Reason: {$event->reason}." : '';

            $this->notifications->send($audience, 'maintenance.breakdown', [
                'title'       => "Machine Breakdown — {$machine->machine_code}",
                'message'     => "{$machine->name} is down.{$woInfo}{$reason}",
                'link_to'     => "/maintenance/machines/{$machine->hash_id}",
                'entity_type' => 'machine',
                'entity_id'   => $machine->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnMachineBreakdown failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
