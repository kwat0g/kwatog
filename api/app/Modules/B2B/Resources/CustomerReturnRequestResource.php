<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-safe return (RMA) representation.
 *
 * The internal ReturnRequestResource exposes credit notes, NCR handoff,
 * inspections, approval records, creator and internal notes. A portal response
 * is an explicit allowlist instead of relying on relation loading to decide
 * what a customer can see.
 */
class CustomerReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'rma_number'         => $this->rma_number,
            'type'               => $this->type?->value,
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'reason_code'        => $this->reason_code,
            'reason_description' => $this->reason_description,
            'customer_notes'     => $this->customer_notes,
            'resolution'         => $this->resolution,
            'return_date'        => optional($this->return_date)->toDateString(),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'items'              => $this->whenLoaded('items', fn () => $this->items->map(
                static fn ($item): array => [
                    'id'         => $item->hash_id,
                    'product'    => $item->relationLoaded('product') && $item->product ? [
                        'id'          => $item->product->hash_id,
                        'part_number' => $item->product->part_number,
                        'name'        => $item->product->name,
                    ] : null,
                    'quantity'   => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'total'      => (string) $item->total,
                    'reason'     => $item->reason,
                    'condition'  => $item->condition,
                    'disposition'=> $item->disposition,
                ],
            )->values()),
        ];
    }
}
