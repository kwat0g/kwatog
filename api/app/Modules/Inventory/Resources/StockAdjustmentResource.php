<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Enums\StockAdjustmentReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $code = $this->reason_code instanceof StockAdjustmentReason ? $this->reason_code : null;

        return [
            'id'            => $this->hash_id,
            'direction'     => $this->direction,
            'quantity'      => (string) $this->quantity,
            'unit_cost'     => (string) $this->unit_cost,
            'value'         => (string) $this->value,
            'reason_code'   => $code?->value,
            'reason_label'  => $code?->label(),
            'reason'        => $this->reason,
            'status'        => $this->getRawOriginal('status'),
            'item'          => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ]),
            'location'      => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->hash_id,
                'code' => $this->location->code,
            ] : null),
            'stock_movement' => $this->whenLoaded('stockMovement', fn () => $this->stockMovement
                ? new StockMovementResource($this->stockMovement)
                : null),
            'requested_by'  => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id'   => $this->requester->hash_id,
                'name' => $this->requester->name,
            ] : null),
            'approved_by'   => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id'   => $this->approver->hash_id,
                'name' => $this->approver->name,
            ] : null),
            'approved_at'   => optional($this->approved_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
        ];
    }
}
