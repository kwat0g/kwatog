<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ActivityEvent;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\SalesOrderSupplyService;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderFulfillmentController
{
    public function show(SalesOrder $salesOrder): JsonResponse
    {
        return response()->json(['data' => app(SalesOrderSupplyService::class)->preview($salesOrder)]);
    }

    public function update(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_unless($request->user()->hasPermission('production.schedule.confirm') || $request->user()->hasPermission('production.wo.confirm'), 403);
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'], 'request_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'string', 'distinct'], 'lines.*.policy' => ['required', 'in:stock_first,produce_new'],
        ]);

        return DB::transaction(function () use ($request, $salesOrder, $data): JsonResponse {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrder->id);
            $service = app(SalesOrderSupplyService::class);
            if (trim($data['reason']) === '') {
                throw new BusinessRuleException('Enter a planning reason.');
            }
            $fingerprint = hash('sha256', json_encode($data + ['actor_id' => $request->user()->id], JSON_THROW_ON_ERROR));
            $prior = ActivityEvent::where('idempotency_key', 'fulfillment:'.$data['request_key'])->first();
            if ($prior) {
                if ((int) $prior->subject_id !== (int) $order->id || ($prior->detail['fingerprint'] ?? '') !== $fingerprint) {
                    throw new BusinessRuleException('Fulfillment key belongs to another payload.');
                }

                return response()->json(['data' => $service->preview($order)]);
            }
            if ($order->status->value !== 'confirmed') {
                throw new BusinessRuleException('Review fulfillment before production or dispatch progresses.');
            }
            if (! hash_equals($service->revision($order), $data['revision'])) {
                throw new BusinessRuleException('Order changed. Refresh fulfillment before saving.');
            }
            $lines = $order->items()->lockForUpdate()->get()->keyBy('hash_id');
            if (count($data['lines']) !== $lines->count()) {
                throw new BusinessRuleException('Include each order line exactly once.');
            }
            $draftsToRetire = collect();
            foreach ($data['lines'] as $row) {
                $line = $lines->get($row['id']);
                if (! $line) {
                    throw new BusinessRuleException('Selected line does not belong to this order.');
                }
                if (($line->fulfillment_policy ?? 'produce_new') === $row['policy']) {
                    continue;
                }
                $shipments = Delivery::where('sales_order_id', $order->id)->where('status', '!=', 'cancelled')
                    ->whereHas('items', fn ($q) => $q->where('sales_order_item_id', $line->id))->lockForUpdate()->get();
                foreach ($shipments as $shipment) {
                    if ($shipment->status !== DeliveryStatus::Scheduled || ! $shipment->automatic_supply_key
                        || $shipment->driver_id || $shipment->vehicle_id || $shipment->departed_at) {
                        throw new BusinessRuleException('An assigned or dispatched shipment prevents this policy change.');
                    }
                    $draftsToRetire->put($shipment->id, $shipment);
                }
                if (WorkOrder::where('sales_order_item_id', $line->id)->where('status', '!=', 'cancelled')
                    ->where(fn ($q) => $q->where('status', '!=', 'planned')->orWhereHas('currentProductionSchedule')->orWhereHas('materials', fn ($q) => $q->where('actual_quantity_issued', '>', 0)))->exists()) {
                    throw new BusinessRuleException('Production or shipment commitments prevent this policy change.');
                }
            }
            foreach ($draftsToRetire as $shipment) {
                app(DeliveryService::class)->updateStatus($shipment, DeliveryStatus::Cancelled);
            }
            foreach ($data['lines'] as $row) {
                if ($row['policy'] === 'produce_new') {
                    $service->releaseLine($lines->get($row['id']));
                }
            }
            foreach ($data['lines'] as $row) {
                $lines->get($row['id'])->update(['fulfillment_policy' => $row['policy']]);
            }
            ActivityEvent::create(['subject_type' => $order->getMorphClass(), 'subject_id' => $order->id,
                'actor_user_id' => $request->user()->id, 'actor_type' => 'user', 'created_at' => now(), 'type' => 'sales_order.fulfillment', 'action' => 'reviewed',
                'idempotency_key' => 'fulfillment:'.$data['request_key'], 'summary' => $data['reason'], 'detail' => ['fingerprint' => $fingerprint, 'policies' => $data['lines'], 'request_key' => $data['request_key']]]);
            app(MrpEngineService::class)->runForSalesOrder($order, initiatingActorId: $request->user()->id, triggerReason: 'fulfillment_review');

            return response()->json(['data' => $service->preview($order)]);
        }, 3);
    }
}
