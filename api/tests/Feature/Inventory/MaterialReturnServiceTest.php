<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\MaterialReturnService;
use App\Modules\Inventory\Exceptions\MaterialReturnConflictException;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaterialReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->user = User::factory()->create();
        $this->product = Product::factory()->create();
        $this->item = Item::factory()->create([
            'unit_of_measure' => 'kg',
            'standard_cost' => '12.3400',
        ]);
        $this->location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '12.3400',
        ]);
    }

    public function test_auto_issue_return_replays_after_response_loss_and_cannot_cross_recorded_production_floor(): void
    {
        $workOrder = $this->startBomWorkOrder();
        $source = StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->where('reference_type', 'work_order')
            ->where('reference_id', $workOrder->id)
            ->firstOrFail();

        app(WorkOrderOutputService::class)->record($workOrder, [
            'good_count' => 5,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'material-return-auto-output');

        $returns = app(MaterialReturnService::class);
        $request = [
            'quantity_returned' => '5.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Unused quantity after the first production run',
            'idempotency_key' => 'auto-return-replay-001',
        ];
        $posted = $returns->returnUnused($source, $request, $this->user);
        // Simulate the client losing the successful response and replaying the
        // exact request. It must resolve to the original stock-ledger row.
        $replayed = $returns->returnUnused($source, $request, $this->user);

        $this->assertSame($posted->id, $replayed->id);
        $this->assertSame(1, StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $source->id)
            ->count());
        $this->assertSame('5.000', (string) $posted->quantity);
        $this->assertSame((string) $source->unit_cost, (string) $posted->unit_cost);
        $this->assertSame($source->from_location_id, $posted->to_location_id);
        $this->assertSame('95.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));

        $summary = $returns->options($source->fresh());
        $this->assertFalse($summary['eligible']);
        $this->assertSame('5.000', $summary['returned_quantity']);
        $this->assertSame('5.000', $summary['consumption_floor']);
        $this->assertStringContainsString('planned material usage floor', (string) $summary['message']);

        try {
            $returns->returnUnused($source, [
                'quantity_returned' => '0.001',
                'expected_returned_quantity' => '5.000',
                'reason' => 'Attempt to return below produced usage',
                'idempotency_key' => 'auto-return-over-floor-001',
            ], $this->user);
            $this->fail('Return must be blocked once the saved BOM usage floor has been reached.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('planned material usage floor', $e->getMessage());
        }

        $this->assertSame(1, StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $source->id)
            ->count());
    }

    public function test_manual_slip_returns_preserve_original_cost_and_lot_and_use_expected_returned_guard(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::Opening->value,
            'quantity' => '3.000',
            'unit_cost' => '12.3400',
            'total_cost' => '37.02',
            'lot_number' => 'LOT-MATERIAL-RETURN-001',
            'created_by' => $this->user->id,
            'created_at' => now()->subMinute(),
        ]);
        StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->update([
            'quantity' => '3.000',
            'weighted_avg_cost' => '12.3400',
        ]);

        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '3.000',
                'lot_number' => 'LOT-MATERIAL-RETURN-001',
            ]],
        ], $this->user);
        $line = $slip->items()->firstOrFail();
        $source = $line->stockMovement;

        $returns = app(MaterialReturnService::class);
        $first = $returns->returnUnused($source, [
            'quantity_returned' => '1.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return one unused lot unit',
            'idempotency_key' => 'manual-return-first-001',
        ], $this->user);

        $this->assertSame(StockMovementType::MaterialReturn, $first->movement_type);
        $this->assertSame('stock_movement', $first->reference_type);
        $this->assertSame($source->id, (int) $first->reference_id);
        $this->assertSame('12.3400', (string) $first->unit_cost);
        $this->assertSame('12.34', (string) $first->total_cost);
        $this->assertSame('LOT-MATERIAL-RETURN-001', $first->lot_number);
        $this->assertSame($this->location->id, $first->to_location_id);

        try {
            $returns->returnUnused($source, [
                'quantity_returned' => '1.000',
                'expected_returned_quantity' => '0.000',
                'reason' => 'Try with an outdated returned total',
                'idempotency_key' => 'manual-return-stale-001',
            ], $this->user);
            $this->fail('A stale expected-returned total must not post a second return.');
        } catch (MaterialReturnConflictException $e) {
            $this->assertStringContainsString('already has 1.000 returned', $e->getMessage());
        }

        $this->assertSame('1.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));

        $groups = app(\App\Modules\Production\Services\WorkOrderMaterialUsageService::class)
            ->groups($workOrder, includeReservations: false);
        $material = collect($groups)->firstWhere('item_id', $this->item->id);
        $this->assertSame('2.000', $material['manual_quantity_issued']);
        $this->assertSame('24.68', $material['manual_actual_cost']);
        $this->assertSame('1.000', $material['manual_returned_quantity']);
        $this->assertSame('0.000', (string) $workOrder->materials()->value('actual_quantity_issued'));
    }

    public function test_cancelling_a_partly_returned_manual_slip_posts_only_the_unreturned_remainder(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '3.000',
            ]],
        ], $this->user);
        $source = $slip->items()->firstOrFail()->stockMovement;

        app(MaterialReturnService::class)->returnUnused($source, [
            'quantity_returned' => '1.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return the initial unused material',
            'idempotency_key' => 'cancel-return-prior-001',
        ], $this->user);

        app(MaterialIssueService::class)->cancel($slip, $this->user);

        $returns = StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $source->id)
            ->orderBy('id')
            ->get();
        $this->assertSame([1.0, 2.0], $returns->map(fn (StockMovement $movement) => (float) $movement->quantity)->all());
        $this->assertSame('Cancelled', $slip->fresh()->status->label());
        $this->assertSame('100.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_split_fractional_returns_conserve_the_original_rounded_issue_value(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::Opening->value,
            'quantity' => '1.000',
            'unit_cost' => '5.0100',
            'total_cost' => '5.01',
            'lot_number' => 'LOT-PENNY-001',
            'created_by' => $this->user->id,
            'created_at' => now()->subMinute(),
        ]);
        StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->update([
            'quantity' => '1.000',
            'weighted_avg_cost' => '5.0100',
        ]);
        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '1.000',
                'lot_number' => 'LOT-PENNY-001',
            ]],
        ], $this->user);
        $source = $slip->items()->firstOrFail()->stockMovement;

        $returns = app(MaterialReturnService::class);
        $quantities = ['0.001', '0.001', '0.998'];
        $expected = ['0.000', '0.001', '0.002'];
        $movements = [];
        foreach ($quantities as $index => $quantity) {
            $movements[] = $returns->returnUnused($source, [
                'quantity_returned' => $quantity,
                'expected_returned_quantity' => $expected[$index],
                'reason' => 'Return fractional unused material',
                'idempotency_key' => 'penny-return-00'.($index + 1),
            ], $this->user);
        }

        $returnedCost = '0.00';
        $returnedQuantity = '0.000';
        foreach ($movements as $movement) {
            $returnedCost = bcadd($returnedCost, (string) $movement->total_cost, 2);
            $returnedQuantity = bcadd($returnedQuantity, (string) $movement->quantity, 3);
        }

        $this->assertSame('5.01', (string) $source->total_cost);
        $this->assertSame(['0.01', '0.00', '5.00'], array_map(fn (StockMovement $movement) => (string) $movement->total_cost, $movements));
        $this->assertSame('5.01', $returnedCost);
        $this->assertSame('1.000', $returnedQuantity);
        $this->assertSame('5.0100', (string) $movements[0]->unit_cost);

        $groups = app(\App\Modules\Production\Services\WorkOrderMaterialUsageService::class)
            ->groups($workOrder, includeReservations: false);
        $material = collect($groups)->firstWhere('item_id', $this->item->id);
        $this->assertSame('0.000', $material['manual_quantity_issued']);
        $this->assertSame('0.00', $material['manual_actual_cost']);
        $this->assertSame('5.01', $material['manual_returned_cost']);
    }

    public function test_split_return_weighted_average_uses_the_allocated_source_value(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::Opening->value,
            'quantity' => '1.000',
            'unit_cost' => '5.0050',
            'total_cost' => '5.01',
            'lot_number' => 'LOT-WAC-001',
            'created_by' => $this->user->id,
            'created_at' => now()->subMinute(),
        ]);
        StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->update([
            'quantity' => '1.000',
            'weighted_avg_cost' => '5.0050',
        ]);
        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '1.000',
                'lot_number' => 'LOT-WAC-001',
            ]],
        ], $this->user);
        $source = $slip->items()->firstOrFail()->stockMovement;

        $this->assertSame('5.0050', (string) $source->unit_cost);
        $this->assertSame('5.01', (string) $source->total_cost);
        $returns = app(MaterialReturnService::class);
        $first = $returns->returnUnused($source, [
            'quantity_returned' => '0.500',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return the first half of the unused material',
            'idempotency_key' => 'wac-return-half-001',
        ], $this->user);
        $level = StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->firstOrFail();

        $this->assertSame('2.51', (string) $first->total_cost);
        $this->assertSame('0.500', (string) $level->quantity);
        $this->assertSame('5.0200', (string) $level->weighted_avg_cost);
        $this->assertSame('5.0050', (string) $first->unit_cost, 'The posted return preserves unit-cost provenance while inventory value follows the allocated cents.');

        $last = $returns->returnUnused($source, [
            'quantity_returned' => '0.500',
            'expected_returned_quantity' => '0.500',
            'reason' => 'Return the remaining half of the unused material',
            'idempotency_key' => 'wac-return-half-002',
        ], $this->user);
        $level->refresh();

        $this->assertSame('2.50', (string) $last->total_cost);
        $this->assertSame('1.000', (string) $level->quantity);
        $this->assertSame('5.0100', (string) $level->weighted_avg_cost);
        $this->assertSame('5.01', (string) StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->sum('total_cost'));
    }

    public function test_return_rejects_non_issue_and_unowned_source_movements(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        $receipt = StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::ProductionReceipt,
            'quantity' => '1.000',
            'unit_cost' => '12.3400',
            'total_cost' => '12.34',
            'reference_type' => 'work_order',
            'reference_id' => $workOrder->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        $unownedIssue = StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => $this->location->id,
            'to_location_id' => null,
            'movement_type' => StockMovementType::MaterialIssue,
            'quantity' => '1.000',
            'unit_cost' => '12.3400',
            'total_cost' => '12.34',
            'reference_type' => 'work_order',
            'reference_id' => 999999,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $returns = app(MaterialReturnService::class);
        foreach ([$receipt, $unownedIssue] as $index => $source) {
            $this->assertFalse($returns->options($source)['eligible']);
            try {
                $returns->returnUnused($source, [
                    'quantity_returned' => '1.000',
                    'expected_returned_quantity' => '0.000',
                    'reason' => 'Reject an unowned movement source',
                    'idempotency_key' => 'unowned-material-return-00'.($index + 1),
                ], $this->user);
                $this->fail('Only an actual owned material issue source can be returned.');
            } catch (BusinessRuleException $e) {
                $expected = $index === 0 ? 'material-issue movement' : 'work order material plan';
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovementType::MaterialReturn->value)->count());
    }

    public function test_unlinked_issue_excess_error_names_the_source_balance(): void
    {
        $slip = app(MaterialIssueService::class)->create([
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '2.000',
            ]],
        ], $this->user);
        $source = $slip->items()->firstOrFail()->stockMovement;
        $returns = app(MaterialReturnService::class);
        $returns->returnUnused($source, [
            'quantity_returned' => '1.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return one unlinked issue unit',
            'idempotency_key' => 'unlinked-return-first-001',
        ], $this->user);

        try {
            $returns->returnUnused($source, [
                'quantity_returned' => '1.001',
                'expected_returned_quantity' => '1.000',
                'reason' => 'Try returning more than this issue balance',
                'idempotency_key' => 'unlinked-return-excess-001',
            ], $this->user);
            $this->fail('An unlinked slip cannot return beyond the source movement balance.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'The requested return exceeds the unreturned quantity on this original material-issue movement.',
                $e->getMessage(),
            );
        }

        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::MaterialReturn->value)->count());
    }

    public function test_excess_return_is_rejected_after_a_valid_partial_return(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        $source = $this->issueManual($workOrder, '3.000');
        $returns = app(MaterialReturnService::class);
        $returns->returnUnused($source, [
            'quantity_returned' => '2.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return two of three issued',
            'idempotency_key' => 'excess-return-valid-001',
        ], $this->user);

        try {
            $returns->returnUnused($source, [
                'quantity_returned' => '2.000',
                'expected_returned_quantity' => '2.000',
                'reason' => 'Try to return two when one remains',
                'idempotency_key' => 'excess-return-invalid-001',
            ], $this->user);
            $this->fail('The return amount must not exceed the original issue movement remainder.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('The requested return', $e->getMessage());
        }

        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::MaterialReturn->value)->count());
        $this->assertSame('99.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_receipt_is_blocked_when_the_original_source_location_becomes_blocked_or_frozen(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        $source = $this->issueManual($workOrder, '2.000');
        $this->location->forceFill(['is_blocked' => true])->save();

        try {
            app(MaterialReturnService::class)->returnUnused($source, [
                'quantity_returned' => '1.000',
                'expected_returned_quantity' => '0.000',
                'reason' => 'Attempt return into blocked location',
                'idempotency_key' => 'blocked-location-return-001',
            ], $this->user);
            $this->fail('Blocked locations must refuse material-return receipts.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('blocked for receiving', $e->getMessage());
        }

        $this->location->forceFill(['is_blocked' => false])->save();
        $session = StockCountSession::create([
            'session_number' => 'SC-MRET-'.substr(uniqid(), -6),
            'title' => 'Material return freeze regression',
            'scope' => 'full',
            'status' => \App\Modules\Inventory\Enums\StockCountSessionStatus::InProgress,
            'created_by' => $this->user->id,
        ]);
        StockCountItem::create([
            'session_id' => $session->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'system_quantity' => '98.000',
            'status' => 'pending',
        ]);

        try {
            app(MaterialReturnService::class)->returnUnused($source, [
                'quantity_returned' => '1.000',
                'expected_returned_quantity' => '0.000',
                'reason' => 'Attempt return while stock is frozen',
                'idempotency_key' => 'frozen-location-return-001',
            ], $this->user);
            $this->fail('A frozen stock-count location must refuse material-return receipts.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('frozen by stock count', $e->getMessage());
        }

        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovementType::MaterialReturn->value)->count());
        $this->assertSame('98.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_manual_no_bom_post_output_returns_keep_the_full_saved_material_floor(): void
    {
        $workOrder = $this->startBomWorkOrder('manual');
        $source = $this->issueManual($workOrder, '2.000');

        app(WorkOrderOutputService::class)->record($workOrder, [
            'good_count' => 1,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'manual-no-bom-return-output');

        $returns = app(MaterialReturnService::class);
        $summary = $returns->options($source);
        $this->assertSame('fixed_saved_plan', $summary['consumption_basis']);
        $this->assertSame('10.000', $summary['consumption_floor']);
        $this->assertSame('2.000', $summary['returnable_quantity']);

        $returns->returnUnused($source, [
            'quantity_returned' => '2.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return only manual plan excess',
            'idempotency_key' => 'manual-no-bom-excess-return-001',
        ], $this->user);
        $blocked = $returns->options($source->fresh());

        $this->assertFalse($blocked['eligible']);
        $this->assertSame('10.000', $blocked['consumption_floor']);
        $this->assertSame('No recipe: usage cannot be calculated for this manual/no-BOM plan. Contact Production to reconcile before returning more material.', $blocked['message']);
        try {
            $returns->returnUnused($source, [
                'quantity_returned' => '0.001',
                'expected_returned_quantity' => '2.000',
                'reason' => 'Do not undercut conservative floor',
                'idempotency_key' => 'manual-no-bom-below-floor-001',
            ], $this->user);
            $this->fail('No-BOM history cannot lower the stated material floor.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('No recipe: usage cannot be calculated', $e->getMessage());
            $this->assertStringContainsString('Contact Production to reconcile', $e->getMessage());
        }
    }

    private function startBomWorkOrder(string $planSource = 'bom'): WorkOrder
    {
        $workOrder = $this->confirmedWorkOrder(WorkOrderStatus::Planned, $planSource);
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create([
            'mold_code' => 'MRET-'.substr(uniqid(), -5),
            'name' => 'Material return mold',
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 25,
            'output_rate_per_hour' => 120,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);
        $service = app(WorkOrderService::class);

        return $service->start(
            $service->confirm($workOrder, $machine->id, $mold->id),
            $this->user->id,
        );
    }

    private function confirmedWorkOrder(WorkOrderStatus $status = WorkOrderStatus::Confirmed, string $planSource = 'bom'): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'status' => $status->value,
            'quantity_target' => 10,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'work_order_class' => 'standard',
            'material_plan_source' => $planSource,
            'created_by' => $this->user->id,
        ]);
        WorkOrderMaterial::create([
            'work_order_id' => $workOrder->id,
            'item_id' => $this->item->id,
            'bom_quantity' => '10.000',
            'standard_unit_cost' => '12.3400',
            'standard_cost' => '123.40',
            'actual_quantity_issued' => '0.000',
            'actual_cost' => '0.00',
            'cost_variance' => '-123.40',
            'variance' => '-10.000',
        ]);

        return $workOrder;
    }

    private function issueManual(WorkOrder $workOrder, string $quantity): StockMovement
    {
        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => $quantity,
            ]],
        ], $this->user);

        return $slip->items()->firstOrFail()->stockMovement;
    }
}
