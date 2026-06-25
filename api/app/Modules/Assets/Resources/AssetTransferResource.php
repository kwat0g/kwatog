<?php

declare(strict_types=1);

namespace App\Modules\Assets\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetTransferResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->hash_id,
            'transfer_number' => $this->transfer_number,
            'asset'           => $this->whenLoaded('asset', fn () => [
                'id'         => $this->asset->hash_id,
                'asset_code' => $this->asset->asset_code,
                'name'       => $this->asset->name,
            ]),
            'from_department' => $this->whenLoaded('fromDepartment', fn () => [
                'id'   => $this->fromDepartment->hash_id,
                'name' => $this->fromDepartment->name,
            ]),
            'to_department'   => $this->whenLoaded('toDepartment', fn () => [
                'id'   => $this->toDepartment->hash_id,
                'name' => $this->toDepartment->name,
            ]),
            'reason'          => $this->reason,
            'status'          => $this->status?->value,
            'transfer_date'   => $this->transfer_date?->toDateString(),
            'approved_at'     => $this->approved_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
