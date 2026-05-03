<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'complaint_number'  => $this->complaint_number,
            'severity'          => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'status'            => $this->status   instanceof \BackedEnum ? $this->status->value   : $this->status,
            'description'       => $this->description,
            'affected_quantity' => (int) $this->affected_quantity,
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
            'ncr'               => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id'         => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
                'status'     => $this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status,
                'severity'   => $this->ncr->severity instanceof \BackedEnum ? $this->ncr->severity->value : $this->ncr->severity,
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
