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
            'auto_generated_reason'   => $this->auto_generated_reason,
            'is_urgent'               => (bool) $this->is_urgent,
            'urgency_reason'          => $this->urgency_reason,
            'current_approval_step'   => (int) $this->current_approval_step,
            'has_overdue_approval'    => $this->relationLoaded('approvalRecords')
                ? $this->approvalRecords->contains(fn ($r) => $r->action === 'pending' && $r->is_overdue)
                : false,
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
            'template'                => $this->whenLoaded('template', fn () => $this->template ? [
                'id'   => app('hashids')->encode((int) $this->template->id),
                'name' => $this->template->name,
            ] : null),
            'items'                   => PurchaseRequestItemResource::collection($this->whenLoaded('items')),
            'approval_records'        => $this->whenLoaded('approvalRecords', fn () => $this->approvalRecords->map(fn ($r) => [
                'step_order'    => (int) $r->step_order,
                'role_slug'     => $r->role_slug,
                'action'        => $r->action,
                'remarks'       => $r->remarks,
                'acted_at'      => optional($r->acted_at)->toIso8601String(),
                'approver'      => $r->relationLoaded('approver') && $r->approver ? [
                    'id'   => $r->approver->hash_id,
                    'name' => $r->approver->name,
                ] : null,
                'is_overdue'    => (bool) $r->is_overdue,
                'overdue_hours' => $r->is_overdue ? (int) $r->overdue_hours : null,
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
