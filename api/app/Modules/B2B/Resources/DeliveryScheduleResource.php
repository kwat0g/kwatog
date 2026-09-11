<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DeliveryScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusValue = $this->status instanceof \BackedEnum
            ? (string) $this->status->value
            : (string) $this->status;

        return [
            'id'             => $this->hash_id,
            'month'          => $this->month,
            'status'         => $statusValue,
            'status_label'   => DeliveryScheduleStatus::tryFrom($statusValue)?->label() ?? Str::headline($statusValue),
            'source'         => $this->customer_id ? 'customer' : 'supplier',
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
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ] : null),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'reject_reason'  => $this->reject_reason,
            'reviewed_at'    => optional($this->reviewed_at)->toIso8601String(),
            'reviewed_by'    => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->hash_id,
                'name' => $this->reviewer->name,
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
