<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MrpPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'mrp_plan_no'     => $this->mrp_plan_no,
            'sales_order'     => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'customer'  => $this->salesOrder->relationLoaded('customer') && $this->salesOrder->customer
                    ? ['id' => $this->salesOrder->customer->hash_id, 'name' => $this->salesOrder->customer->name]
                    : null,
            ] : null),
            'version'         => (int) $this->version,
            'status'          => (string) $this->status?->value,
            'total_lines'     => (int) $this->total_lines,
            'shortages_found' => (int) $this->shortages_found,
            'auto_pr_count'   => (int) $this->auto_pr_count,
            'draft_wo_count'  => (int) $this->draft_wo_count,
            'diagnostics'     => $this->diagnostics ?? [],
            'generator'       => $this->whenLoaded('generator', fn () => $this->generator ? [
                'id' => $this->generator->hash_id, 'name' => $this->generator->name,
            ] : null),
            'work_orders'     => $this->whenLoaded('workOrders', fn () =>
                $this->workOrders->map(fn ($w) => [
                    'id' => $w->hash_id, 'wo_number' => $w->wo_number,
                    'product_id' => $w->product_id,
                    'quantity_target' => (int) $w->quantity_target,
                    'status' => (string) $w->status?->value,
                    'planned_start' => optional($w->planned_start)->toIso8601String(),
                ])
            ),
            'purchase_requests' => $this->whenLoaded('purchaseRequests', fn () =>
                $this->purchaseRequests->map(fn ($p) => [
                    'id' => $p->hash_id, 'pr_number' => $p->pr_number,
                    'priority' => $p->priority, 'status' => $p->status,
                    'is_auto_generated' => (bool) $p->is_auto_generated,
                    'date' => optional($p->date)->toDateString(),
                ])
            ),
            'generated_at'    => optional($this->generated_at)->toIso8601String(),
            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
