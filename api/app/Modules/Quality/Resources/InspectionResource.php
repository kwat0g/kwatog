<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Resources\InspectionSpecRevisionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'inspection_number' => $this->inspection_number,
            'stage' => $this->stage instanceof \BackedEnum ? $this->stage->value : $this->stage,
            'stage_label' => InspectionStage::tryFrom((string) ($this->stage instanceof \BackedEnum ? $this->stage->value : $this->stage))?->label(),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label' => Str::headline((string) ($this->status instanceof \BackedEnum ? $this->status->value : $this->status)),
            'entity_type' => $this->entity_type instanceof \BackedEnum ? $this->entity_type->value : $this->entity_type,
            'entity_hash_id' => $this->entity_id ? app('hashids')->encode($this->entity_id) : null,
            'entity_context' => $this->whenLoaded('entityRecord', function () use ($request) {
                $entity = $this->getRelation('entityRecord');
                if (! $entity) return null;

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
                    'status_label' => $status !== '' ? Str::headline($status) : null,
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
            'sample_size' => (int) $this->sample_size,
            'aql_code' => $this->aql_code,
            'accept_count' => (int) $this->accept_count,
            'reject_count' => (int) $this->reject_count,
            'defect_count' => (int) $this->defect_count,
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
            ] : null),
            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector ? [
                'id' => $this->inspector->hash_id,
                'name' => $this->inspector->name,
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
            'measurements' => $this->whenLoaded('measurements', fn () => InspectionMeasurementResource::collection($this->measurements)->resolve()
            ),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
