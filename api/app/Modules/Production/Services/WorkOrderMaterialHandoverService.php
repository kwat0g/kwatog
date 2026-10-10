<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Collection;

class WorkOrderMaterialHandoverService
{
    public const OUTPUT_PENDING_MESSAGE = 'Warehouse handover is pending. Ask Warehouse to confirm before recording output.';

    public function pending(WorkOrder $workOrder): Collection
    {
        return StockMovement::query()->forWorkOrder($workOrder->id)
            ->pendingHandover()->withHandoverBalance()->orderBy('id')->get();
    }

    public function summary(WorkOrder $workOrder): array
    {
        $sources = StockMovement::query()->forWorkOrder($workOrder->id)->handoverSources()
            ->whereRaw('stock_movements.quantity > '.StockMovement::HANDOVER_RETURNED_SQL)
            ->withExists('handover')->get();
        $handedOver = $sources->where('handover_exists', true)->count();
        $pending = $sources->count() - $handedOver;

        return ['total' => $pending + $handedOver, 'handed_over' => $handedOver, 'pending' => $pending];
    }

    /** Called under the parent WO lock, after existing output authorization. */
    public function assertForOutput(WorkOrder $workOrder, User $actor, array $data): ?array
    {
        $pending = $this->pending($workOrder);
        if ($pending->isEmpty()) {
            return null;
        }
        if (! $actor->is_active || ! $actor->hasPermission('production.wo.record')
            || ! $actor->hasPermission('production.work_orders.lifecycle')
            || ! in_array($data['material_handover_acknowledged'] ?? null, [true, 1, '1'], true)) {
            throw new BusinessRuleException(self::OUTPUT_PENDING_MESSAGE);
        }
        $reason = $data['material_handover_reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new BusinessRuleException('Explain the pending Warehouse confirmation in 500 characters or fewer.');
        }

        return [
            'manager_id' => $actor->hash_id,
            'manager_name' => $actor->name,
            'recorded_at' => now()->toIso8601String(),
            'reason' => trim($reason),
            'sources' => $pending->map(fn (StockMovement $source): array => [
                'movement_id' => $source->hash_id,
                'remaining_quantity' => bcadd((string) $source->remaining_quantity, '0', 3),
            ])->all(),
        ];
    }

    public function assertForCompletion(WorkOrder $workOrder): void
    {
        if ($this->pending($workOrder)->isNotEmpty()) {
            throw new BusinessRuleException('Confirm material handover before completing the work order.');
        }
    }
}
