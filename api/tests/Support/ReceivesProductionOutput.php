<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounting\Models\Customer;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

/**
 * Records the finished-goods receipt a work-order output needs before its lots
 * can be dispatched. `DeliveryStockService::source()` refuses an approved output
 * without a `production_receipt` movement whose lot traces back to that output,
 * so fixtures that dispatch produced goods must arrive at stock the way
 * Production does instead of hand-stamping a lot.
 *
 * The receipt always goes through the real `WorkOrderOutputService` handoff, so
 * the lot number, stock level, output link and handoff status match the running
 * app. The item and bin lookup mirrors `WorkOrderOutputService`'s own selection
 * so the receipt lands where the service would have put it.
 */
trait ReceivesProductionOutput
{
    use HasAssignedProductionCrew;

    protected function receiveProductionOutput(WorkOrderOutput $output, User $actor, ?Product $product = null): WorkOrderOutput
    {
        $this->prepareProductionLedgerSetup();
        $product ??= $output->workOrder()->first()?->product()->first();
        if (! $product) {
            throw new \RuntimeException('A production output needs its work-order product before it can be received.');
        }

        $this->finishedGoodsItemFor($product);
        $this->finishedGoodsLocation();

        $received = app(WorkOrderOutputService::class)->retryProductionReceipt($output, $actor);
        $movement = $received->productionReceiptMovement()->first();
        if ($movement && ! in_array($movement->gl_handoff_status, [
            MovementGlHandoffStatus::Generated,
            MovementGlHandoffStatus::NotRequired,
        ], true)) {
            app(MovementGlPostingService::class)->retry($movement);
        }

        return $received->fresh();
    }

    /** The finished-goods inventory item for a product, created once per code. */
    protected function finishedGoodsItemFor(Product $product): Item
    {
        return Item::query()->firstOrCreate(
            ['code' => $product->part_number, 'item_type' => ItemType::FinishedGood->value],
            [
                'name' => $product->name,
                'category_id' => ItemCategory::factory()->create()->id,
                'unit_of_measure' => $product->unit_of_measure ?: 'pcs',
                'standard_cost' => $product->standard_cost ?: '0.0000',
                'is_active' => true,
            ],
        );
    }

