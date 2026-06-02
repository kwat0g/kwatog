<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'month'          => $this->month,
            'status'         => $this->status,
            'lines'          => $this->lines ?? [],
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
