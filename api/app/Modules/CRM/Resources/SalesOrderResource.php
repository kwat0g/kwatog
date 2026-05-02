<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'so_number'          => $this->so_number,
            'date'               => optional($this->date)->toDateString(),
            'subtotal'           => (string) $this->subtotal,
            'vat_amount'         => (string) $this->vat_amount,
            'total_amount'       => (string) $this->total_amount,
            'status'             => (string) $this->status?->value,
            'status_label'       => $this->status?->label(),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'delivery_terms'     => $this->delivery_terms,
            'notes'              => $this->notes,
            'is_editable'        => (bool) $this->is_editable,
            'is_cancellable'     => (bool) $this->is_cancellable,
            'item_count'         => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'customer'           => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ]),
            'creator'            => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'items'              => $this->whenLoaded('items', fn () =>
                SalesOrderItemResource::collection($this->items)
            ),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
