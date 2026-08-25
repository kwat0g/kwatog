<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use App\Modules\SupplyChain\Enums\DeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Least-privilege delivery contract for the driver PWA.
 *
 * This deliberately does not reuse DeliveryResource: the internal resource
 * includes invoice totals, unit prices, inspections, shipment lots, notes,
 * and proof URLs that a driver does not need to operate the route.
 */
class DriverDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof DeliveryStatus
            ? $this->status
            : DeliveryStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->hash_id,
            'delivery_number' => $this->delivery_number,
            'status' => $status?->value ?? (string) $this->status,
            'status_label' => $status?->label() ?? (string) $this->status,
            'next_status' => $this->nextStatus($status),
            'next_status_label' => ($next = $this->nextStatus($status))
                ? DeliveryStatus::from($next)->label()
                : null,
            'scheduled_date' => optional($this->scheduled_date)?->toDateString(),
            'departed_at' => optional($this->departed_at)?->toISOString(),
            'delivered_at' => optional($this->delivered_at)?->toISOString(),
            'confirmed_at' => optional($this->confirmed_at)?->toISOString(),
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
                'customer' => $this->salesOrder->relationLoaded('customer') && $this->salesOrder->customer ? [
                    'id' => $this->salesOrder->customer->hash_id,
                    'name' => $this->salesOrder->customer->name,
                ] : null,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->hash_id,
                'plate_number' => $this->vehicle->plate_number,
                'name' => $this->vehicle->name,
            ] : null),
            // Drivers may see their own proof state, but never broad internal
            // proof-view URLs or uploader/inspection/accounting metadata.
            'proofs' => $this->whenLoaded('proofs', fn () => $this->proofs->map(static fn ($proof): array => [
                'id' => $proof->hash_id,
                'proof_type' => $proof->proof_type,
                'file_name' => $proof->file_name,
                'uploaded_at' => optional($proof->created_at)?->toISOString(),
            ])->all()),
            'proof_count' => $this->whenLoaded('proofs', fn () => $this->proofs->count()),
        ];
    }

    private function nextStatus(?DeliveryStatus $status): ?string
    {
        if (! $status) {
            return null;
        }

        foreach ([DeliveryStatus::Loading, DeliveryStatus::InTransit, DeliveryStatus::Delivered] as $candidate) {
            if ($status->canTransitionTo($candidate)) {
                return $candidate->value;
            }
        }

        return null;
    }
}
