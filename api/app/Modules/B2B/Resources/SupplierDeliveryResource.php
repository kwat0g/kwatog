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
        ];
    }
}
