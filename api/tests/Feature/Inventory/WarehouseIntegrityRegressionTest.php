<?php

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Requests\StoreMrbRequest;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\PickingListService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Regression coverage for warehouse lot and custody integrity. */
class WarehouseIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([StockMovementCompleted::class]);
    }

    private function receipt(Item $item, WarehouseLocation $bin, string $qty, ?string $lot): StockMovement
    {
        return app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::AdjustmentIn, itemId: $item->id, toLocationId: $bin->id, quantity: $qty, unitCost: '100.00', lotNumber: $lot));
    }

    public function test_count_reconciles_selected_lot_and_reports_posted_value(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $counter = User::factory()->create();
        $checker = User::factory()->create();
        $finisher = User::factory()->create();
        $this->receipt($item, $bin, '5.000', 'A');
        $this->receipt($item, $bin, '5.000', 'B');
        $session = StockCountSession::create(['session_number' => 'SC-AUDIT-001', 'title' => 'Audit', 'scope' => 'zone', 'zone_id' => $bin->zone_id, 'status' => 'in_progress', 'created_by' => $counter->id, 'frozen_at' => now()]);
        $line = StockCountItem::create(['session_id' => $session->id, 'location_id' => $bin->id, 'item_id' => $item->id, 'system_quantity' => '10.000', 'status' => 'pending']);
        app(StockCountService::class)->recordCount($line->id, ['counted_quantity' => '8.000', 'lot_number' => 'B'], $counter);
        app(StockCountService::class)->approveVariance($line->id, $checker);
        app(StockCountService::class)->completeSession($session->id, $finisher);
        $movement = StockMovement::where('reference_type', 'stock_count_session')->where('reference_id', $session->id)->sole();
        $this->assertSame('B', $line->fresh()->lot_number);
        $this->assertSame('B', $movement->lot_number);
        $this->assertSame('200.00', (string) $session->fresh()->variance_value);
        $this->assertSame('200.00', (string) $movement->total_cost);
    }

    public function test_multilot_count_allocations_conserve_quantity_and_value(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $counter = User::factory()->create();
        $checker = User::factory()->create();
        $finisher = User::factory()->create();
        $this->receipt($item, $bin, '5.000', 'A');
        $this->receipt($item, $bin, '5.000', 'B');
        $session = StockCountSession::create(['session_number' => 'SC-MULTI-001', 'title' => 'Multi lot', 'scope' => 'zone', 'zone_id' => $bin->zone_id, 'status' => 'in_progress', 'created_by' => $counter->id, 'frozen_at' => now()]);
        $line = StockCountItem::create(['session_id' => $session->id, 'location_id' => $bin->id, 'item_id' => $item->id, 'system_quantity' => '10.000', 'status' => 'pending']);
        app(StockCountService::class)->recordCount($line->id, ['counted_quantity' => '7.000', 'variance_allocations' => [['lot_number' => 'A', 'quantity' => '1.000'], ['lot_number' => 'B', 'quantity' => '2.000']]], $counter);
        app(StockCountService::class)->approveVariance($line->id, $checker);
        app(StockCountService::class)->completeSession($session->id, $finisher);
        $lots = collect(app(StockLocationSummaryService::class)->lots($item->id, $bin->id))->keyBy('lot_number');
        $this->assertSame('4.000', $lots['A']['net']);
        $this->assertSame('3.000', $lots['B']['net']);
        $this->assertSame('300.00', (string) $session->fresh()->variance_value);
        $this->assertSame(2, StockMovement::where('reference_type', 'stock_count_session')->where('reference_id', $session->id)->count());
    }

    public function test_count_cannot_use_untracked_allocation_for_known_lots(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $counter = User::factory()->create();
        $checker = User::factory()->create();
        $finisher = User::factory()->create();
        $this->receipt($item, $bin, '5.000', 'A');
        $this->receipt($item, $bin, '5.000', 'B');
        $session = StockCountSession::create(['session_number' => 'SC-NULL-001', 'title' => 'Null lot', 'scope' => 'zone', 'zone_id' => $bin->zone_id, 'status' => 'in_progress', 'created_by' => $counter->id, 'frozen_at' => now()]);
        $line = StockCountItem::create(['session_id' => $session->id, 'location_id' => $bin->id, 'item_id' => $item->id, 'system_quantity' => '10.000', 'status' => 'pending']);
        app(StockCountService::class)->recordCount($line->id, ['counted_quantity' => '8.000', 'variance_allocations' => [['lot_number' => null, 'quantity' => '2.000']]], $counter);
        app(StockCountService::class)->approveVariance($line->id, $checker);
        try {
            app(StockCountService::class)->completeSession($session->id, $finisher);
            $this->fail('Known stock requires explicit lot allocation.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('lot', strtolower($error->getMessage()));
        }
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->where('location_id', $bin->id)->sole()->quantity);
        $this->assertSame('in_progress', $session->fresh()->status->value);
    }

    public function test_issued_picking_preserves_original_lot(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $user = User::factory()->create();
        $this->receipt($item, $bin, '5.000', 'A');
        $this->receipt($item, $bin, '5.000', 'B');
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-AUDIT-001', 'issued_date' => today(), 'issued_by' => $user->id, 'created_by' => $user->id, 'status' => 'issued', 'total_value' => '500.00']);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::MaterialIssue, itemId: $item->id, fromLocationId: $bin->id, quantity: '5.000', lotNumber: 'A', referenceType: 'material_issue_slip', referenceId: $slip->id));
        MaterialIssueSlipItem::create(['material_issue_slip_id' => $slip->id, 'item_id' => $item->id, 'location_id' => $bin->id, 'quantity_issued' => '5.000', 'unit_cost' => '100.00', 'total_cost' => '500.00', 'stock_movement_id' => $movement->id, 'lot_number' => 'A']);
        $picking = app(PickingListService::class)->generateForMis($slip->id);
        $this->assertSame('A', $movement->lot_number);
        $this->assertSame('A', $picking['lines'][0]['issued_source']['lot_number']);
        $this->assertSame([], $picking['lines'][0]['suggestions']);
    }

    public function test_public_mrb_retains_requested_lot(): void
    {
        $rules = (new StoreMrbRequest)->rules();
        $this->assertArrayHasKey('lot_number', $rules);
        $this->assertArrayNotHasKey('expiry_date', $rules);
    }

    public function test_handed_over_issue_cannot_cancel_without_actual_return(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $user = User::factory()->create();
        $this->receipt($item, $bin, '5.000', 'A');
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-AUDIT-002', 'issued_date' => today(), 'issued_by' => $user->id, 'created_by' => $user->id, 'status' => 'issued', 'total_value' => '500.00']);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::MaterialIssue, itemId: $item->id, fromLocationId: $bin->id, quantity: '5.000', lotNumber: 'A', referenceType: 'material_issue_slip', referenceId: $slip->id));
        MaterialIssueSlipItem::create(['material_issue_slip_id' => $slip->id, 'item_id' => $item->id, 'location_id' => $bin->id, 'quantity_issued' => '5.000', 'unit_cost' => '100.00', 'total_cost' => '500.00', 'stock_movement_id' => $movement->id, 'lot_number' => 'A']);
        $employee = Employee::factory()->create();
        MaterialHandover::create(['stock_movement_id' => $movement->id, 'received_by_employee_id' => $employee->id, 'recipient_name' => $employee->full_name, 'recorded_by' => $user->id, 'quantity' => '5.000', 'handed_over_at' => now()]);
        try {
            app(MaterialIssueService::class)->cancel($slip, $user);
            $this->fail('Handed-over material requires an actual return before cancellation.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('return',strtolower($e->getMessage()));
        }
        $this->assertSame('0.000',(string) StockLevel::where('item_id',$item->id)->where('location_id',$bin->id)->sole()->quantity);
        $this->assertSame('issued',$slip->fresh()->status->value);
    }
}
