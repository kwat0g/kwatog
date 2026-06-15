<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
// ADV7 — Proof of Delivery fields surfaced via receiver_* + proofs[].
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
            'receipt_photo_url'   => $this->receipt_photo_path ? "/api/v1/supply-chain/deliveries/{$this->hash_id}/receipt-photo" : null,
            'notes'               => $this->notes,
            // ADV7 — Proof of Delivery receiver capture fields.
            'receiver_name'       => $this->receiver_name,
            'receiver_position'   => $this->receiver_position,
            'received_at'         => optional($this->received_at)?->toISOString(),
            'delivery_remarks'    => $this->delivery_remarks,
            'proofs'              => $this->whenLoaded('proofs', fn () => $this->proofs->map(fn ($p) => [
                'id'          => $p->hash_id,
                'proof_type'  => $p->proof_type,
                'file_name'   => $p->file_name,
                'file_size'   => $p->file_size,
                'mime_type'   => $p->mime_type,
                'is_image'    => $p->mime_type ? str_starts_with((string) $p->mime_type, 'image/') : false,
                'notes'       => $p->notes,
                'view_url'    => "/api/v1/supply-chain/deliveries/{$this->hash_id}/proofs/{$p->hash_id}/view",
                'uploader'    => $p->relationLoaded('uploader') && $p->uploader ? [
                    'id'   => $p->uploader->hash_id,
                    'name' => $p->uploader->name,
                ] : null,
                'uploaded_at' => optional($p->created_at)?->toISOString(),
            ])->all()),
            'proof_count'         => $this->whenLoaded('proofs', fn () => $this->proofs->count()),
            'sales_order'         => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'customer'  => $this->salesOrder->relationLoaded('customer') && $this->salesOrder->customer ? [
                    'id'   => $this->salesOrder->customer->hash_id,
                    'name' => $this->salesOrder->customer->name,
                ] : null,
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
            // ADV3 — IATF 16949 outgoing shipment lot (one per delivery).
            'shipment_lot'        => $this->whenLoaded('shipmentLot', fn () => $this->shipmentLot ? [
                'id'           => $this->shipmentLot->hash_id,
                'lot_number'   => $this->shipmentLot->lot_number,
                'lot_date'     => optional($this->shipmentLot->lot_date)?->toDateString(),
                'quantity'     => (int) $this->shipmentLot->quantity,
                'product'      => $this->shipmentLot->product ? [
                    'id'          => $this->shipmentLot->product->hash_id,
                    'part_number' => $this->shipmentLot->product->part_number ?? null,
                    'name'        => $this->shipmentLot->product->name ?? null,
                ] : null,
                'customer'     => $this->shipmentLot->customer ? [
                    'id'   => $this->shipmentLot->customer->hash_id,
                    'name' => $this->shipmentLot->customer->name ?? null,
                ] : null,
                'work_order_count' => is_array($this->shipmentLot->work_order_ids) ? count($this->shipmentLot->work_order_ids) : 0,
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
