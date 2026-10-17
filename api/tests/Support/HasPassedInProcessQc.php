<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;

/**
 * Seeds an already-approved QC starting state for focused downstream tests.
 * End-to-end chain tests must create and approve QC through the real workflow.
 */
trait HasPassedInProcessQc
{
    protected function passInProcessQc(WorkOrder $workOrder): Inspection
    {
        $inspection = Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $workOrder->id)
            ->whereNull('wo_operation_id')
            ->where('status', '!=', InspectionStatus::Cancelled->value)
            ->first();

        $inspectorId = $workOrder->created_by
            ?: User::factory()->create()->id;
        if ($inspection?->status === InspectionStatus::Failed) {
            throw new \LogicException('A failed in-process QC inspection cannot be replaced by a passing test fixture.');
        }

        if ($inspection) {
            $reviewerId = (int) $inspection->reviewed_by;
            if ($reviewerId < 1 || $reviewerId === (int) $inspection->inspector_id) {
                $reviewerId = User::factory()->create()->id;
            }

            $inspection->forceFill([
                'status' => InspectionStatus::Passed,
                'startup_trial_required' => $inspection->trialOutput()->exists() ? $inspection->startup_trial_required : false,
                'inspector_id' => $inspection->inspector_id ?: $inspectorId,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $inspection->reviewed_at ?? now(),
                'completed_at' => $inspection->completed_at ?? now(),
            ])->save();

            return $inspection->fresh();
        }

        $reviewerId = User::factory()->create()->id;

        return Inspection::create([
            'inspection_number' => 'QC-START-'.strtoupper(substr(uniqid(), -6)),
            'stage' => InspectionStage::InProcess->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $workOrder->product_id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id,
            'batch_quantity' => max(1, (int) $workOrder->quantity_target),
            'sample_size' => 1,
            'accepted_quantity' => max(1, (int) $workOrder->quantity_target),
            'inspector_id' => $inspectorId,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
