<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Common\Support\HashId;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trialOutput = $this->startup_trial_required ? $this->trialOutput : null;

        return [
            'final_work_order_id' => $this->resource->usesFullPieceCapture() ? app('hashids')->encode((int) $this->entity_id) : null,
            'work_order_quality' => $this->resource->getAttribute('work_order_quality'),
            'counted_capture' => $this->counted_capture,
            'historical_held' => (bool) ($this->work_order_output_id && $this->workOrderOutput?->held_quantity > 0),
            'coverage_confirmed_at' => $this->coverage_confirmed_at?->toISOString(),
            'startup_trial_required' => (bool) $this->startup_trial_required,
            'startup_trial' => $this->startup_trial_required ? [
                'required_quantity' => (int) $this->sample_size,
                'recorded_quantity' => (int) ($trialOutput?->trial_quantity ?? 0),
                'settled' => $trialOutput?->trial_settled_at !== null,
                'captured_at' => $trialOutput?->recorded_at?->toISOString(),
                'output_id' => $trialOutput?->hash_id,
            ] : null,
            'result_version' => (int) $this->result_version,
            'source_quantity' => $this->source_quantity,
            'source_uom_code' => $this->source_uom_code,
            'sampling_basis' => $this->sampling_basis,
            'capture_method' => $this->capture_method ?? 'measurement',
            'count_only_incoming' => $this->isCountOnlyIncoming(),
            'can_manage' => (bool) $request->user()?->hasPermission('quality.inspections.manage'),
            'replaces_inspection_id' => $this->replaces_inspection_id ? app('hashids')->encode((int) $this->replaces_inspection_id) : null,
            'can_replace_checklist' => (bool) config('inspection.checklist_capture_enabled')
                && ! $trialOutput && ! $this->counted_capture
                && ! $this->usesChecklistCapture()
                && in_array($this->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)
                && (int) $this->accepted_quantity === 0 && $this->ncr === null
                && $this->relationLoaded('measurements')
                && ! $this->measurements->contains(fn ($row) => $row->item_result === 'failed' || $row->is_pass === false || $row->evaluate() === false),
            'id' => $this->hash_id,
            'return_request_item_id' => $this->return_request_item_id ? HashId::encode((int) $this->return_request_item_id) : null,
            'inspection_number' => $this->inspection_number,
            'stage' => $this->stage instanceof \BackedEnum ? $this->stage->value : $this->stage,
            'stage_label' => InspectionStage::tryFrom((string) ($this->stage instanceof \BackedEnum ? $this->stage->value : $this->stage))?->label(),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label' => $this->status instanceof \BackedEnum && method_exists($this->status, 'label')
                ? $this->status->label()
                : Str::headline((string) $this->status),
            // Mirrors InspectionService::review()'s guards so the checker actions
            // are hidden from the maker instead of failing with a 403 on click.
            'can_review' => ($this->status instanceof \BackedEnum ? $this->status->value : $this->status) === InspectionStatus::AwaitingReview->value
                && (bool) $request->user()?->hasPermission('quality.inspections.review')
                && $this->inspector_id !== null
                && (int) $this->inspector_id !== (int) $request->user()?->id
                && ! $this->hasResultAuthor($request->user()),
            'proposed_result' => $this->proposed_result instanceof \BackedEnum ? $this->proposed_result->value : $this->proposed_result,
            'recorded_result' => $this->recorded_result instanceof \BackedEnum ? $this->recorded_result->value : $this->recorded_result,
            'problem_description' => $this->problem_description,
            'affected_quantity' => $this->affected_quantity,
            'ncr' => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id' => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
                'status' => $this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status,
                'status_label' => $this->ncr->status instanceof \BackedEnum && method_exists($this->ncr->status, 'label') ? $this->ncr->status->label() : Str::headline($this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : (string) $this->ncr->status),
                'disposition' => $this->ncr->disposition instanceof \BackedEnum ? $this->ncr->disposition->value : $this->ncr->disposition,
                'disposition_label' => $this->ncr->disposition instanceof \BackedEnum && method_exists($this->ncr->disposition, 'label') ? $this->ncr->disposition->label() : null,
                'href' => $request->user()?->hasPermission('quality.ncr.view') ? "/quality/ncrs/{$this->ncr->hash_id}" : null,
            ] : null),
            'incoming_acceptance' => $this->whenLoaded('grnItem', function () {
                $line = $this->grnItem;
                if ($this->stage !== InspectionStage::Incoming || ! $line || ! $line->grn) {
                    return null;
                }
                $factor = (string) ($line->received_uom_conversion_factor ?? '1');
                $originalUnit = $line->received_uom_code !== null && bccomp($factor, '0', 6) > 0;

                return [
                    'grn_item_id' => $line->hash_id,
                    'grn_id' => $line->grn->hash_id,
                    'grn_number' => $line->grn->grn_number,
                    'quantity_received' => $originalUnit && $line->quantity_received_in_uom !== null ? (string) $line->quantity_received_in_uom : (string) $line->quantity_received,
                    'quantity_accepted' => $originalUnit ? bcdiv((string) $line->quantity_accepted, $factor, 6) : (string) $line->quantity_accepted,
                    'unit' => $originalUnit ? $line->received_uom_code : $line->item?->unit_of_measure,
                    'status' => $line->grn->status instanceof \BackedEnum ? $line->grn->status->value : $line->grn->status,
                    'status_label' => $line->grn->status instanceof \BackedEnum && method_exists($line->grn->status, 'label') ? $line->grn->status->label() : null,
                ];
            }),
            'sorting_approved_at' => optional($this->ncr?->sorting_approved_at)?->toISOString(),
            'inspected_quantity' => $this->inspected_quantity,
            'critical_defect' => $this->critical_defect,
            'defects_removed_confirmed' => $this->defects_removed_confirmed,
            'reviewed_at' => optional($this->reviewed_at)?->toISOString(),
            'coc_unavailable_reason' => $this->resource->getAttribute('coc_unavailable_reason'),
            'review_remarks' => $this->review_remarks,
            'entity_type' => $this->entity_type instanceof \BackedEnum ? $this->entity_type->value : $this->entity_type,
            'entity_hash_id' => $this->entity_id ? app('hashids')->encode($this->entity_id) : null,
            'entity_context' => $this->whenLoaded('entityRecord', function () use ($request) {
                $entity = $this->getRelation('entityRecord');
                if (! $entity) {
                    return null;
                }

                $type = $this->entity_type instanceof \BackedEnum ? $this->entity_type->value : (string) $this->entity_type;
                $permission = match ($type) {
                    'grn' => 'inventory.view',
                    'work_order' => 'production.work_orders.view',
                    'delivery' => 'supply_chain.deliveries.view',
                    'return_request' => 'return_management.view',
                    default => null,
                };
                $href = match ($type) {
                    'grn' => "/inventory/grn/{$entity->hash_id}",
                    'work_order' => "/production/work-orders/{$entity->hash_id}",
                    'delivery' => "/supply-chain/deliveries/{$entity->hash_id}",
                    'return_request' => "/return-management/{$entity->hash_id}",
                    default => null,
                };
                $reference = match ($type) {
                    'grn' => $entity->grn_number,
                    'work_order' => $entity->wo_number,
                    'delivery' => $entity->delivery_number,
                    'return_request' => $entity->rma_number,
                    default => null,
                };
                $status = $entity->status instanceof \BackedEnum ? $entity->status->value : (string) $entity->status;

                return [
                    'id' => $entity->hash_id,
                    'type' => $type,
                    'reference' => $reference,
                    'status' => $status,
                    'status_label' => $entity->status instanceof \BackedEnum && method_exists($entity->status, 'label')
                        ? $entity->status->label()
                        : ($status !== '' ? Str::headline($status) : null),
                    'href' => $permission && $request->user()?->hasPermission($permission) ? $href : null,
                ];
            }),
            'batch_quantity' => (int) $this->batch_quantity,
            'accepted_quantity' => (int) $this->accepted_quantity,
            'work_order_output' => $this->whenLoaded('workOrderOutput', fn () => $this->workOrderOutput ? [
                'id' => $this->workOrderOutput->hash_id,
                'batch_code' => $this->workOrderOutput->batch_code,
                'good_count' => (int) $this->workOrderOutput->good_count,
                'work_order' => $this->workOrderOutput->relationLoaded('workOrder') && $this->workOrderOutput->workOrder ? [
                    'id' => $this->workOrderOutput->workOrder->hash_id,
                    'wo_number' => $this->workOrderOutput->workOrder->wo_number,
                ] : null,
            ] : null),
            'inspection_mode' => $this->inspection_mode instanceof \BackedEnum ? $this->inspection_mode->value : $this->inspection_mode,
            'sample_size' => (int) $this->sample_size,
            'aql_code' => $this->aql_code,
            'accept_count' => (int) $this->accept_count,
            'reject_count' => (int) $this->reject_count,
            'defect_count' => (int) $this->defect_count,
            'sample_defect_count' => $this->sample_defect_count !== null ? (int) $this->sample_defect_count : null,
            'started_at' => optional($this->started_at)?->toISOString(),
            'completed_at' => optional($this->completed_at)?->toISOString(),
            'notes' => $this->notes,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name' => $this->product->name,
            ] : null),
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ] : null),
            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector ? [
                'id' => $this->inspector->hash_id,
                'name' => $this->inspector->name,
            ] : null),
            'result_authors' => $this->whenLoaded('resultAuthors', fn () => $this->resultAuthors
                ->map(fn ($author) => [
                    'id' => $author->user?->hash_id,
                    'name' => $author->user?->name,
                ])
                ->filter(fn (array $author) => $author['id'] !== null)
                ->values()
                ->all()),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->hash_id,
                'name' => $this->reviewer->name,
            ] : null),
            'spec' => $this->whenLoaded('spec', fn () => $this->spec ? [
                'id' => $this->spec->hash_id,
                'version' => $this->relationLoaded('specRevision') && $this->specRevision
                    ? (int) $this->specRevision->version
                    : null,
                'is_active' => (bool) $this->spec->is_active,
                'revision_id' => $this->relationLoaded('specRevision') && $this->specRevision
                    ? $this->specRevision->hash_id
                    : null,
                'revision_status' => $this->relationLoaded('specRevision') && $this->specRevision
                    ? 'pinned'
                    : 'legacy_unknown',
                'revision_notes' => $this->relationLoaded('specRevision') && $this->specRevision
                    ? $this->specRevision->notes
                    : null,
            ] : null),
            'spec_revision' => $this->whenLoaded('specRevision', fn () => $this->specRevision
                ? (new InspectionSpecRevisionResource($this->specRevision))->toArray($request)
                : null),
            'quality_plan' => $this->whenLoaded('qualityPlan', fn () => $this->qualityPlan ? [
                'id' => $this->qualityPlan->hash_id,
                'version' => (int) $this->qualityPlan->version,
                'sampling_method' => $this->qualityPlan->sampling_method,
            ] : null),
            'calibration_record' => $this->whenLoaded('calibrationRecord', fn () => $this->calibrationRecord ? [
                'id' => $this->calibrationRecord->hash_id,
                'equipment_code' => $this->calibrationRecord->equipment_code,
                'name' => $this->calibrationRecord->name,
                'status' => $this->calibrationRecord->status instanceof \BackedEnum
                    ? $this->calibrationRecord->status->value
                    : $this->calibrationRecord->status,
            ] : null),
            'measurements' => $this->whenLoaded('measurements', fn () => InspectionMeasurementResource::collection($this->measurements)->resolve()
            ),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
