<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->hasPermission('supply_chain.fleet.manage') ?? false;
        $availableActions = [];
        if ($canManage) {
            if ($this->deleted_at) {
                $availableActions = ['restore'];
            } else {
                $availableActions[] = 'update';
                if ($this->asset_id === null && ! (bool) $this->has_active_delivery) {
                    $availableActions[] = 'archive';
                }
            }
        }

        return [
            'id'            => $this->hash_id,
            'plate_number'  => $this->plate_number,
            'name'          => $this->name,
            'vehicle_type'  => $this->vehicle_type,
            'capacity_kg'   => $this->capacity_kg !== null ? (float) $this->capacity_kg : null,
            'status'        => $this->status,
            'status_label'  => Str::headline((string) $this->status),
            'notes'         => $this->notes,
            'asset'         => $this->whenLoaded('asset', fn () => $this->asset ? [
                'id' => $this->asset->hash_id,
                'asset_code' => $this->asset->asset_code,
                'name' => $this->asset->name,
            ] : null),
            'deleted_at'    => optional($this->deleted_at)?->toIso8601String(),
            'version'       => $this->updated_at?->toISOString(),
            'permissions'   => ['manage' => $canManage],
            'available_actions' => $availableActions,
        ];
    }
}
