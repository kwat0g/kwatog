<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NcrResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'ncr_number'         => $this->ncr_number,
            'source'             => $this->source instanceof \BackedEnum ? $this->source->value : $this->source,
            'severity'           => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'status'             => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'disposition'        => $this->disposition instanceof \BackedEnum ? $this->disposition->value : $this->disposition,
            'defect_description' => $this->defect_description,
            'affected_quantity'  => (int) $this->affected_quantity,
            'root_cause'         => $this->root_cause,
            'corrective_action'  => $this->corrective_action,
            'closed_at'          => optional($this->closed_at)?->toISOString(),
            'product'            => $this->whenLoaded('product', fn () => $this->product ? [
                'id'           => $this->product->hash_id,
                'part_number'  => $this->product->part_number,
                'name'         => $this->product->name,
            ] : null),
            'inspection'         => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id'                => $this->inspection->hash_id,
                'inspection_number' => $this->inspection->inspection_number,
                'stage'             => $this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage,
                'status'            => $this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status,
            ] : null),
            'creator'            => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'assignee'           => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'   => $this->assignee->hash_id,
                'name' => $this->assignee->name,
            ] : null),
            'closer'             => $this->whenLoaded('closer', fn () => $this->closer ? [
                'id'   => $this->closer->hash_id,
                'name' => $this->closer->name,
            ] : null),
            'replacement_work_order' => $this->whenLoaded('replacementWorkOrder', fn () => $this->replacementWorkOrder ? [
                'id'              => $this->replacementWorkOrder->hash_id,
                'wo_number'       => $this->replacementWorkOrder->wo_number,
                'status'          => $this->replacementWorkOrder->status instanceof \BackedEnum ? $this->replacementWorkOrder->status->value : $this->replacementWorkOrder->status,
                'quantity_target' => (int) $this->replacementWorkOrder->quantity_target,
            ] : null),
            'actions'            => $this->whenLoaded('actions', fn () => NcrActionResource::collection($this->actions)->resolve()),
            'created_at'         => optional($this->created_at)?->toISOString(),
            'updated_at'         => optional($this->updated_at)?->toISOString(),
        ];
    }
}
