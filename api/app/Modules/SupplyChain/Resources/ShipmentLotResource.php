<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use App\Modules\Production\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $woIds = $this->work_order_ids ?? [];
        $batches = [];
        if (! empty($woIds)) {
            $batches = WorkOrder::query()
                ->whereIn('id', $woIds)
                ->get(['id', 'wo_number', 'batch_number', 'quantity_good'])
                ->map(fn (WorkOrder $wo) => [
                    'id'             => $wo->hash_id,
                    'wo_number'      => $wo->wo_number,
                    'batch_number'   => $wo->batch_number,
                    'quantity_good'  => (int) $wo->quantity_good,
                ])
                ->values()
                ->all();
        }

        return [
            'id'         => $this->hash_id,
            'lot_number' => $this->lot_number,
            'delivery'   => $this->whenLoaded('delivery', fn () => $this->delivery ? [
                'id'              => $this->delivery->hash_id,
                'delivery_number' => $this->delivery->delivery_number,
                'status'          => (string) ($this->delivery->status?->value ?? $this->delivery->status),
            ] : null),
            'customer'   => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name ?? null,
            ] : null),
            'product'    => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number ?? null,
                'name'        => $this->product->name ?? null,
            ] : null),
            'batches'    => $batches,
            'quantity'   => (int) $this->quantity,
            'lot_date'   => $this->lot_date?->toDateString(),
            'coc_path'   => $this->coc_path,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
