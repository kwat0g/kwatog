<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Auth\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'session_number'   => $this->session_number,
            'title'            => $this->title,
            'scope'            => $this->scope,
            'warehouse'        => $this->whenLoaded('warehouse', fn () => [
                'id'   => $this->warehouse?->hash_id,
                'name' => $this->warehouse?->name,
                'code' => $this->warehouse?->code,
            ]),
            'zone'             => $this->whenLoaded('zone', fn () => [
                'id'   => $this->zone?->hash_id,
                'name' => $this->zone?->name,
                'code' => $this->zone?->code,
            ]),
            'status'           => $this->status,
            'total_locations'  => $this->total_locations,
            'counted_locations' => $this->counted_locations,
            'variance_count'   => $this->variance_count,
            'variance_value'   => $this->variance_value,
            'created_by'       => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'approved_by'      => $this->whenLoaded('approver', fn () => new UserResource($this->approver)),
            'frozen_at'        => $this->frozen_at?->toISOString(),
            'completed_at'     => $this->completed_at?->toISOString(),
            'notes'            => $this->notes,
            'items'            => StockCountItemResource::collection($this->whenLoaded('items')),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
