<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe shared projection of driver report and depot reconciliation. */
class DeliveryAttemptOutcomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeliveryAttemptOutcome $outcome */
        $outcome = $this->resource;

        return [
            'id' => $outcome->hash_id,
            'version' => $outcome->version,
            'can_amend' => $outcome->reconciled_at === null && ($request->user()?->hasPermission('supply_chain.deliveries.create')
                || ($request->is('api/v1/driver/*') && (int) $outcome->delivery->driver_id === (int) $request->user()?->id)),
            'return_requests' => $outcome->returnRequests->map(static fn ($rma): array => [
                'id' => $rma->hash_id, 'rma_number' => $rma->rma_number, 'status' => $rma->status->value,
            ])->all(),
            'revisions' => $outcome->revisions()->with('creator')->get()->map(static fn ($revision): array => [
                'id' => $revision->hash_id, 'kind' => $revision->kind->value, 'reason' => $revision->reason,
                'created_at' => $revision->created_at?->toISOString(), 'actor_name' => $revision->creator?->name,
                'before' => $revision->before_snapshot, 'after' => $revision->after_snapshot,
            ])->all(),
            'reason_code' => $outcome->reason_code instanceof \BackedEnum
                ? $outcome->reason_code->value : (string) $outcome->reason_code,
            'reason_label' => $outcome->reason_code instanceof \BackedEnum
                ? $outcome->reason_code->label() : (string) $outcome->reason_code,
            'notes' => $outcome->notes,
            'reported_at' => optional($outcome->reported_at)?->toISOString(),
            'reconciled_at' => optional($outcome->reconciled_at)?->toISOString(),
            'variance_reason' => $outcome->variance_reason,
            'return_request' => $outcome->relationLoaded('returnRequest') && $outcome->returnRequest ? [
                'id' => $outcome->returnRequest->hash_id,
                'rma_number' => $outcome->returnRequest->rma_number,
                'status' => $outcome->returnRequest->status instanceof ReturnRequestStatus
                    ? $outcome->returnRequest->status->value : (string) $outcome->returnRequest->status,
            ] : null,
            'lines' => $outcome->relationLoaded('items') ? $outcome->items->map(static function ($line): array {
                $declaredUnaccounted = (string) $line->declared_unaccounted_quantity;
                $finalUnaccounted = $line->unaccounted_quantity;

                return [
                    'delivery_item_id' => $line->deliveryItem?->hash_id,
                    'shipped_quantity' => (string) $line->shipped_quantity,
                    'customer_received_quantity' => (string) $line->customer_received_quantity,
                    'customer_received_damaged_quantity' => (string) $line->customer_received_damaged_quantity,
                    'truck_return_quantity' => (string) $line->truck_return_quantity,
                    'truck_return_damaged_quantity' => (string) $line->truck_return_damaged_quantity,
                    // Before the depot count, show the driver's declared residual.
                    // After reconciliation this is the residual after all physical recoveries.
                    'unaccounted_quantity' => $finalUnaccounted === null
                        ? $declaredUnaccounted : (string) $finalUnaccounted,
                    'declared_unaccounted_quantity' => $declaredUnaccounted,
                    'warehouse_received_quantity' => $line->warehouse_received_quantity === null
                        ? null : (string) $line->warehouse_received_quantity,
                ];
            })->values()->all() : [],
        ];
    }
}
