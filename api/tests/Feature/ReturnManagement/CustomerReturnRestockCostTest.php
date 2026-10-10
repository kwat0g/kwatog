<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-03 — customer-return restock must inherit the destination location's WAC.
 *
 * Customer stock returns enter good stock only through the separate MRB
 * release. These checks keep transfer cost and destination WAC consistent.
 */
class CustomerReturnRestockCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // REC-13 — completing a customer return posts a credit note to the GL.
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name'               => 'Restock Cost Customer',
            'payment_terms_days' => 30,
        ]);
    }

    private function makeInvoice(Customer $customer, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-T-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '800.00',
            'vat_amount'     => '96.00',
            'total_amount'   => '896.00',
            'balance'        => '896.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);
    }

    private function makeInspectedRma(
        User $by,
        Customer $customer,
        Invoice $invoice,
        int $itemId,
        int $returnedQuantity,
        string $quarantineUnitCost = '0.00',
    ): ReturnRequest {
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'invoice_id'  => $invoice->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $invoiceLine = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => Product::create([
                'part_number' => Item::query()->findOrFail($itemId)->code,
                'name' => 'Returned stock',
            ])->id,
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'description' => 'Returned stock',
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'total' => '1000.00',
        ]);

        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $itemId,
            'quantity'          => 10,
            'returned_quantity' => $returnedQuantity,
            'unit_price'        => 100.00,
            'total'             => (float) $returnedQuantity * 100.00,
            'reason'            => 'defective',
            'condition'         => 'damaged',
            'source_invoice_item_id' => $invoiceLine->id,
        ]);
        $product = Product::query()->findOrFail($invoiceLine->product_id);
        Item::query()->whereKey($itemId)->update([
            'code' => $product->part_number,
            'item_type' => ItemType::FinishedGood->value,
        ]);
        $line->update(['product_id' => $product->id]);

        $quarantineZone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $itemId,
            toLocationId: $quarantine->id,
            quantity: (string) $returnedQuantity,
            unitCost: $quarantineUnitCost,
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $by->id,
        ));
        $line->update([
            'quarantine_location_id' => $quarantine->id,
            'quarantine_movement_id' => $movement->id,
            'quarantine_status' => 'held',
        ]);

        Inspection::create([
            'inspection_number' => 'QC-RC-'.substr(uniqid(), -8),
            'stage' => InspectionStage::CustomerReturn->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::ReturnRequest->value,
            'entity_id' => $rma->id,
            'batch_quantity' => $returnedQuantity,
            'sample_size' => $returnedQuantity,
            'accept_count' => $returnedQuantity,
            'reject_count' => 0,
            'defect_count' => 0,
            'inspector_id' => $by->id,
        ]);

        return $rma->load('items');
    }

    private function goodLocation(ReturnRequestItem $line): WarehouseLocation
    {
        $quarantine = WarehouseLocation::query()->with('zone')->findOrFail($line->quarantine_location_id);
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);

        return WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
    }

    private function releaseCustomerReturn(ReturnRequest $rma, User $by, int $targetLocationId): MaterialReviewRecord
    {
        $line = $rma->items->firstOrFail();
        app(ReturnRequestService::class)->dispose($rma, [[
            'item_id' => $line->hash_id,
            'disposition' => 'restock',
        ]], $by);

        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('held', $mrb->status->value);
        $this->assertNull($mrb->release_movement_id);

        $reviewer = User::factory()->create();
        return app(QuarantineService::class)->release(
            $mrb,
            'use_as_is',
            $reviewer,
            $targetLocationId,
            null,
            [
                'inspected_quantity' => (string) $mrb->quantity,
                'evidence_reference' => 'INDEPENDENT-REINSPECTION-'.$rma->rma_number,
                'recovery_passed' => true,
            ],
        );
    }

    public function test_customer_return_restock_inherits_destination_wac(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $by);
        $item = Item::factory()->create();
        $rma = $this->makeInspectedRma($by, $customer, $invoice, $item->id, 8, '100.00');
        $location = $this->goodLocation($rma->items->firstOrFail());

        // Seed 5 units @ 100.00 into the destination location.
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $location->id,
            quantity: '5',
            unitCost: '100.00',
            referenceType: 'opening',
            createdBy: $by->id,
        ));

        $mrb = $this->releaseCustomerReturn($rma, $by, $location->id);
        $movementCount = StockMovement::query()->count();
        $completed = app(ReturnRequestService::class)->complete($rma, $by, $location->id);

        $this->assertSame(ReturnRequestStatus::Completed, $completed->status);
        $this->assertSame($movementCount, StockMovement::query()->count(), 'Completion must not move MRB-released stock again.');

        $movement = StockMovement::query()
            ->where('reference_type', 'material_review_record')
            ->where('reference_id', $mrb->id)
            ->where('to_location_id', $location->id)
            ->firstOrFail();

        $this->assertSame(StockMovementType::Transfer, $movement->movement_type);
        $this->assertSame('100.0000', (string) $movement->unit_cost, 'Restock must inherit the destination WAC');
        $this->assertSame('800.00', (string) $movement->total_cost, '8 units × 100.00');

        $level = StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $this->assertSame('13.000', (string) $level->quantity);
        $this->assertSame('100.0000', (string) $level->weighted_avg_cost, 'Value-neutral blend must not move the WAC');
    }

    public function test_customer_return_restock_into_empty_location_costs_zero(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $by);
        $item = Item::factory()->create();
        $rma = $this->makeInspectedRma($by, $customer, $invoice, $item->id, 4);
        $freshLocation = $this->goodLocation($rma->items->firstOrFail());

        $mrb = $this->releaseCustomerReturn($rma, $by, $freshLocation->id);
        $movementCount = StockMovement::query()->count();
        $completed = app(ReturnRequestService::class)->complete($rma, $by, $freshLocation->id);

        $this->assertSame(ReturnRequestStatus::Completed, $completed->status);
        $this->assertSame($movementCount, StockMovement::query()->count(), 'Completion must not move MRB-released stock again.');

        $movement = StockMovement::query()
            ->where('reference_type', 'material_review_record')
            ->where('reference_id', $mrb->id)
            ->where('to_location_id', $freshLocation->id)
            ->firstOrFail();

        $this->assertSame(StockMovementType::Transfer, $movement->movement_type);
        $this->assertSame('0.0000', (string) $movement->unit_cost, 'A zero-cost quarantine receipt stays zero through MRB release');
        $this->assertSame('0.00', (string) $movement->total_cost);

        $level = StockLevel::where('item_id', $item->id)->where('location_id', $freshLocation->id)->firstOrFail();
        $this->assertSame('4.000', (string) $level->quantity);
        $this->assertSame('0.0000', (string) $level->weighted_avg_cost);
    }
}
