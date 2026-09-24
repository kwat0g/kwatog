<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Inventory\Enums\GrnStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof GrnStatus
            ? $this->status
            : GrnStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->hash_id,
            'grn_number' => $this->grn_number,
            'received_date' => optional($this->received_date)?->toDateString(),
            'status' => $status?->value ?? (string) $this->status,
            'status_label' => $status?->label() ?? (string) $this->status,
            'purchase_order' => $this->relationLoaded('purchaseOrder') && $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null,
            // The rejection reason is recorded once per receipt, not per line.
            'rejection_reason' => $this->rejected_reason,
            'lines' => $this->whenLoaded('items', fn () => $this->items->map(static fn ($line): array => [
                'item_code' => $line->item?->code ?? '—',
                'item_name' => $line->item?->name ?? '—',
                'quantity_received' => (string) $line->quantity_received,
                'quantity_accepted' => (string) $line->quantity_accepted,
                'quantity_rejected' => bcsub((string) $line->quantity_received, (string) $line->quantity_accepted, 3),
                'remarks' => $line->remarks,
            ])->values()->all()),
        ];
    }
}
