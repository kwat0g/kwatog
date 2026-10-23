<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\Production\Models\WorkOrder;

/** D07 — active production orders beyond their planned completion date. */
class WorkOrderInterventionDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'dss.work_order_intervention';
    }

    public function domain(): string
    {
        return 'production';
    }

    public function ownerPermissions(): array
    {
        return ['production.work_orders.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $rows = WorkOrder::query()
            ->with([
                'product:id,part_number,unit_of_measure',
                'salesOrder:id,so_number',
                'salesOrderItem:id,sales_order_id,product_id',
                'salesOrderItem.product:id,part_number',
            ])
            ->whereIn('status', ['confirmed', 'in_progress', 'paused'])
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', now())
            ->orderBy('planned_end')
            ->limit($limit + 1)
            ->get(['id', 'wo_number', 'status', 'planned_end', 'quantity_target', 'quantity_good']);

        $emissions = $rows->take($limit)->map(function (WorkOrder $workOrder): DetectorEmission {
            $daysLate = $workOrder->planned_end->diffInDays(now(), true);

            return new DetectorEmission(
                type: 'work_order_overdue',
                severity: 'warning',
                dedupeKey: 'work-order:'.$workOrder->id,
                rationale: [
                    'rule' => 'active work order passed planned completion',
                    'work_order' => $workOrder->wo_number,
                    'status' => $workOrder->status->value,
                    'planned_end' => $workOrder->planned_end->toIso8601String(),
                    'days_overdue' => $daysLate,
                    'quantity_target' => (string) $workOrder->quantity_target,
                    'quantity_good' => (string) $workOrder->quantity_good,
                    'remaining_quantity' => (string) max(0, (int) $workOrder->quantity_target - (int) $workOrder->quantity_good),
                    'unit_of_measure' => $workOrder->product?->unit_of_measure,
                ],
                subjectType: WorkOrder::class,
                subjectId: (int) $workOrder->id,
                confidence: 1.0,
                action: ['route' => '/production/work-orders/'.$workOrder->hash_id, 'label' => 'Review work order →'],
                ownerRoleSlugs: ['production_manager'],
                decisionCode: 'work_order_overdue',
                policyVersion: $this->version(),
                sourceReferences: array_values(array_filter([
                    [
                        'source_kind' => 'work_order',
                        'public_id' => (string) $workOrder->hash_id,
                        'display_reference' => (string) $workOrder->wo_number,
                        'destination' => '/production/work-orders/'.$workOrder->hash_id,
                    ],
                    $workOrder->salesOrder !== null && $workOrder->salesOrderItem !== null
                        && $workOrder->salesOrderItem->sales_order_id === $workOrder->salesOrder->id
                        ? [
                            'source_kind' => 'sales_order',
                            'public_id' => (string) $workOrder->salesOrder->hash_id,
                            'display_reference' => (string) $workOrder->salesOrder->so_number
                                .($workOrder->salesOrderItem->product?->part_number
                                    ? ' · '.$workOrder->salesOrderItem->product->part_number
                                    : ''),
                            'destination' => '/crm/sales-orders/'.$workOrder->salesOrder->hash_id,
                        ]
                        : null,
                ])),
                verificationContext: [
                    'source_id' => (int) $workOrder->id,
                    'planned_end' => $workOrder->planned_end->toIso8601String(),
                ],
                businessDueAt: $workOrder->planned_end,
            );
        })->all();

        return new DetectorScanResult($emissions, $rows->count(), $rows->count() <= $limit);
    }
}
