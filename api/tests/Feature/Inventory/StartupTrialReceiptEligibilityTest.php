<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartupTrialReceiptEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrder $wo;

    private WorkOrderOutput $trial;

    private Item $item;

    private WarehouseLocation $location;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->wo = WorkOrder::factory()->create(['status' => 'in_progress', 'actual_start' => now()]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-INV-'.uniqid(), 'stage' => 'in_process', 'status' => 'draft',
            'product_id' => $this->wo->product_id, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id,
            'batch_quantity' => 100, 'sample_size' => 8, 'startup_trial_required' => true,
        ]);
        $this->trial = WorkOrderOutput::create([
            'work_order_id' => $this->wo->id, 'recorded_by' => $this->actor->id, 'recorded_at' => now(),
            'trial_inspection_id' => $inspection->id, 'trial_quantity' => 8, 'good_count' => 0, 'reject_count' => 0,
            'batch_code' => 'TRIAL-INVENTORY',
        ]);
        $this->item = Item::factory()->create(['item_type' => 'finished_good']);
        $this->location = WarehouseLocation::factory()->create();
    }

    public function test_direct_manual_receipt_cannot_receive_unsettled_trial_output(): void
    {
        $this->receiptDenied('work_order_output', $this->trial->id);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertSame(0, $this->trial->fresh()->good_count);
        $this->assertNull($this->trial->fresh()->trial_settled_at);
    }

    public function test_parent_work_order_reference_cannot_receive_pending_samples(): void
    {
        $this->receiptDenied('work_order', $this->wo->id);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_levels', 0);
    }

    public function test_classified_trial_and_ordinary_output_receipts_remain_available(): void
    {
        $this->trial->forceFill(['good_count' => 6, 'reject_count' => 2, 'trial_settled_at' => now()])->save();
        $movement = $this->receive('work_order_output', $this->trial->id, '6');
        $this->assertSame('6.000', $movement->quantity);
        $ordinary = WorkOrderOutput::create([
            'work_order_id' => $this->wo->id, 'recorded_by' => $this->actor->id, 'recorded_at' => now(),
            'good_count' => 3, 'reject_count' => 0, 'batch_code' => 'ORDINARY-INVENTORY',
        ]);
        $this->receive('work_order_output', $ordinary->id, '3');
        $this->assertSame('9.000', StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_crafted_outgoing_approval_cannot_release_unsettled_trial(): void
    {
        $reviewer = User::factory()->create();
        $inspection = Inspection::create([
            'inspection_number' => 'QC-OUT-'.uniqid(), 'stage' => 'outgoing', 'status' => 'passed',
            'product_id' => $this->wo->product_id, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id,
            'work_order_output_id' => $this->trial->id, 'batch_quantity' => 8, 'sample_size' => 8,
            'accepted_quantity' => 8, 'inspector_id' => $this->actor->id, 'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(), 'completed_at' => now(),
        ]);
        // Isolate the outgoing predicate from the independent root process gate.
        $this->trial->trialInspection->forceFill([
            'status' => 'passed', 'inspector_id' => $this->actor->id,
            'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
        ])->save();
        $this->assertFalse($inspection->fresh()->canReleaseOutgoing());
    }

    private function receive(string $type, int $id, string $quantity = '8'): StockMovement
    {
        return app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::ProductionReceipt, itemId: $this->item->id, quantity: $quantity,
            toLocationId: $this->location->id, unitCost: '2', referenceType: $type, referenceId: $id,
            createdBy: $this->actor->id, lotNumber: 'MANUAL-TRIAL-RECEIPT',
        ));
    }

    private function receiptDenied(string $type, int $id): void
    {
        try {
            $this->receive($type, $id);
            $this->fail('Unsettled startup trial must not enter finished-goods inventory through a direct receipt.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('trial', $error->getMessage());
        }
    }
}
