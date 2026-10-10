<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NcrResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'historical_held' => (bool) ($this->inspection?->work_order_output_id && $this->inspection?->workOrderOutput?->production_started_at),
            'startup_trial' => (bool) $this->inspection?->startup_trial_required,
            'process_recovery_quantity' => $this->inspection?->stage === InspectionStage::InProcess ? $this->inspection->processRecoveryQuantity() : null,
            'recovery_batch_quantity' => $this->recovery_batch_quantity !== null ? (int) $this->recovery_batch_quantity : null,
            'id' => $this->hash_id,
            'ncr_number' => $this->ncr_number,
            'full_piece_inspection' => $this->relationLoaded('inspection') && ($this->inspection?->counted_capture['workflow'] ?? null) === 'finish_first_v1',
            'source' => $this->source instanceof \BackedEnum ? $this->source->value : $this->source,
            'source_label' => Str::headline((string) ($this->source instanceof \BackedEnum ? $this->source->value : $this->source)),
            'severity' => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'severity_label' => Str::headline((string) ($this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity)),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label' => Str::headline((string) ($this->status instanceof \BackedEnum ? $this->status->value : $this->status)),
            'disposition' => $this->disposition instanceof \BackedEnum ? $this->disposition->value : $this->disposition,
            'disposition_label' => NcrDisposition::tryFrom((string) ($this->disposition instanceof \BackedEnum ? $this->disposition->value : $this->disposition))?->label(),
            'defect_description' => $this->defect_description,
            'affected_quantity' => (int) $this->affected_quantity,
            'late_incoming' => (bool) $this->is_late_incoming,
            'late_hold_quantity' => $this->late_hold_quantity,
            'concession_approval_reference' => $this->concession_approval_reference,
            'material_review_records' => MaterialReviewRecord::where('ncr_id', $this->id)->get()->map(fn ($record) => [
                'id' => $record->hash_id, 'mrb_number' => $record->mrb_number,
                'status' => $record->status->value, 'quantity' => (string) $record->quantity,
            ]),
            'is_inline_rework' => $this->relationLoaded('inspection') && $this->inspection?->entity_type?->value === 'work_order'
                && $this->rework_work_order_id !== null && (int) $this->rework_work_order_id === (int) $this->inspection?->entity_id,
            'confirmed_rework_quantity' => (int) $this->confirmed_rework_quantity,
            'rework_started_at' => optional($this->rework_started_at)->toIso8601String(),
            'rework_started_by' => $this->whenLoaded('reworkStartedBy', fn () => $this->reworkStartedBy ? [
                'id' => $this->reworkStartedBy->hash_id,
                'name' => $this->reworkStartedBy->name,
            ] : null),
            'rework_finished_at' => optional($this->rework_finished_at)->toIso8601String(),
            'rework_finished_by' => $this->whenLoaded('reworkFinishedBy', fn () => $this->reworkFinishedBy ? [
                'id' => $this->reworkFinishedBy->hash_id,
                'name' => $this->reworkFinishedBy->name,
            ] : null),
            'is_auto_generated' => (bool) $this->is_auto_generated,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'closed_at' => optional($this->closed_at)?->toISOString(),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name' => $this->product->name,
            ] : null),
            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id' => $this->inspection->hash_id,
                'inspection_number' => $this->inspection->inspection_number,
                'batch_quantity' => $this->inspection->batch_quantity,
                'source_quantity' => $this->inspection->source_quantity,
                'source_uom_code' => $this->inspection->source_uom_code,
                'sampling_basis' => $this->inspection->sampling_basis,
                'accepted_quantity' => $this->inspection->accepted_quantity,
                'sample_defect_count' => $this->inspection->sample_defect_count,
                'inspected_quantity' => $this->inspection->inspected_quantity,
                'inspector' => $this->inspection->relationLoaded('inspector') && $this->inspection->inspector ? [
                    'id' => $this->inspection->inspector->hash_id,
                    'name' => $this->inspection->inspector->name,
                ] : null,
                'stage' => $this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage,
                'status' => $this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status,
                'stage_label' => InspectionStage::tryFrom((string) ($this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage))?->label() ?? (string) ($this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage),
                'status_label' => InspectionStatus::tryFrom((string) ($this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status))?->label() ?? (string) ($this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status),
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->hash_id,
                'name' => $this->assignee->name,
            ] : null),
            'closer' => $this->whenLoaded('closer', fn () => $this->closer ? [
                'id' => $this->closer->hash_id,
                'name' => $this->closer->name,
            ] : null),
            'sorting_required_quantity' => $this->inspection?->stage === InspectionStage::InProcess
                ? $this->inspection->processRecoveryQuantity()
                : ($this->inspection?->grn_item_id
                ? GrnItem::query()->whereKey($this->inspection->grn_item_id)->value('quantity_received')
                : ($this->recovery_batch_quantity ?? $this->inspection?->batch_quantity)),
            'sorting_inspected_quantity' => $this->sorting_inspected_quantity,
            'sorting_evidence_reference' => $this->sorting_evidence_reference,
            'sorting_approved_at' => optional($this->sorting_approved_at)?->toISOString(),
            'is_process_recovery' => $this->inspection?->stage === InspectionStage::InProcess && $this->inspection?->status === InspectionStatus::Failed && $this->mrb_decided_at === null,
            'is_outgoing_recovery' => $this->inspection?->stage === InspectionStage::Outgoing
                && in_array($this->inspection?->status, [InspectionStatus::Failed, InspectionStatus::Passed], true)
                && $this->inspection?->work_order_output_id !== null
                && $this->mrb_decided_at === null,
            'mrb_accepted_quantity' => $this->mrb_accepted_quantity,
            'mrb_decided_at' => optional($this->mrb_decided_at)?->toISOString(),
            'mrb_decider' => $this->whenLoaded('mrbDecider', fn () => $this->mrbDecider ? [
                'id' => $this->mrbDecider->hash_id,
                'name' => $this->mrbDecider->name,
            ] : null),
            // Cheap pre-filter, then the service's own rule (pending GRN + MRB
            // setting) so the SPA never offers an MRB input the API refuses.
            'is_incoming_mrb' => $this->inspection
                && $this->inspection->stage?->value === 'incoming'
                && in_array($this->status?->value, ['open', 'in_progress'], true)
                && ! $this->disposition
                && app(NcrService::class)->awaitsMrbDecision($this->resource),
            'replacement_work_order' => $this->whenLoaded('replacementWorkOrder', fn () => $this->replacementWorkOrder ? [
                'id' => $this->replacementWorkOrder->hash_id,
                'wo_number' => $this->replacementWorkOrder->wo_number,
                'status' => $this->replacementWorkOrder->status instanceof \BackedEnum ? $this->replacementWorkOrder->status->value : $this->replacementWorkOrder->status,
                'status_label' => WorkOrderStatus::tryFrom((string) ($this->replacementWorkOrder->status instanceof \BackedEnum ? $this->replacementWorkOrder->status->value : $this->replacementWorkOrder->status))?->label() ?? (string) ($this->replacementWorkOrder->status instanceof \BackedEnum ? $this->replacementWorkOrder->status->value : $this->replacementWorkOrder->status),
                'quantity_target' => (int) $this->replacementWorkOrder->quantity_target,
            ] : null),
            'rework_work_order' => $this->whenLoaded('reworkWorkOrder', fn () => $this->reworkWorkOrder ? [
                'id' => $this->reworkWorkOrder->hash_id,
                'wo_number' => $this->reworkWorkOrder->wo_number,
                'status' => $this->reworkWorkOrder->status instanceof \BackedEnum ? $this->reworkWorkOrder->status->value : $this->reworkWorkOrder->status,
                'status_label' => WorkOrderStatus::tryFrom((string) ($this->reworkWorkOrder->status instanceof \BackedEnum ? $this->reworkWorkOrder->status->value : $this->reworkWorkOrder->status))?->label() ?? (string) ($this->reworkWorkOrder->status instanceof \BackedEnum ? $this->reworkWorkOrder->status->value : $this->reworkWorkOrder->status),
                'quantity_target' => (int) $this->reworkWorkOrder->quantity_target,
            ] : null),
            'actions' => $this->whenLoaded('actions', fn () => NcrActionResource::collection($this->actions)->resolve()),
            // CAPA effectiveness rollup.
            'effectiveness_status' => $this->effectiveness_status instanceof \BackedEnum ? $this->effectiveness_status->value : $this->effectiveness_status,
            'effectiveness_status_label' => $this->effectiveness_status instanceof EffectivenessStatus ? $this->effectiveness_status->label() : null,
            'effectiveness_closed_at' => optional($this->effectiveness_closed_at)?->toISOString(),
            'recurrence_of_ncr' => $this->whenLoaded('recurrenceOf', fn () => $this->recurrenceOf ? [
                'id' => $this->recurrenceOf->hash_id,
                'ncr_number' => $this->recurrenceOf->ncr_number,
            ] : null),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
