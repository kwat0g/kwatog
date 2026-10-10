<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Account;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\Quality\Services\QualityStockHoldService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

/**
 * 2026-08-08 — restock at disposition time (O2C twin of GRN acceptance).
 *
 * Disposing a customer-return line as 'restock'/'rework' transfers the goods
 * from quarantine into the declared warehouse location immediately. complete()
 * is idempotent and must not move those lines again.
 */
class CustomerReturnRestockOnDisposeTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Restock Scenario Customer', 'payment_terms_days' => 30]);
    }

    private function invoice(Customer $c, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-RS-' . substr(uniqid(), -5),
            'customer_id'    => $c->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);
    }

    /** An inspected customer RMA: 10 requested, 8 physically returned. */
    private function inspectedRma(User $by, Customer $c, Invoice $inv, Item $item, string $unitCost = '0.00', ?string $lotNumber = null): ReturnRequest
    {
        $product = Product::create([
            'part_number' => 'PT-RS-'.substr(uniqid(), -5),
            'name' => 'Restock returned product',
            'revenue_account_id' => Account::query()->where('code', '4010')->value('id'),
        ]);
        $item->update([
            'code' => $product->part_number,
            'item_type' => ItemType::FinishedGood,
        ]);
        $salesOrder = \App\Modules\CRM\Models\SalesOrder::factory()->create([
            'customer_id' => $c->id,
            'status' => 'invoiced',
            'created_by' => $by->id,
        ]);
        $inv->forceFill(['sales_order_id' => $salesOrder->id])->save();
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-RS-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $c->id,
            'invoice_id'  => $inv->id,
            'sales_order_id' => $salesOrder->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $invoiceLine = InvoiceItem::create([
            'invoice_id' => $inv->id,
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'description' => 'Returned stock',
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'total' => '1000.00',
        ]);

        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $product->id,
            'item_id'           => $item->id,
            'quantity'          => 10,
            'returned_quantity' => 8,
            'unit_price'        => '100.00',
            'total'             => '1000.00',
            'source_invoice_item_id' => $invoiceLine->id,
            'lot_number' => $lotNumber,
        ]);

        $invoiceLine->update(['product_id' => $product->id]);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: \App\Modules\Inventory\Enums\StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $quarantine->id,
            quantity: '8.000',
            unitCost: $unitCost,
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $by->id,
            lotNumber: $lotNumber,
        ));
        $line->update([
            'quarantine_location_id' => $quarantine->id,
            'quarantine_movement_id' => $movement->id,
            'quarantine_status' => 'held',
        ]);

        Inspection::create([
            'inspection_number' => 'QC-RS-'.substr(uniqid(), -8),
            'stage' => InspectionStage::CustomerReturn->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::ReturnRequest->value,
            'entity_id' => $rma->id,
            'batch_quantity' => 8,
            'sample_size' => 8,
            'accept_count' => 8,
            'reject_count' => 0,
            'defect_count' => 0,
            'inspector_id' => $by->id,
        ]);

        return $rma->load('items');
    }

    public function test_released_return_does_not_move_outgoing_qc_hold_between_lot_locations(): void
    {
        $disposer = $this->admin();
        $qcMaker = User::factory()->create();
        $qcReviewer = User::factory()->create();
        $mrbReviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'email' => 'mixed-lot-mrb+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
        $transferChecker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'email' => 'mixed-lot-checker+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
        $lotNumber = 'MIXED-LOT-'.strtoupper(substr(uniqid(), -6));

        $item = Item::factory()->create(['item_type' => ItemType::FinishedGood]);
        $product = Product::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'closed',
            'quantity_target' => 100,
            'quantity_produced' => 100,
            'quantity_good' => 100,
            'created_by' => $qcMaker->id,
        ]);
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id,
            'recorded_by' => $qcMaker->id,
            'recorded_at' => now(),
            'good_count' => 100,
            'reject_count' => 0,
            'batch_code' => $lotNumber,
        ]);
        Inspection::create([
            'inspection_number' => 'QC-MIXED-'.strtoupper(substr(uniqid(), -6)),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 100,
            'sample_size' => 20,
            'accepted_quantity' => 98,
            'inspector_id' => $qcMaker->id,
            'reviewed_by' => $qcReviewer->id,
            'reviewed_at' => now(),
            'completed_at' => now(),
        ]);

        $finishedGoods = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $originalLocation = WarehouseLocation::factory()->create(['zone_id' => $finishedGoods->id]);
        $receipt = StockMovement::create([
            'movement_type' => 'production_receipt',
            'item_id' => $item->id,
            'quantity' => '100.000',
            'unit_cost' => '5.00',
            'total_cost' => '500.00',
            'to_location_id' => $originalLocation->id,
            'reference_type' => 'work_order_output',
            'reference_id' => $output->id,
            'created_by' => $qcMaker->id,
            'lot_number' => $lotNumber,
        ]);
        $output->forceFill(['production_receipt_movement_id' => $receipt->id])->save();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $originalLocation->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.00',
        ]);

        StockMovement::create([
            'movement_type' => 'delivery',
            'item_id' => $item->id,
            'quantity' => '8.000',
            'unit_cost' => '5.00',
            'total_cost' => '40.00',
            'from_location_id' => $originalLocation->id,
            'reference_type' => 'delivery',
            'created_by' => $disposer->id,
            'lot_number' => $lotNumber,
        ]);
        StockLevel::query()->where('item_id', $item->id)
            ->where('location_id', $originalLocation->id)
            ->update(['quantity' => '92.000']);
        $holds = app(QualityStockHoldService::class);
        $this->assertSame('2.000', $holds->heldQuantity($item->id, $originalLocation->id, $lotNumber));

        $customer = $this->customer();
        $rma = $this->inspectedRma($disposer, $customer, $this->invoice($customer, $disposer), $item, '5.00', $lotNumber);
        $line = $rma->items->firstOrFail();
        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();

        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $quarantine = $line->fresh()->quarantineLocation()->with('zone')->firstOrFail();
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $target = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);
        $this->actingAs($mrbReviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", [
                'disposition' => 'use_as_is',
                'target_location_id' => $target->hash_id,
                'inspected_quantity' => '8.000',
                'evidence_reference' => 'MIXED-LOT-REINSPECTION',
                'recovery_passed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');

        $this->assertSame('2.000', $holds->heldQuantity($item->id, $originalLocation->id, $lotNumber));
        $this->assertSame('0.000', $holds->heldQuantity($item->id, $target->id, $lotNumber));

        $movementCount = StockMovement::query()->count();
        $transfer = $this->actingAs($mrbReviewer)
            ->postJson('/api/v1/inventory/transfer-orders', [
                'from_location_id' => $target->hash_id,
                'to_location_id' => $originalLocation->hash_id,
                'item_id' => $item->hash_id,
                'quantity' => '8.000',
                'reason' => 'Keep MRB-released stock traceable by bin.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.quantity', '8.000');
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $target->id)->value('quantity'));

        $this->actingAs($transferChecker)
            ->postJson("/api/v1/inventory/transfer-orders/{$transfer->json('data.id')}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', 'transferred');
        $this->assertSame($movementCount + 1, StockMovement::query()->count());
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $target->id)->value('quantity'));
        $this->assertSame('100.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $originalLocation->id)->value('quantity'));
        $this->assertSame('2.000', $holds->heldQuantity($item->id, $originalLocation->id, $lotNumber));
        $this->assertSame('0.000', $holds->heldQuantity($item->id, $target->id, $lotNumber));
    }

    public function test_dispose_restock_without_a_location_opens_mrb_and_keeps_stock_quarantined(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $customer = $this->customer();
        $rma   = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();

        $line = $rma->items->firstOrFail()->fresh();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('disposed', $rma->fresh()->disposition_status);
        $this->assertSame('held', $mrb->status->value);
        $this->assertNull($line->quarantine_release_movement_id);
        $this->assertSame('held', $line->quarantine_status);
        $this->assertSame('0.000', (string) $line->stock_movement_quantity);
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $line->quarantine_location_id)->value('quantity'));
    }

    public function test_dispose_restock_does_not_release_stock_before_mrb_review(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $customer = $this->customer();
        $rma   = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);
        $line = $rma->items->firstOrFail();
        $beforeMovementCount = StockMovement::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $line->hash_id,
                    'disposition' => 'restock',
                    'notes'       => 'Passed inspection — restockable',
                ]],
            ])
            ->assertOk();

        $rma->refresh();
        $line->refresh();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('held', $mrb->status->value);
        $this->assertNull($line->quarantine_release_movement_id);
        $this->assertSame('held', $line->quarantine_status);
        $this->assertSame('0.000', (string) $line->stock_movement_quantity);
        $this->assertSame($beforeMovementCount, StockMovement::query()->count());
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $line->quarantine_location_id)->value('quantity'));

        // The credit note is staged independently of the quarantined stock.
        $this->assertNotNull($rma->credit_note_id);
        $this->assertSame('draft', $rma->fresh()->creditNote->status->value);

        // The API surfaces the pending MRB and does not claim the stock moved.
        $this->actingAs($admin)
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.moved_quantity', null)
            ->assertJsonPath('data.items.0.mrb.mrb_number', $mrb->mrb_number)
            ->assertJsonPath('data.items.0.mrb.status', 'held');
    }

    public function test_customer_restock_waits_for_independent_mrb_release_with_reinspection_evidence(): void
    {
        $disposer = $this->admin();
        $item = Item::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($disposer, $customer, $this->invoice($customer, $disposer), $item);
        $line = $rma->items->firstOrFail();
        $quarantineId = (int) $line->quarantine_location_id;
        $quarantine = WarehouseLocation::query()->with('zone')->findOrFail($quarantineId);
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $target = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);

        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();

        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('held', $mrb->status->value);
        $this->assertSame((int) $line->item_id, (int) $mrb->item_id);
        $this->assertSame('8.000', (string) $mrb->quantity);
        $this->assertSame($disposer->id, (int) $mrb->held_by);
        $this->assertSame($line->quarantine_movement_id, $mrb->hold_movement_id);
        $this->assertNull($mrb->source_location_id, 'A customer return has no internal source location.');
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $quarantineId)->value('quantity'));
        $this->assertFalse(StockLevel::query()->where('item_id', $item->id)->where('location_id', $target->id)->exists());

        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every customer-return restock or rework line needs a completed MRB disposition before the RMA can complete.');
        $this->assertSame('inspected', $rma->fresh()->status->value);

        $payload = [
            'disposition' => 'use_as_is',
            'target_location_id' => $target->hash_id,
            'inspected_quantity' => '8.000',
            'evidence_reference' => 'RMA-REINSPECTION-8',
            'recovery_passed' => true,
        ];
        $this->actingAs($disposer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A different user must release an MRB hold than the one who placed it.');
        $this->actingAs($disposer)
            ->getJson("/api/v1/inventory/mrb/{$mrb->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_release', false)
            ->assertJsonPath('data.actions.can_release_good_stock', false);
        $this->assertSame('held', $mrb->fresh()->status->value);

        $qualityMaker = $this->admin();
        Inspection::query()
            ->where('entity_type', InspectionEntityType::ReturnRequest->value)
            ->where('entity_id', $rma->id)
            ->where('stage', InspectionStage::CustomerReturn->value)
            ->update(['inspector_id' => $qualityMaker->id]);
        $this->actingAs($qualityMaker)
            ->getJson("/api/v1/inventory/mrb/{$mrb->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_release', true)
            ->assertJsonPath('data.actions.can_release_good_stock', false);
        $this->actingAs($qualityMaker)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Good-stock release requires a reviewer independent of every customer-return inspection maker.');
        $this->assertSame('held', $mrb->fresh()->status->value);

        $reviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'email' => 'mrb-reviewer+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
        $this->actingAs($reviewer)
            ->getJson('/api/v1/inventory/mrb?status=held')
            ->assertOk()
            ->assertJsonFragment(['mrb_number' => $mrb->mrb_number]);
        $this->actingAs($reviewer)
            ->getJson("/api/v1/inventory/mrb/{$mrb->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_release', true)
            ->assertJsonPath('data.actions.can_release_good_stock', true);
        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", array_replace($payload, [
                'inspected_quantity' => '7.000',
            ]))
            ->assertUnprocessable();
        $this->assertSame('held', $mrb->fresh()->status->value);
        $this->assertNull($mrb->fresh()->release_movement_id);

        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'released');

        $movementCountAfterRelease = StockMovement::query()->count();
        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", $payload)
            ->assertUnprocessable();
        $this->assertSame($movementCountAfterRelease, StockMovement::query()->count());

        $mrb->refresh();
        $line->refresh();
        $this->assertNotNull($mrb->release_movement_id);
        $this->assertSame('released', $line->quarantine_status);
        $this->assertSame($mrb->release_movement_id, $line->quarantine_release_movement_id);
        $this->assertSame('8.000', (string) $line->stock_movement_quantity);
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $quarantineId)->value('quantity'));
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $target->id)->value('quantity'));
        $mrbAudit = AuditLog::query()
            ->where('model_type', $mrb->getMorphClass())
            ->where('model_id', $mrb->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(['created', 'updated'], $mrbAudit->pluck('action')->all());
        $this->assertSame($disposer->id, (int) $mrbAudit[0]->user_id);
        $this->assertSame($reviewer->id, (int) $mrbAudit[1]->user_id);

        $movementCount = StockMovement::query()->count();
        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame($movementCount, StockMovement::query()->count(), 'RMA completion must not release the MRB stock twice.');
    }

    public function test_complete_rejects_a_movement_stamp_while_the_linked_mrb_is_still_held(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);
        $line = $rma->items->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();

        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $movementCount = StockMovement::query()->count();
        // Simulate an inconsistent legacy/imported stamp; the MRB and stock remain held.
        $line->forceFill(['stock_movement_quantity' => '8.000'])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every customer-return restock or rework line needs a completed MRB disposition before the RMA can complete.');

        $this->assertSame('inspected', $rma->fresh()->status->value);
        $this->assertSame('held', $mrb->fresh()->status->value);
        $this->assertNull($mrb->fresh()->release_movement_id);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $line->quarantine_location_id)->value('quantity'));
    }

    public function test_stock_movement_service_rejects_adjustment_out_while_customer_return_mrb_is_pending(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);
        $line = $rma->items->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $beforeMovementCount = StockMovement::query()->count();

        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::AdjustmentOut,
                itemId: $item->id,
                fromLocationId: (int) $line->quarantine_location_id,
                quantity: '8.000',
                createdBy: $admin->id,
            ));
        } catch (BusinessRuleException $exception) {
            $this->assertSame(
                'Customer-return stock held in quarantine cannot be adjusted; complete its RMA or MRB disposition first.',
                $exception->getMessage(),
            );
            $this->assertSame($beforeMovementCount, StockMovement::query()->count());
            $this->assertSame('held', $mrb->fresh()->status->value);
            $this->assertSame('8.000', (string) StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', $line->quarantine_location_id)
                ->value('quantity'));

            return;
        }

        $this->fail('A stock adjustment must not remove customer-return stock held by a pending MRB.');
    }

    public function test_stock_adjustment_cannot_queue_a_pending_request_for_held_customer_return_stock(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item, '25.00');
        $line = $rma->items->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 100);
        $beforeMovementCount = StockMovement::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/v1/inventory/stock-adjustments', [
                'item_id' => $item->hash_id,
                'location_id' => $line->quarantineLocation->hash_id,
                'direction' => 'out',
                'quantity' => '8.000',
                'reason' => 'Remove quarantined customer-return stock',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Customer-return stock held in quarantine cannot be adjusted; complete its RMA or MRB disposition first.');

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame($beforeMovementCount, StockMovement::query()->count());
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $line->quarantine_location_id)
            ->value('quantity'));
    }

    public function test_stock_movement_cannot_skip_the_linked_mrb_release_for_customer_return_stock(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $destination = WarehouseLocation::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);
        $line = $rma->items->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $beforeMovementCount = StockMovement::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/v1/inventory/stock-adjustments', [
                'item_id' => $item->hash_id,
                'location_id' => $line->quarantineLocation->hash_id,
                'direction' => 'out',
                'quantity' => '8.000',
                'reason' => 'Remove quarantined customer-return stock',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Customer-return stock held in quarantine cannot be adjusted; complete its RMA or MRB disposition first.');

        $this->assertSame($beforeMovementCount, StockMovement::query()->count());
        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame('held', $mrb->fresh()->status->value);
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $line->quarantine_location_id)->value('quantity'));

        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::Transfer,
                itemId: $item->id,
                fromLocationId: (int) $line->quarantine_location_id,
                toLocationId: (int) $destination->id,
                quantity: '8.000',
                referenceType: 'return_request',
                referenceId: $rma->id,
                createdBy: $admin->id,
            ));
            $this->fail('A stock transfer must not skip the linked MRB release.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame(
                'Quarantine stock can leave only through the authorized MRB release workflow.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($beforeMovementCount, StockMovement::query()->count());
        $this->assertSame('held', $mrb->fresh()->status->value);
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $line->quarantine_location_id)
            ->value('quantity'));
        $this->assertFalse(StockLevel::query()->where('item_id', $item->id)->where('location_id', $destination->id)->exists());
        $this->assertNull($line->fresh()->quarantine_release_movement_id);
    }

    public function test_complete_waits_for_mrb_release_and_never_moves_the_return_twice(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $customer = $this->customer();
        $rma = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);
        $line = $rma->items->firstOrFail();
        $quarantine = WarehouseLocation::query()->with('zone')->findOrFail($line->quarantine_location_id);
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $target = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertOk();

        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every customer-return restock or rework line needs a completed MRB disposition before the RMA can complete.');

        $reviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'email' => 'mrb-completer+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", [
                'disposition' => 'use_as_is',
                'target_location_id' => $target->hash_id,
                'inspected_quantity' => '8.000',
                'evidence_reference' => 'RMA-REINSPECTION-COMPLETE',
                'recovery_passed' => true,
            ])
            ->assertOk();

        $movementCountAfterRelease = StockMovement::query()->count();
        $line->refresh();
        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::Transfer,
                itemId: $item->id,
                fromLocationId: (int) $line->quarantine_location_id,
                toLocationId: (int) $target->id,
                quantity: '8.000',
                referenceType: 'return_request',
                referenceId: $rma->id,
                createdBy: $admin->id,
            ));
            $this->fail('An RMA reference cannot release MRB-held stock without the MRB workflow.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame(
                'Quarantine stock can leave only through the authorized MRB release workflow.',
                $exception->getMessage(),
            );
        }

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame(ReturnRequestStatus::Completed, $rma->fresh()->status);
        $this->assertSame($movementCountAfterRelease, StockMovement::query()->count());
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $line->quarantine_location_id)
            ->value('quantity'));
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $target->id)
            ->value('quantity'));
    }

    public function test_dispose_scrap_needs_no_location_and_creates_no_movement(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $customer = $this->customer();
        $rma   = $this->inspectedRma($admin, $customer, $this->invoice($customer, $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'scrap',
                ]],
            ])
            ->assertOk();

        $this->assertSame('disposed', $rma->fresh()->disposition_status);
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->where('movement_type', StockMovementType::AdjustmentIn->value)
                ->count(),
            'Quarantine receipt is recorded before a scrap disposition.',
        );
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->where('movement_type', StockMovementType::Scrap->value)
            ->count());
    }

    public function test_dispose_blocks_imported_received_customer_return_without_inventory_mapping(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $location = WarehouseLocation::factory()->create();
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-IMPORT-' . substr(uniqid(), -5),
            'type' => 'customer_return',
            'status' => ReturnRequestStatus::Inspected,
            'customer_id' => $customer->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);
        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'quantity' => '10.000',
            'returned_quantity' => '8.000',
            'receipt_recorded' => true,
            'unit_price' => '100.00',
            'total' => '800.00',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id' => $line->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id' => $location->hash_id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'A received customer-return line needs a finished-goods inventory item before disposition.');

        $this->assertSame(ReturnRequestStatus::Inspected, $rma->fresh()->status);
        $this->assertNull($rma->fresh()->disposition_status);
        $this->assertNull($rma->fresh()->credit_note_id);
        $this->assertNull($line->fresh()->disposition);
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->count());
    }
}
