<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Validation\ValidationException;

/** The single authoritative lifecycle transition table for maintenance WOs. */
final class MaintenanceWorkOrderStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'open' => ['assigned', 'in_progress', 'cancelled'],
        'assigned' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function transition(MaintenanceWorkOrder $workOrder, MaintenanceWorkOrderStatus $target): void
    {
        $current = $workOrder->status instanceof MaintenanceWorkOrderStatus
            ? $workOrder->status
            : MaintenanceWorkOrderStatus::tryFrom((string) $workOrder->getRawOriginal('status'));

        if ($current === null) {
            throw ValidationException::withMessages([
                'status' => ['Maintenance work order status is invalid; the lifecycle cannot continue.'],
            ]);
        }

        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf(
                    'Maintenance work order cannot transition from %s to %s.',
                    $current->label(),
                    $target->label(),
                )],
            ]);
        }

        $workOrder->status = $target;
    }

    public function assertInProgress(MaintenanceWorkOrder $workOrder, string $action): void
    {
        if ($workOrder->status !== MaintenanceWorkOrderStatus::InProgress) {
            throw ValidationException::withMessages([
                'status' => ["A maintenance work order must be in progress before {$action}."],
            ]);
        }
    }
}
