<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->hash_id,
            'pr_number'               => $this->pr_number,
            'date'                    => optional($this->date)->toDateString(),
            'reason'                  => $this->reason,
            'priority'                => (string) $this->priority?->value,
            'status'                  => (string) $this->status?->value,
            'is_auto_generated'       => (bool) $this->is_auto_generated,
            'current_approval_step'   => (int) $this->current_approval_step,
            'submitted_at'            => optional($this->submitted_at)->toIso8601String(),
            'approved_at'             => optional($this->approved_at)->toIso8601String(),
            'total_estimated_amount'  => $this->totalEstimatedAmount(),
            'requester'               => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id'   => $this->requester->hash_id,
                'name' => $this->requester->name,
            ] : null),
            'department'              => $this->whenLoaded('department', fn () => $this->department ? [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'items'                   => PurchaseRequestItemResource::collection($this->whenLoaded('items')),
            'approval_records'        => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
                'step_order' => $r->step_order,
                'role_slug'  => $r->role_slug,
                'action'     => $r->action,
                'remarks'    => $r->remarks,
                'acted_at'   => optional($r->acted_at)->toIso8601String(),
            ])->all()),
            'purchase_orders'         => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(fn ($po) => [
                'id'        => $po->hash_id,
                'po_number' => $po->po_number,
                'status'    => (string) $po->status?->value,
                'vendor'    => $po->vendor ? ['id' => $po->vendor->hash_id, 'name' => $po->vendor->name] : null,
                'total_amount' => (string) $po->total_amount,
            ])->all()),
            'created_at'              => optional($this->created_at)->toIso8601String(),
            'updated_at'              => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
