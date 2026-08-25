<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DeliveryScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'month'          => $this->month,
            'status'         => $this->status,
            'status_label'   => Str::headline((string) $this->status),
            'lines'          => collect($this->lines ?? [])->map(function (mixed $line): array {
                $line = is_array($line) ? $line : [];
                $item = null;
                $rawItemId = $line['purchase_order_item_id'] ?? null;

                if ($rawItemId !== null
                    && $this->relationLoaded('purchaseOrder')
                    && $this->purchaseOrder
                    && $this->purchaseOrder->relationLoaded('items')) {
                    $item = $this->purchaseOrder->items->first(
                        static fn ($candidate): bool => (string) $candidate->id === (string) $rawItemId
                            || $candidate->hash_id === (string) $rawItemId,
                    );
                }

                return [
                    // Never echo a legacy numeric database ID. New supplier
                    // rows already store the hash; legacy rows are converted
                    // from the loaded PO line when that relation is present.
                    'purchase_order_item_id' => $item?->hash_id
                        ?? (is_string($rawItemId) && ! ctype_digit($rawItemId) ? $rawItemId : null),
                    'product_name' => (string) ($line['product_name'] ?? $item?->description ?? '—'),
                    'quantity' => (string) ($line['quantity'] ?? '0'),
                    'notes' => $line['notes'] ?? null,
                ];
            })->values()->all(),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