    /** An active finished-goods bin, reusing whatever the fixture already has. */
    protected function finishedGoodsLocation(): WarehouseLocation
    {
        $zone = WarehouseZone::query()
            ->where('zone_type', WarehouseZoneType::FinishedGoods->value)
            ->orderBy('id')
            ->first();
        if (! $zone) {
            $zone = WarehouseZone::factory()->create([
                'code' => 'FG-TEST', 'name' => 'Finished goods (test)',
                'zone_type' => WarehouseZoneType::FinishedGoods->value,
            ]);
        }

        return WarehouseLocation::query()
            ->where('zone_id', $zone->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first()
            ?? WarehouseLocation::factory()->create([
                'zone_id' => $zone->id, 'code' => 'FG-TEST-A', 'is_active' => true,
            ]);
    }

    protected function reserveDeliveryStock(Delivery $delivery, User $warehouse): void
    {
        $this->actingAs($warehouse)
            ->postJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/reserve-stock', [
                'request_key' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('delivery_stock_reservation_batches', [
            'delivery_id' => $delivery->id,
            'status' => 'reserved',
            'reserved_by' => $warehouse->id,
        ]);
        $this->assertDatabaseHas('delivery_stock_reservations', [
            'delivery_id' => $delivery->id,
            'status' => 'reserved',
        ]);
    }

    /**
     * A delivery that is genuinely ready to depart: produced stock received into
     * a finished-goods bin, an independently reviewed outgoing inspection, and
     * the reservation `DeliveryService::create()` holds. The departure guard
     * refuses a delivery that has no durable reservation, so status-transition
     * fixtures cannot hand-build a bare row any more.
     *
     * @return array{0: Delivery, 1: Item, 2: WarehouseLocation}
     */
    protected function dispatchableDelivery(
        User $creator,
        string $quantity = '3.00',
        ?SalesOrder $salesOrder = null,
    ): array {
        $this->prepareProductionLedgerSetup();

        if ($salesOrder) {
            $order = $salesOrder;
            $line = SalesOrderItem::query()->where('sales_order_id', $order->id)->orderBy('id')->firstOrFail();
            $product = Product::query()->findOrFail($line->product_id);
            $outputQuantity = (int) ceil((float) $line->quantity);
        } else {
            $customer = Customer::create(['name' => 'Dispatchable customer '.uniqid(), 'is_active' => true]);
            $product = Product::create([
                'part_number' => 'FG-'.strtoupper(substr(uniqid(), -8)),
                'name' => 'Dispatchable product '.uniqid(),
                'unit_of_measure' => 'pcs',
                'standard_cost' => '4.50',
                'is_active' => true,
            ]);
            $subtotal = bcmul($quantity, '15.00', 2);
            $vat = bcmul($subtotal, '0.12', 2);
            $order = SalesOrder::create([
                'so_number' => 'SO-DISP-'.strtoupper(substr(uniqid(), -8)),
                'customer_id' => $customer->id,
                'date' => now()->toDateString(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => bcadd($subtotal, $vat, 2),
                'status' => 'confirmed', 'created_by' => $creator->id,
            ]);
            $line = SalesOrderItem::create([
                'sales_order_id' => $order->id, 'product_id' => $product->id,
                'quantity' => $quantity, 'unit_price' => '15.00', 'total' => $subtotal,
                'quantity_delivered' => '0.00', 'delivery_date' => now()->addDay()->toDateString(),
            ]);
            $outputQuantity = (int) ceil((float) $line->quantity);
        }

        if ($outputQuantity < 1) {
            throw new \RuntimeException('Dispatchable fixture needs a positive sales-order quantity.');
        }

        $production = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $qcMaker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $qcChecker = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, contamination, or visible surface damage.',
            'is_critical' => false,
        ]], $production->id);

        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create([
            'mold_code' => 'DISP-'.strtoupper(substr(uniqid(), -6)), 'name' => 'Dispatch fixture mold',
            'product_id' => $product->id, 'cavity_count' => 1, 'cycle_time_seconds' => 25,
            'output_rate_per_hour' => 120, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000, 'status' => 'available',
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $workOrder = WorkOrder::create([
            'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'wo_number' => 'WO-DISP-'.strtoupper(substr(uniqid(), -6)),
            'product_id' => $product->id, 'sales_order_id' => $order->id,
            'sales_order_item_id' => $line->id, 'quantity_target' => $outputQuantity,
            'quantity_good' => 0, 'quantity_produced' => 0,
            'planned_start' => now()->startOfMinute(), 'planned_end' => now()->addHours(4),
            'material_plan_source' => 'bom', 'status' => 'confirmed', 'created_by' => $production->id,
        ]);

        $raw = Item::factory()->create(['standard_cost' => '1.0000', 'reorder_point' => 0]);
        $rawLocation = WarehouseLocation::factory()->create();
        app(StockMovementService::class)->move(
            new StockMovementInput(
                type: StockMovementType::AdjustmentIn,
                itemId: $raw->id, toLocationId: $rawLocation->id, quantity: '1.000', unitCost: '1.0000',
            )
        );
        WorkOrderMaterial::create([
            'work_order_id' => $workOrder->id, 'item_id' => $raw->id, 'bom_quantity' => '1.000',
            'standard_unit_cost' => '1.0000', 'standard_cost' => '1.00',
        ]);
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $issueSlip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->id, 'issued_date' => now()->toDateString(),
            'items' => [['item_id' => $raw->id, 'location_id' => $rawLocation->id, 'quantity_issued' => '1.000']],
        ], $warehouse);
        $processInspection = $this->createInspectionThroughApi($workOrder, $product, $qcMaker, InspectionStage::InProcess, $outputQuantity);

