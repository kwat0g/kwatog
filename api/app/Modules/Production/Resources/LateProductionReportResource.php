<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use App\Modules\Production\Services\LateProductionReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LateProductionReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $finishFirst = $this->workOrder?->usesFinishFirstQc() ?? false;

        return [
            'id' => $this->hash_id,
            'work_order_id' => $this->workOrder?->hash_id,
            'wo_number' => $this->workOrder?->wo_number,
            'work_order_status' => $this->workOrder?->status?->value,
            'crew_id' => $this->crew?->hash_id,
            'source_reference' => $this->source_reference,
            'production_started_at' => $this->production_started_at?->toIso8601String(),
            'production_finished_at' => $this->production_finished_at?->toIso8601String(),
            'produced_count' => $this->good_count + $this->reject_count,
            'good_count' => $finishFirst ? null : $this->good_count,
            'reject_count' => $finishFirst ? null : $this->reject_count,
            'quantity_scope' => 'additional_unreported_physical_pieces',
            'quantity_warning' => $finishFirst
                ? 'Produced count covers only physical pieces not already recorded in another output.'
                : 'Counts must cover only physical pieces not already recorded in another output or startup sample.',
            'defects' => $finishFirst ? [] : collect($this->defects ?? [])->map(static fn (array $defect): array => [
                'defect_type_id' => app('hashids')->encode((int) $defect['defect_type_id']),
                'count' => (int) $defect['count'],
            ])->values()->all(),
            'reason' => $this->reason,
            'crew_snapshot' => $this->crew_snapshot,
            'evidence_summary' => $this->evidence_summary,
            'status' => $this->status->value,
            'recorded_by' => app('hashids')->encode($this->recorded_by),
            'recorded_by_name' => $this->submitter?->name,
            'recorded_at' => $this->created_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by ? app('hashids')->encode($this->reviewed_by) : null,
            'reviewer_name' => $this->reviewer?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_reason' => $this->review_reason,
            'work_order_output_id' => $this->work_order_output_id ? app('hashids')->encode($this->work_order_output_id) : null,
            'output_summary' => $this->output ? [
                'physical_count' => (int) $this->output->physical_count,
                'produced_count' => (int) $this->output->physical_count,
                'good_count' => $finishFirst ? null : (int) $this->output->good_count,
                'reject_count' => $finishFirst ? null : (int) $this->output->reject_count,
                'held_quantity' => (int) $this->output->held_quantity,
            ] : null,
            'can_approve' => $actor ? app(LateProductionReportService::class)->canApprove($this->resource, $actor) : false,
            'owner' => in_array($this->status->value, ['pending', 'correction'], true) ? 'Production management' : null,
        ];
    }
}
