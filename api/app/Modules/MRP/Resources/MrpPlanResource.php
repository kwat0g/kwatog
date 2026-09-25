<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MrpPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'mrp_plan_no' => $this->mrp_plan_no,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'customer' => $this->salesOrder->relationLoaded('customer') && $this->salesOrder->customer
                    ? ['id' => $this->salesOrder->customer->hash_id, 'name' => $this->salesOrder->customer->name]
                    : null,
            ] : null),
            'version' => (int) $this->version,
            'status' => (string) $this->status?->value,
            'status_label' => MrpPlanStatus::tryFrom((string) $this->status?->value)?->label() ?? (string) $this->status?->value,
            'total_lines' => (int) $this->total_lines,
            'shortages_found' => (int) $this->shortages_found,
            'auto_pr_count' => (int) $this->auto_pr_count,
            'draft_wo_count' => (int) $this->draft_wo_count,
            'diagnostics' => MrpPlanningResponseSerializer::diagnostics($this->diagnostics ?? []),
            'cost_summary' => MrpPlanningResponseSerializer::costSummary($this->cost_summary),
            'generation' => MrpPlanningResponseSerializer::generationContext($this->generation_context),
            'generator' => $this->whenLoaded('generator', fn () => $this->generator ? [
                'id' => $this->generator->hash_id, 'name' => $this->generator->name,
            ] : null),
            'work_orders' => $this->whenLoaded('workOrders', fn () => $this->workOrders
                ->concat($this->relationLoaded('priorProgressedWorkOrders') ? $this->priorProgressedWorkOrders : [])
                ->map(fn ($w) => [
                'id' => $w->hash_id, 'wo_number' => $w->wo_number,
                'product_id' => $w->product_id === null ? null : app('hashids')->encode((int) $w->product_id),
                'parent' => $w->relationLoaded('parent') && $w->parent ? [
                    'id' => $w->parent->hash_id,
                    'wo_number' => $w->parent->wo_number,
                ] : null,
                'quantity_target' => (int) $w->quantity_target,
                'status' => (string) $w->status?->value,
                'status_label' => WorkOrderStatus::tryFrom((string) $w->status?->value)?->label() ?? (string) $w->status?->value,
                'planned_start' => optional($w->planned_start)->toIso8601String(),
            ])
            ),
            'purchase_requests' => $this->whenLoaded('purchaseRequests', fn () => $this->purchaseRequests
                ->concat($this->relationLoaded('priorProgressedPurchaseRequests') ? $this->priorProgressedPurchaseRequests : [])
                ->map(function ($p): array {
                    $priority = $p->priority instanceof \BackedEnum ? $p->priority->value : (string) $p->priority;
                    $status = $p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status;

                    return [
                        'id' => $p->hash_id, 'pr_number' => $p->pr_number,
                        'priority' => $priority, 'priority_label' => PurchaseRequestPriority::tryFrom($priority)?->label() ?? $priority, 'status' => $status,
                        'status_label' => PurchaseRequestStatus::tryFrom($status)?->label() ?? $status,
                        'is_auto_generated' => (bool) $p->is_auto_generated,
                        'date' => optional($p->date)->toDateString(),
                        'purchase_orders' => $p->relationLoaded('purchaseOrders') ? $p->purchaseOrders->map(function ($po): array {
                            $poStatus = $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status;

                            return [
                                'id' => $po->hash_id,
                                'po_number' => $po->po_number,
                                'status' => $poStatus,
                                'status_label' => PurchaseOrderStatus::tryFrom($poStatus)?->label() ?? $poStatus,
                            ];
                        }) : [],
                    ];
                })
            ),
            'generated_at' => optional($this->generated_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
