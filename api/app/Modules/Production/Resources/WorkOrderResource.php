<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ActivityEvent;
use App\Common\Support\Money;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderMaterialHandoverService;
use App\Modules\Production\Services\WorkOrderMaterialUsageService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $materialUsage = $this->resource->relationLoaded('materials')
            ? app(WorkOrderMaterialUsageService::class)->groups($this->resource)
            : null;

        $scheduleStatus = $this->resource->relationLoaded('currentProductionSchedule') ? $this->scheduleStatus() : null;
        $handover = app(WorkOrderMaterialHandoverService::class)->summary($this->resource);
        $quality = $this->resource->relationLoaded('outputs') ? $this->qualitySummary() : null;

        return [
            'id' => $this->hash_id,
            'wo_number' => $this->wo_number,
            'production_purpose' => $this->production_purpose,
            'stock_creation_request_key' => $this->stock_creation_request_key,
            'qc_workflow' => $this->resource->getAttribute('qc_workflow'),
            'produced_quantity' => (int) $this->quantity_produced,
            'qc_accepted_quantity' => $quality['qc_accepted_quantity'] ?? null,
            'qc_defective_quantity' => $quality['qc_defective_quantity'] ?? null,
            'awaiting_qc_quantity' => $quality['awaiting_qc_quantity'] ?? null,
            'quality_state' => $quality['quality_state'] ?? null,
            'startup_trial' => app(WorkOrderOutputService::class)->trialState($this->resource),
            'execution_history' => $this->whenLoaded('schedules', fn () => ActivityEvent::where('subject_type', $this->resource->getMorphClass())
                ->where('subject_id', $this->id)->where('type', 'production.schedule')
                ->whereIn('action', ['reviewed', 'execution_deviation', 'run_extended'])->with('actor:id,name')->latest('id')->limit(20)->get()
                ->map(fn ($event) => ['id' => $event->hash_id, 'summary' => $event->summary,
                    'created_at' => $event->created_at->toIso8601String(), 'actor' => $event->actor ? ['name' => $event->actor->name] : null,
                    'detail' => array_intersect_key($event->detail ?? [], array_flip(['mode', 'approved_start', 'approved_end',
                        'scheduled_start', 'scheduled_end', 'late_start_authorized', 'reason', 'late_production_authorized', 'late_production_reason']))])->values()->all()),
            'staffing_warning' => $this->whenLoaded('schedules', fn () => in_array($this->status->value, ['in_progress', 'paused'], true)
                ? app(WorkOrderAssignmentService::class)->staffingIssues($this->resource) : []),
            'production_released_at' => $this->production_released_at?->toIso8601String(),
            'minimum_workers' => $this->minimum_workers,
            'maximum_workers' => $this->maximum_workers,
            'work_order_class' => $this->work_order_class ?: 'standard',
            'exception_reason' => $this->exception_reason,
            'exception_authorized_by' => $this->hashId($this->exception_authorized_by),
            'material_plan_source' => $this->material_plan_source,
            'bom_id' => $this->hashId($this->bom_id),
            'bom_version' => $this->bom_version,
            'unit_standard_cost' => $this->unit_standard_cost,
            'cost_source' => $this->cost_source,
            'cost_snapshot_at' => optional($this->cost_snapshot_at)->toIso8601String(),
            // ADV3 — IATF 16949 traceability fields.
            'batch_number' => $this->batch_number,
            'material_lot_references' => $this->material_lot_references ?? [],
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name' => $this->product->name,
            ]),
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'sales_order_line' => $this->whenLoaded('salesOrderItem', fn () => $this->salesOrderItem ? [
                'requested_delivery_date' => optional($this->salesOrderItem->requested_delivery_date ?? $this->salesOrderItem->delivery_date)->toDateString(),
                'delivery_date' => optional($this->salesOrderItem->delivery_date)->toDateString(),
            ] : null),
            'machine' => $this->whenLoaded('machine', fn () => $this->machine ? [
                'id' => $this->machine->hash_id,
                'machine_code' => $this->machine->machine_code,
                'name' => $this->machine->name,
            ] : null),
            'mold' => $this->whenLoaded('mold', fn () => $this->mold ? [
                'id' => $this->mold->hash_id,
                'mold_code' => $this->mold->mold_code,
                'name' => $this->mold->name,
            ] : null),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->hash_id,
                'wo_number' => $this->parent->wo_number,
            ] : null),
            'children' => $this->whenLoaded('children', fn () => $this->children->map(fn ($child) => [
                'id' => $child->hash_id,
                'wo_number' => $child->wo_number,
                'product_id' => $this->hashId($child->product_id),
                'quantity_target' => (int) $child->quantity_target,
                'quantity_good' => (int) $child->quantity_good,
                'status' => (string) $child->status?->value,
            ])->values()),
            'warehouse_handover_pending' => $handover['pending'],
            'warehouse_handover' => $handover,
            'production_readiness' => $this->whenLoaded('children', fn () => app(WorkOrderService::class)->productionReadiness($this->resource)
            ),
            'short_completion_reason' => $this->short_completion_reason,
            'quality_summary' => $this->whenLoaded('outputs', fn () => $quality),
            'quantity_target' => (int) $this->quantity_target,
            'quantity_produced' => (int) $this->quantity_produced,
            'quantity_good' => (int) $this->quantity_good,
            'quantity_rejected' => (int) $this->quantity_rejected,
            'progress_percentage' => (float) $this->progress_percentage,
            'scrap_rate' => (string) $this->scrap_rate,
            'planned_start' => optional($this->planned_start)->toIso8601String(),
            'planned_end' => optional($this->planned_end)->toIso8601String(),
            'preferred_production_date' => $this->preferred_production_date?->toDateString(),
            'mrp_generated' => $this->mrp_plan_id !== null,
            'schedule_status' => $this->whenLoaded('currentProductionSchedule', fn () => $scheduleStatus),
            'schedule_warning' => $this->whenLoaded('currentProductionSchedule', fn () => in_array($scheduleStatus['kind'] ?? null,
                ['proposal_expired', 'start_overdue', 'schedule_passed'], true) ? $scheduleStatus : null),
            'actual_start' => optional($this->actual_start)->toIso8601String(),
            'actual_end' => optional($this->actual_end)->toIso8601String(),
            'status' => (string) $this->status?->value,
            'status_label' => $this->status?->label(),
            'rework_status' => $this->whenLoaded('activeReworks', function (): ?array {
                if (in_array($this->status?->value, ['closed', 'cancelled'], true) || $this->activeReworks->isEmpty()) {
                    return null;
                }
                $pending = $this->activeReworks->whereNull('rework_finished_at');

                return ['state' => $pending->isEmpty() ? 'awaiting_qc'
                    : ($pending->contains(fn ($ncr) => $ncr->rework_started_at === null) ? 'required' : 'in_progress'),
                    'quantity' => (int) ($pending->isEmpty() ? $this->activeReworks : $pending)->sum('confirmed_rework_quantity')];
            }),
            'process_quality_blocker' => $this->when($this->relationLoaded('inspections'), function (): ?string {
                try {
                    $this->resource->assertProcessQualityReleased();

                    return null;
                } catch (BusinessRuleException $exception) {
                    return $exception->getMessage();
                }
            }),
            'production_completion_blocker' => $this->when($this->relationLoaded('outputs'), fn () => app(WorkOrderService::class)->productionCompletionBlocker($this->resource)),
            'quality_close_blocker' => $this->when($this->relationLoaded('outputs'), fn () => app(WorkOrderService::class)->qualityCloseBlocker($this->resource)),
            'rework' => $this->when($this->relationLoaded('outputs'), function (): array {
                return NonConformanceReport::query()->where('rework_work_order_id', $this->id)
                    ->whereHas('inspection', fn ($q) => $q->where('entity_type', 'work_order')->where('entity_id', $this->id))
                    ->with(['inspection', 'reworkStartedBy:id,name', 'reworkFinishedBy:id,name'])
                    ->orderBy('id')->get()->map(function ($ncr): array {
                        $outputs = $this->outputs->where('rework_ncr_id', $ncr->id);
                        $processed = $outputs->sum(fn ($output) => $output->good_count + $output->reject_count);

                        return [
                            'id' => $ncr->hash_id, 'ncr_number' => $ncr->ncr_number,
                            'defect_description' => $ncr->defect_description, 'status' => $ncr->status->value,
                            'quantity' => (int) $ncr->confirmed_rework_quantity, 'recorded_quantity' => (int) $processed,
                            'remaining_quantity' => max(0, (int) $ncr->confirmed_rework_quantity - (int) $processed),
                            'started_at' => optional($ncr->rework_started_at)->toIso8601String(),
                            'started_by' => $ncr->reworkStartedBy ? [
                                'id' => $ncr->reworkStartedBy->hash_id,
                                'name' => $ncr->reworkStartedBy->name,
                            ] : null,
                            'finished_at' => optional($ncr->rework_finished_at)->toIso8601String(),
                            'finished_by' => $ncr->reworkFinishedBy ? [
                                'id' => $ncr->reworkFinishedBy->hash_id,
                                'name' => $ncr->reworkFinishedBy->name,
                            ] : null,
                            'inspections' => $outputs->flatMap(fn ($output) => $output->inspections->map(fn ($inspection) => [
                                'id' => $inspection->hash_id, 'inspection_number' => $inspection->inspection_number, 'status' => $inspection->status->value,
                            ]))->values()->all(),
                        ];
                    })->all();
            }),
            'next_statuses' => array_map(
                static fn (string $next): array => [
                    'value' => $next,
                    'label' => WorkOrderStatus::tryFrom($next)?->label() ?? $next,
                ],
                WorkOrderService::allowedTransitions()[$this->status?->value ?? ''] ?? [],
            ),
            'pause_reason' => $this->pause_reason,
            'priority' => (int) $this->priority,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id, 'name' => $this->creator->name,
            ] : null),
            'materials' => $materialUsage === null ? $this->whenLoaded('materials') : array_map(static fn (array $usage) => [
                'id' => $usage['id'],
                'item' => $usage['item'],
                'bom_quantity' => (string) $usage['required_quantity'],
                'standard_unit_cost' => (string) $usage['standard_unit_cost'],
                'standard_cost' => (string) $usage['standard_cost'],
                'actual_quantity_issued' => (string) $usage['actual_quantity_issued'],
                'actual_cost' => (string) $usage['actual_cost'],
                'cost_variance' => (string) $usage['cost_variance'],
                'variance' => (string) $usage['variance'],
                'auto_quantity_issued' => (string) $usage['auto_quantity_issued'],
                'auto_actual_cost' => (string) $usage['auto_actual_cost'],
                'auto_gross_quantity_issued' => (string) $usage['auto_gross_quantity_issued'],
                'auto_gross_actual_cost' => (string) $usage['auto_gross_actual_cost'],
                'auto_returned_quantity' => (string) $usage['auto_returned_quantity'],
                'auto_returned_cost' => (string) $usage['auto_returned_cost'],
                'manual_quantity_issued' => (string) $usage['manual_quantity_issued'],
                'manual_actual_cost' => (string) $usage['manual_actual_cost'],
                'manual_gross_quantity_issued' => (string) $usage['manual_gross_quantity_issued'],
                'manual_gross_actual_cost' => (string) $usage['manual_gross_actual_cost'],
                'manual_returned_quantity' => (string) $usage['manual_returned_quantity'],
                'manual_returned_cost' => (string) $usage['manual_returned_cost'],
                'returned_quantity' => (string) $usage['returned_quantity'],
                'returned_cost' => (string) $usage['returned_cost'],
            ], $materialUsage),
            'material_cost_summary' => $materialUsage === null ? $this->whenLoaded('materials') : (function () use ($materialUsage): array {
                $standard = '0.00';
                $actual = '0.00';
                foreach ($materialUsage as $material) {
                    $standard = Money::add($standard, (string) $material['standard_cost']);
                    $actual = Money::add($actual, (string) $material['actual_cost']);
                }

                return [
                    'standard_cost' => $standard,
                    'actual_cost' => $actual,
                    'cost_variance' => Money::sub($actual, $standard),
                ];
            })(),
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs->map(fn ($o) => [
                'id' => $o->hash_id,
                'recorded_at' => optional($o->recorded_at)->toIso8601String(),
                'good_count' => (int) $o->good_count,
                'held_quantity' => $o->held_quantity, 'production_started_at' => $o->production_started_at?->toIso8601String(),
                'production_finished_at' => $o->production_finished_at?->toIso8601String(),
                'trial_quantity' => $o->trial_quantity, 'trial_inspection_id' => $this->hashId($o->trial_inspection_id),
                'trial_settled_at' => $o->trial_settled_at?->toIso8601String(), 'physical_count' => $o->physical_count,
                'reject_count' => (int) $o->reject_count,
                'shift' => $o->shift,
                'batch_code' => $o->batch_code,
                'is_rework' => $o->rework_ncr_id !== null,
                'remarks' => $o->remarks,
                'crew_snapshot' => $o->crew_snapshot,
                'recording_reason' => $o->recording_reason,
                'material_handover_exception' => $o->material_handover_exception,
                'material_lineage' => $this->normalizeMaterialLineage($o->material_lineage),
                'production_receipt_handoff' => [
                    'status' => $o->production_receipt_handoff_status instanceof ProductionReceiptHandoffStatus
                        ? $o->production_receipt_handoff_status->value
                        : (string) $o->production_receipt_handoff_status,
                    'status_label' => ($handoff = $o->production_receipt_handoff_status instanceof ProductionReceiptHandoffStatus
                        ? $o->production_receipt_handoff_status
                        : ProductionReceiptHandoffStatus::tryFrom((string) $o->production_receipt_handoff_status))?->label(),
                    'message' => $o->production_receipt_handoff_message,
                    'at' => optional($o->production_receipt_handoff_at)->toIso8601String(),
                ],
                'general_ledger_handoff' => $o->relationLoaded('productionReceiptMovement') && $o->productionReceiptMovement ? [
                    'status' => $o->productionReceiptMovement->gl_handoff_status instanceof MovementGlHandoffStatus
                        ? $o->productionReceiptMovement->gl_handoff_status->value
                        : (string) $o->productionReceiptMovement->gl_handoff_status,
                    'status_label' => ($glHandoff = $o->productionReceiptMovement->gl_handoff_status instanceof MovementGlHandoffStatus
                        ? $o->productionReceiptMovement->gl_handoff_status
                        : MovementGlHandoffStatus::tryFrom((string) $o->productionReceiptMovement->gl_handoff_status))?->label(),
                    'message' => $o->productionReceiptMovement->gl_handoff_message,
                    'at' => optional($o->productionReceiptMovement->gl_handoff_at)->toIso8601String(),
                    'movement_id' => $o->productionReceiptMovement->hash_id,
                ] : null,
                'recorder' => $o->relationLoaded('recorder') && $o->recorder ? [
                    'id' => $o->recorder->hash_id, 'name' => $o->recorder->name,
                ] : null,
                'defects' => $o->relationLoaded('defects') ? $o->defects->map(fn ($d) => [
                    'id' => $d->hash_id,
                    'count' => (int) $d->count,
                    'defect_type' => $d->relationLoaded('defectType') && $d->defectType ? [
                        'id' => $d->defectType->hash_id,
                        'code' => $d->defectType->code,
                        'name' => $d->defectType->name,
                    ] : null,
                ]) : [],
            ])
            ),
            'inspections' => $this->whenLoaded('inspections', fn () => $this->inspections->map(fn ($inspection) => [
                'id' => $inspection->hash_id,
                'inspection_number' => $inspection->inspection_number,
                'stage' => (string) ($inspection->stage?->value ?? $inspection->stage),
                'status' => (string) ($inspection->status?->value ?? $inspection->status),
                'completed_at' => optional($inspection->completed_at)->toIso8601String(),
            ])->values()
            ),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }

    private function scheduleStatus(): ?array
    {
        $slot = $this->currentProductionSchedule;
        if (! $slot || in_array($this->status, [WorkOrderStatus::Completed, WorkOrderStatus::Closed, WorkOrderStatus::Cancelled], true)) {
            return null;
        }
        $now = now();
        if ($this->status === WorkOrderStatus::Planned) {
            $expired = $slot->scheduled_end->lte($now);
            $kind = $expired ? 'proposal_expired' : 'awaiting_confirmation';
            $message = $expired ? 'Proposal passed. Open Confirm to find a new slot.'
                : 'Awaiting confirmation. Open Confirm to review this proposal.';
        } elseif ($slot->scheduled_end->lte($now)) {
            $kind = 'schedule_passed';
            $message = match ($this->status) {
                WorkOrderStatus::Confirmed => 'Schedule passed. Click Start to check a late start or reschedule.',
                WorkOrderStatus::Paused => 'Schedule passed. Click Resume to check readiness or reschedule.',
                WorkOrderStatus::InProgress => 'Schedule passed. Review remaining production time.',
                default => null,
            };
        } elseif ($this->status === WorkOrderStatus::Confirmed && $slot->scheduled_start->lte($now)) {
            $late = $now->gt($slot->scheduled_start->copy()->addMinutes(ProductionResourceService::START_GRACE_MINUTES));
            $kind = $late ? 'start_overdue' : 'start_available';
            $message = $late ? 'Start overdue. Click Start to check readiness.'
                : 'Start available. Click Start to check readiness.';
        } else {
            return null;
        }

        return $message === null ? null : [
            'kind' => $kind,
            'message' => $message,
            'scheduled_start' => $slot->scheduled_start->toIso8601String(),
            'scheduled_end' => $slot->scheduled_end->toIso8601String(),
        ];
    }

    private function normalizeMaterialLineage(mixed $lineage): mixed
    {
        if (! is_array($lineage)) {
            return $lineage;
        }

        if (array_key_exists('authorized_by', $lineage)) {
            $lineage['authorized_by'] = $this->hashId($lineage['authorized_by']);
        }

        if (is_array($lineage['materials'] ?? null)) {
            $lineage['materials'] = array_map(function (array $material): array {
                if (array_key_exists('item_id', $material)) {
                    $material['item_id'] = $this->hashId($material['item_id']);
                }

                return $material;
            }, $lineage['materials']);
        }

        return $lineage;
    }

    private function hashId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_string($id) && ! ctype_digit($id)) {
            return $id;
        }

        return app('hashids')->encode((int) $id);
    }

    private function qualitySummary(): array
    {
        $inspections = $this->resource->inspections()->where('status', '!=', 'cancelled')->get();
        $defects = NonConformanceReport::query()->whereIn('inspection_id', $inspections->pluck('id'))->get();
        $approved = 0;
        $rejected = (int) $this->quantity_rejected;
        $defective = 0;
        $hasAwaitingReview = false;
        $hasEditableInspection = false;
        foreach ($this->outputs->where('good_count', '>', 0) as $output) {
            $qc = $output->inspections()->where('stage', 'outgoing')->where('status', '!=', 'cancelled')->latest('id')->first();
            if ($this->resource->usesFinishFirstQc() && $qc) {
                $hasAwaitingReview = $hasAwaitingReview || $qc->status?->value === 'awaiting_review';
                $hasEditableInspection = $hasEditableInspection || in_array($qc->status?->value, ['draft', 'in_progress'], true);
                if ($qc->status?->value === 'failed') {
                    $defective += min((int) $output->physical_count, (int) $qc->defect_count);
                }
            }
            if ($qc?->status?->value === 'failed') {
                $rejected += max(0, (int) $output->good_count - (int) $qc->accepted_quantity);
            }
            if ($qc?->canReleaseOutgoing()) {
                $approved += min((int) $output->good_count, (int) $qc->accepted_quantity);
            }
        }
        $recoveryIds = $defects->flatMap(fn ($ncr) => [$ncr->replacement_work_order_id, $ncr->rework_work_order_id])->filter()
            ->reject(fn ($id) => (int) $id === (int) $this->id)->unique();
        $recovery = WorkOrder::whereIn('id', $recoveryIds)->get();
        $finishFirstFields = [];
        if ($this->resource->usesFinishFirstQc()) {
            $awaiting = max(0, (int) $this->quantity_produced - $approved - $defective);
            $qualityState = $approved >= (int) $this->quantity_produced && $this->quantity_produced > 0
                ? 'released'
                : ($defective > 0 ? 'defects_held'
                    : ($hasAwaitingReview ? 'awaiting_review'
                        : ($hasEditableInspection ? 'checking' : 'awaiting_qc')));
            $finishFirstFields = [
                'produced_quantity' => (int) $this->quantity_produced,
                'qc_accepted_quantity' => $approved,
                'qc_defective_quantity' => min((int) $this->quantity_produced, $defective),
                'awaiting_qc_quantity' => $awaiting,
                'quality_state' => $qualityState,
            ];
        }

        return [
            ...$finishFirstFields,
            'approved_quantity' => $approved,
            'rejected_quantity' => min($rejected, max(0, (int) $this->quantity_produced - $approved)),
            'open_defects' => $defects->whereNotIn('status', [NcrStatus::Closed, NcrStatus::Cancelled])->count(),
            'missing_process_evidence' => ! $this->resource->usesFinishFirstQc() && $this->quantity_produced > 0 && ! $inspections->contains(fn ($qc) => $qc->stage->value === 'in_process' && ($qc->isMakerChecked() || (! $qc->requiresMakerChecker() && $qc->resultAuthors()->exists()))),
            'recovery_work_orders' => $recovery->map(fn ($wo) => [
                'id' => $wo->hash_id, 'wo_number' => $wo->wo_number, 'status' => $wo->status->value, 'quantity_target' => (int) $wo->quantity_target,
            ])->values()->all(),
            'replacement_pending' => $recovery->contains(fn ($wo) => $defects->contains(fn ($ncr) => (int) $ncr->replacement_work_order_id === (int) $wo->id) && ! in_array($wo->status->value, ['closed', 'cancelled'], true)),
        ];
    }
}
