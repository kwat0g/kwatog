<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Support\WarrantyAdvisory;

class CustomerComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canManage = $viewer instanceof User && $viewer->hasPermission('crm.complaints.manage');
        $status = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;
        $reportLoaded = $this->resource->relationLoaded('eightDReport');
        $report = $reportLoaded ? $this->resource->getRelation('eightDReport') : null;
        $ncrLoaded = $this->resource->relationLoaded('ncr');
        $ncr = $ncrLoaded ? $this->resource->getRelation('ncr') : null;
        $ncrStatus = $ncr?->status instanceof \BackedEnum ? $ncr->status->value : $ncr?->status;
        $reportFinalized = (bool) $report?->finalized_at;
        $requiredEightDFields = [
            'd1_team', 'd2_problem', 'd3_containment', 'd4_root_cause',
            'd5_corrective_action', 'd6_verification', 'd7_prevention', 'd8_recognition',
        ];
        $reportComplete = $report !== null && collect($requiredEightDFields)
            ->every(fn (string $field): bool => trim((string) $report->{$field}) !== '');
        $qualityCompletionReady = $reportFinalized
            && $ncrStatus === 'closed'
            && $ncr?->disposition !== null;

        return [
            'id'                => $this->hash_id,
            'complaint_number'  => $this->complaint_number,
            'severity'          => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'severity_label'    => Str::headline((string) ($this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity)),
            'status'            => $this->status   instanceof \BackedEnum ? $this->status->value   : $this->status,
            'status_label'      => Str::headline((string) ($this->status instanceof \BackedEnum ? $this->status->value : $this->status)),
            'permissions'       => ['manage' => $canManage],
            'available_actions' => [
                'retry_ncr_handoff' => $canManage && $this->ncr_handoff_status?->value === 'manual_required',
                'edit_8d' => $canManage && $reportLoaded && (! $report || ! $reportFinalized),
                'finalize_8d' => $canManage && $reportLoaded && $reportComplete && ! $reportFinalized,
                'resolve' => $canManage && in_array($status, ['open', 'investigating'], true) && $qualityCompletionReady,
                'close' => $canManage && $status === 'resolved' && $qualityCompletionReady,
            ],
            'description'       => $this->description,
            'affected_quantity' => (int) $this->affected_quantity,
            'ncr_handoff'      => [
                'status'       => $this->ncr_handoff_status?->value,
                'status_label' => $this->ncr_handoff_status?->label(),
                'message'      => $this->ncr_handoff_message,
                'at'           => optional($this->ncr_handoff_at)->toIso8601String(),
            ],
            'received_date'     => optional($this->received_date)?->toDateString(),
            'resolved_at'       => optional($this->resolved_at)?->toISOString(),
            'closed_at'         => optional($this->closed_at)?->toISOString(),
            'customer'          => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'product'           => $this->whenLoaded('product', fn () => $this->product ? [
                'id'           => $this->product->hash_id,
                'part_number'  => $this->product->part_number,
                'name'         => $this->product->name,
            ] : null),
            'sales_order'       => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'delivered_item' => $this->whenLoaded('deliveryItem', fn () => $this->deliveryItem ? [
                'id' => $this->deliveryItem->hash_id,
                'delivery_number' => $this->deliveryItem->delivery?->delivery_number,
                'delivery_date' => $this->deliveryItem->delivery?->delivered_at?->toDateString(),
                'part_number' => $this->deliveryItem->salesOrderItem?->product?->part_number,
                'product_name' => $this->deliveryItem->salesOrderItem?->product?->name,
            ] : null),
            'warranty_advisory' => $this->whenLoaded('deliveryItem', fn () => $this->deliveryItem
                ? WarrantyAdvisory::from(
                    $this->deliveryItem->salesOrderItem?->warranty_terms_snapshot,
                    $this->deliveryItem->delivery?->delivered_at?->toDateString(),
                    $this->deliveryItem->delivery?->invoice?->date?->toDateString(),
                )
                : null),
            'ncr'               => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id'         => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
                'status'     => $this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status,
                'severity'   => $this->ncr->severity instanceof \BackedEnum ? $this->ncr->severity->value : $this->ncr->severity,
                'disposition' => $this->ncr->disposition instanceof \BackedEnum ? $this->ncr->disposition->value : $this->ncr->disposition,
                'status_label' => Str::headline((string) ($this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status)),
                'severity_label' => Str::headline((string) ($this->ncr->severity instanceof \BackedEnum ? $this->ncr->severity->value : $this->ncr->severity)),
            ] : null),
            'creator'           => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'assignee'          => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'   => $this->assignee->hash_id,
                'name' => $this->assignee->name,
            ] : null),
            'eight_d_report'    => $this->whenLoaded('eightDReport', fn () => $this->eightDReport ? [
                'id'                   => $this->eightDReport->hash_id,
                'version'              => $this->eightDReport->version,
                'd1_team'              => $this->eightDReport->d1_team,
                'd2_problem'           => $this->eightDReport->d2_problem,
                'd3_containment'       => $this->eightDReport->d3_containment,
                'd4_root_cause'        => $this->eightDReport->d4_root_cause,
                'd5_corrective_action' => $this->eightDReport->d5_corrective_action,
                'd6_verification'      => $this->eightDReport->d6_verification,
                'd7_prevention'        => $this->eightDReport->d7_prevention,
                'd8_recognition'       => $this->eightDReport->d8_recognition,
                'finalized_at'         => optional($this->eightDReport->finalized_at)?->toISOString(),
            ] : null),
            'created_at'        => optional($this->created_at)?->toISOString(),
            'updated_at'        => optional($this->updated_at)?->toISOString(),
        ];
    }
}
