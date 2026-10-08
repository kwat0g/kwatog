<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $code = $this->reason_code instanceof StockAdjustmentReason ? $this->reason_code : null;
        $actor = $request->user();
        $pending = $this->status === StockAdjustmentStatus::Pending;
        $isRequester = $actor !== null && $this->requested_by !== null
            && (int) $this->requested_by === (int) $actor->getAuthIdentifier();

        return [
            'id'            => $this->hash_id,
            'direction'     => $this->direction,
            'quantity'      => (string) $this->quantity,
            'unit_cost'     => (string) $this->unit_cost,
            'value'         => (string) $this->value,
            'reason_code'   => $code?->value,
            'reason_label'  => $code?->label(),
            'reason'        => $this->reason,
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
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
            'resolved_by'   => $this->whenLoaded('resolver', fn () => $this->resolver ? [
                'id' => $this->resolver->hash_id,
                'name' => $this->resolver->name,
            ] : null),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'resolution_reason' => $this->resolution_reason,
            'can_approve' => $pending && $actor?->hasPermission('inventory.adjust.approve') && ! $isRequester,
            'can_reject' => $pending && $actor?->hasPermission('inventory.adjust.approve') && ! $isRequester,
            'can_withdraw' => $pending && $actor?->hasPermission('inventory.adjust') && $isRequester,
            'created_at'    => optional($this->created_at)->toIso8601String(),
        ];
    }
}
