<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->hash_id,
            'quote_number'                => $this->quote_number,
            'status'                      => $this->status?->value,
            'status_label'                => $this->status?->label(),
            'is_editable'                 => $this->status?->isEditable() ?? false,
            'valid_until'                 => optional($this->valid_until)->toDateString(),
            'subtotal'                    => (string) $this->subtotal,
            'tax_amount'                  => (string) $this->tax_amount,
            'total_amount'                => (string) $this->total_amount,
            'terms'                       => $this->terms,
            'revision'                    => (int) $this->revision,
            'converted_to_sales_order_id' => $this->converted_to_sales_order_id
                ? app('hashids')->encode($this->converted_to_sales_order_id)
                : null,
            'item_count'   => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'customer'     => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'opportunity'  => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id'                 => $this->opportunity->hash_id,
                'opportunity_number' => $this->opportunity->opportunity_number,
                'title'              => $this->opportunity->title,
            ] : null),
            'items'        => $this->whenLoaded('items', fn () =>
                QuoteItemResource::collection($this->items)
            ),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
