<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'delivery_number'     => $this->delivery_number,
            'status'              => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'scheduled_date'      => optional($this->scheduled_date)?->toDateString(),
            'departed_at'         => optional($this->departed_at)?->toISOString(),
            'delivered_at'        => optional($this->delivered_at)?->toISOString(),
            'confirmed_at'        => optional($this->confirmed_at)?->toISOString(),
            'receipt_photo_url'   => $this->receipt_photo_path ? Storage::disk('public')->url($this->receipt_photo_path) : null,
            'notes'               => $this->notes,
            'sales_order'         => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'vehicle'             => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'           => $this->vehicle->hash_id,
                'plate_number' => $this->vehicle->plate_number,
                'name'         => $this->vehicle->name,
            ] : null),
            'driver'              => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id'   => $this->driver->hash_id,
                'name' => $this->driver->name,
            ] : null),
            'confirmer'           => $this->whenLoaded('confirmer', fn () => $this->confirmer ? [
                'id'   => $this->confirmer->hash_id,
                'name' => $this->confirmer->name,
            ] : null),
            'invoice'             => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->hash_id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount'   => (string) $this->invoice->total_amount,
                'status'         => $this->invoice->status instanceof \BackedEnum ? $this->invoice->status->value : $this->invoice->status,
            ] : null),
            'items'               => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'                  => $i->hash_id,
                'sales_order_item_id' => optional($i->salesOrderItem)?->hash_id,
                'inspection'          => $i->relationLoaded('inspection') && $i->inspection ? [
                    'id'                => $i->inspection->hash_id,
                    'inspection_number' => $i->inspection->inspection_number,
                    'status'            => $i->inspection->status instanceof \BackedEnum ? $i->inspection->status->value : $i->inspection->status,
                ] : null,
                'quantity'            => (float) $i->quantity,
                'unit_price'          => (string) $i->unit_price,
            ])->all()),
            'created_at'          => optional($this->created_at)?->toISOString(),
            'updated_at'          => optional($this->updated_at)?->toISOString(),
        ];
    }
}
