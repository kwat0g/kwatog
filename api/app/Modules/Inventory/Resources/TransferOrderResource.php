<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Auth\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'transfer_number' => $this->transfer_number,
            'from_location'   => $this->whenLoaded('fromLocation', fn () => [
                'id'        => $this->fromLocation?->hash_id,
                'code'      => $this->fromLocation?->code,
                'full_code' => $this->fromLocation?->full_code,
            ]),
            'to_location'     => $this->whenLoaded('toLocation', fn () => [
                'id'        => $this->toLocation?->hash_id,
                'code'      => $this->toLocation?->code,
                'full_code' => $this->toLocation?->full_code,
            ]),
            'item'            => $this->whenLoaded('item', fn () => [
                'id'              => $this->item?->hash_id,
                'code'            => $this->item?->code,
                'name'            => $this->item?->name,
                'unit_of_measure' => $this->item?->unit_of_measure,
            ]),
            'quantity'        => $this->quantity,
            'reason'          => $this->reason,
            'status'          => $this->status,
            'created_by'      => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'transferred_by'  => $this->whenLoaded('transferrer', fn () => new UserResource($this->transferrer)),
            'transferred_at'  => $this->transferred_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