        $this->finishedGoodsItemFor($product);
        $this->finishedGoodsLocation();
        $crew = $this->assignProductionCrew($workOrder, $production);
        // Production may only record output after Warehouse confirms the
        // material handover to the crew recorder (WorkOrderMaterialHandoverService).
        $sourceMovement = $issueSlip->items()->firstOrFail()->stockMovement;
        $this->actingAs($warehouse)->postJson('/api/v1/inventory/material-handovers/group', [
            'received_by_employee_id' => Employee::findOrFail($crew->recorder_employee_id)->hash_id,
            'sources' => [['movement_id' => $sourceMovement->hash_id, 'expected_quantity' => '1.000']],
        ])->assertOk();
        ProductionSchedule::create([
            'work_order_id' => $workOrder->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->startOfMinute(), 'scheduled_end' => $workOrder->planned_end,
            'is_confirmed' => true, 'status' => 'confirmed', 'reserves_capacity' => true, 'priority_order' => 1,
        ]);
        $startResponse = $this->actingAs($production)->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/start", [
            'expected_crew_id' => $crew->hash_id,
        ]);
        $this->assertTrue($startResponse->isSuccessful(), $startResponse->getContent());

        // Startup-trial gate: in-process QC evidence for a root work order may
        // only be saved after Production records the pinned trial pieces.
        $trialResponse = $this->actingAs($production)
            ->withHeaders(['X-Idempotency-Key' => 'e2e-trial-'.$workOrder->id])
            ->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/trial-outputs", [
                'crew_id' => $crew->hash_id,
                'trial_quantity' => min((int) $processInspection->sample_size, (int) $workOrder->quantity_target),
                'recording_reason' => 'Startup trial pieces for the pinned in-process QC sample.',
            ]);
        $this->assertTrue($trialResponse->isSuccessful(), $trialResponse->getContent());
        // withHeaders() sticks to later requests on the same test case; the
        // trial key must not leak into the plain output recordings.
        $this->flushHeaders();

        $processInspection = $this->submitInspectionPassThroughApi($processInspection, $qcMaker);
        $this->assertSame(InspectionStatus::Passed, $processInspection->fresh()->status);
        $workOrder->fresh()->assertProcessQualityReleased();

        // QC acceptance settles the startup trial: the pinned sample IS this
        // order's recorded good output (receipt included), so no separate
        // output POST follows — trial == min(in-process sample, target) here.
        $output = WorkOrderOutput::query()->where('work_order_id', $workOrder->id)->sole();
        $this->assertSame($outputQuantity, (int) $workOrder->fresh()->quantity_good);
        $this->assertNotNull($output->trial_settled_at, 'QC acceptance must settle the startup trial.');
        $this->assertNotNull($output->production_receipt_movement_id, 'Settled trial output must create its finished-goods receipt before completion.');

        $this->actingAs($production)
            ->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/complete")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame('completed', $workOrder->fresh()->status->value);

        $inspection = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('work_order_output_id', $output->id)
            ->sole();
        $this->assertSame(InspectionStatus::Draft, $inspection->status, 'Completion must open outgoing QC without pre-approving it.');
        $inspection = $this->submitInspectionPassThroughApi($inspection, $qcMaker, $qcChecker);
        $this->assertSame(InspectionStatus::Passed, $inspection->fresh()->status);
        $this->assertSame($qcChecker->id, (int) $inspection->fresh()->reviewed_by);
        $this->actingAs($creator);

        $delivery = Delivery::query()
            ->where('sales_order_id', $order->id)
            ->whereHas('items', fn ($query) => $query
                ->where('sales_order_item_id', $line->id)
                ->where('inspection_id', $inspection->id))
            ->sole();
        $deliveryItem = DeliveryItem::query()->where('delivery_id', $delivery->id)->sole();
        $this->assertSame('scheduled', $delivery->status->value);
        $this->assertNull($delivery->driver_id);
        $this->assertSame($line->id, $deliveryItem->sales_order_item_id);
        $this->assertSame($inspection->id, $deliveryItem->inspection_id);
        $this->assertSame(0, bccomp((string) $deliveryItem->quantity, $quantity, 2));

        return [$delivery, $this->finishedGoodsItemFor($product), $this->finishedGoodsLocation()];
    }

    private function prepareProductionLedgerSetup(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $settings = app(SettingsService::class);
        $settings->set('accounting.accounts.inventory_raw_material_code', '1200', 'accounting');
        $settings->set('accounting.accounts.inventory_finished_goods_code', '1210', 'accounting');
        $settings->set('accounting.accounts.inventory_adjustment_code', '5010', 'accounting');
        $settings->set('accounting.accounts.material_consumption_code', '5010', 'accounting');
        $settings->set('accounting.accounts.inventory_delivery_transit_code', '1240', 'accounting');
        $settings->set('accounting.accounts.delivery_cogs_code', '5010', 'accounting');
    }

    private function createInspectionThroughApi(
        WorkOrder $workOrder,
        Product $product,
        User $maker,
        InspectionStage $stage,
        int $quantity,
    ): Inspection {
        $data = [
            'stage' => $stage->value,
            'product_id' => $product->hash_id,
            'batch_quantity' => $quantity,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->hash_id,
        ];
        $response = $this->actingAs($maker)
            ->postJson('/api/v1/quality/inspections', $data)
            ->assertCreated()
            ->assertJsonPath('data.status', InspectionStatus::Draft->value);

        return Inspection::query()->where('inspection_number', $response->json('data.inspection_number'))->firstOrFail();
    }

    private function submitInspectionPassThroughApi(Inspection $inspection, User $maker, ?User $checker = null): Inspection
    {
        $items = $inspection->measurements()
            ->orderBy('sample_index')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn ($measurement): string => $measurement->checklistKey())
            ->map(static fn ($group): array => ['id' => $group->first()->hash_id, 'result' => 'passed'])
            ->values()
            ->all();

        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;
        if ($inspection->usesChecklistCapture()) {
            $response = $this->actingAs($maker)->postJson($url.'/batch-result', [
                'expected_result_version' => (int) $inspection->result_version,
                'items' => $items,
                'coverage_confirmed' => true,
                'inspected_quantity' => (int) $inspection->sample_size,
                ...($inspection->sampling_basis === 'lot' ? [] : ['sample_defect_count' => 0]),
            ])->assertSuccessful();
        } else {
            $response = $this->actingAs($maker)->postJson($url.'/item-results', [
                'items' => $items,
                'submit' => $inspection->inspection_mode !== InspectionMode::LotChecklist,
            ])->assertSuccessful();

            if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
                $response = $this->postJson($url.'/batch-result', [
                    'coverage_confirmed' => true,
                    'inspected_quantity' => (int) $inspection->sample_size,
                    'sample_defect_count' => 0,
                    'result' => 'passed',
                    'critical_defect' => false,
                    'defects_removed_confirmed' => false,
                ])->assertSuccessful();
            }
        }

        $expectedStatus = $checker ? InspectionStatus::AwaitingReview : InspectionStatus::Passed;
        $response->assertJsonPath('data.status', $expectedStatus->value);
        $inspection->refresh();
        $this->assertSame($expectedStatus, $inspection->status);
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $maker->id,
        ]);

        if ($checker) {
            $this->assertSame(0, (int) $inspection->accepted_quantity, 'An unchecked inspection cannot authorize any output.');
            $this->actingAs($checker)
                ->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', [
                    'decision' => 'passed',
                    'expected_result_version' => (int) $inspection->result_version,
                ])
                ->assertSuccessful()
                ->assertJsonPath('data.status', InspectionStatus::Passed->value)
                ->assertJsonPath(
                    'data.accepted_quantity',
                    $inspection->stage === InspectionStage::Outgoing ? $inspection->batch_quantity : 0,
                );
            $inspection->refresh();
        }

        return $inspection;
    }
}
