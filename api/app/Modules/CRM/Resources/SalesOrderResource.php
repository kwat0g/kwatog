<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'so_number'          => $this->so_number,
            'date'               => optional($this->date)->toDateString(),
            'subtotal'           => (string) $this->subtotal,
            'vat_amount'         => (string) $this->vat_amount,
            'total_amount'       => (string) $this->total_amount,
            'status'             => (string) $this->status?->value,
            'status_label'       => $this->status?->label(),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'delivery_terms'     => $this->delivery_terms,
            'notes'              => $this->notes,
            'is_editable'        => (bool) $this->is_editable,
            'is_cancellable'     => (bool) $this->is_cancellable,
            'item_count'         => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'customer'           => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ]),
            'creator'            => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'items'              => $this->whenLoaded('items', fn () =>
                SalesOrderItemResource::collection($this->items)
            ),
            // Sprint 6 audit §3.2: surface the chain context for the right-
            // panel LinkedRecords block on the detail page. hash_id only —
            // never raw integer FKs (see plans/sprint-6-audit §1.3).
            'mrp_plan'           => $this->whenLoaded('mrpPlan', fn () => $this->mrpPlan ? [
                'id'              => $this->mrpPlan->hash_id,
                'mrp_plan_no'     => $this->mrpPlan->mrp_plan_no,
                'version'         => (int) $this->mrpPlan->version,
                'status'          => (string) ($this->mrpPlan->status?->value ?? $this->mrpPlan->status),
                'shortages_found' => (int) $this->mrpPlan->shortages_found,
                'auto_pr_count'   => (int) $this->mrpPlan->auto_pr_count,
                'draft_wo_count'  => (int) $this->mrpPlan->draft_wo_count,
            ] : null),
            'work_orders'        => $this->whenLoaded('workOrders', fn () =>
                $this->workOrders->map(fn ($wo) => [
                    'id'                => $wo->hash_id,
                    'wo_number'         => $wo->wo_number,
                    'status'            => (string) ($wo->status?->value ?? $wo->status),
                    'quantity_target'   => (int) $wo->quantity_target,
                    'quantity_produced' => (int) $wo->quantity_produced,
                    'planned_start'     => optional($wo->planned_start)->toIso8601String(),
                    'product'           => $wo->relationLoaded('product') && $wo->product ? [
                        'id'          => $wo->product->hash_id,
                        'part_number' => $wo->product->part_number,
                        'name'        => $wo->product->name,
                    ] : null,
                ])->values()
            ),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
