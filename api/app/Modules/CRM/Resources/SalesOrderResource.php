<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;

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
            'next_statuses'      => array_map(
                static fn (string $next): array => [
                    'value' => $next,
                    'label' => SalesOrderStatus::tryFrom($next)?->label() ?? $next,
                ],
                SalesOrderService::allowedTransitions()[$this->status?->value ?? ''] ?? [],
            ),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'delivery_terms'     => $this->delivery_terms,
            'incoterm'           => $this->incoterm?->value,
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
                'status_label'    => MrpPlanStatus::tryFrom((string) ($this->mrpPlan->status?->value ?? $this->mrpPlan->status))?->label(),
                'shortages_found' => (int) $this->mrpPlan->shortages_found,
                'auto_pr_count'   => (int) $this->mrpPlan->auto_pr_count,
                'draft_wo_count'  => (int) $this->mrpPlan->draft_wo_count,
            ] : null),
            'work_orders'        => $this->whenLoaded('workOrders', fn () =>
                $this->workOrders->map(fn ($wo) => [
                    'id'                => $wo->hash_id,
                    'wo_number'         => $wo->wo_number,
                    'status'            => (string) ($wo->status?->value ?? $wo->status),
                    'status_label'      => WorkOrderStatus::tryFrom((string) ($wo->status?->value ?? $wo->status))?->label(),
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
            'inspections'        => $this->whenLoaded('workOrders', fn () =>
                $this->workOrders->flatMap(fn ($wo) => $wo->relationLoaded('inspections')
                    ? $wo->inspections->map(fn ($inspection) => [
                        'id' => $inspection->hash_id,
                        'inspection_number' => $inspection->inspection_number,
                        'stage' => (string) ($inspection->stage?->value ?? $inspection->stage),
                        'stage_label' => InspectionStage::tryFrom((string) ($inspection->stage?->value ?? $inspection->stage))?->label(),
                        'status' => (string) ($inspection->status?->value ?? $inspection->status),
                        'status_label' => InspectionStatus::tryFrom((string) ($inspection->status?->value ?? $inspection->status))?->label(),
                        'completed_at' => optional($inspection->completed_at)->toIso8601String(),
                    ])
                    : collect())
                    ->values()
            ),
            'deliveries'         => $this->whenLoaded('deliveries', fn () =>
                $this->deliveries->map(fn ($delivery) => [
                    'id' => $delivery->hash_id,
                    'delivery_number' => $delivery->delivery_number,
                    'status' => (string) ($delivery->status?->value ?? $delivery->status),
                    'status_label' => DeliveryStatus::tryFrom((string) ($delivery->status?->value ?? $delivery->status))?->label(),
                    'scheduled_date' => optional($delivery->scheduled_date)->toDateString(),
                ])->values()
            ),
            'invoices'           => $this->whenLoaded('invoices', fn () =>
                $this->invoices->map(fn ($invoice) => [
                    'id' => $invoice->hash_id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => (string) ($invoice->status?->value ?? $invoice->status),
                    'status_label' => InvoiceStatus::tryFrom((string) ($invoice->status?->value ?? $invoice->status))?->label(),
                    'total_amount' => (string) $invoice->total_amount,
                    'balance' => (string) $invoice->balance,
                ])->values()
            ),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
            'deleted_at'         => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
