<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentPurchaseOrderOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'po_number' => $this->po_number,
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? ['name' => $this->vendor->name] : null),
            'incoterm' => $this->incoterm?->value,
        ];
    }
}
