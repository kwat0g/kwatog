<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
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
 * ReturnRequestService::complete() restocks a customer return without a unit
 * cost. StockMovementService used to reject that with "A unit cost is
 * required", so the whole restock path threw at runtime. A pure receipt with
 * a null cost now inherits the destination level's weighted-average cost
 * (value-neutral blend); a fresh, empty location costs 0.00.
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

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $itemId,
            'quantity'          => 10,
            'returned_quantity' => $returnedQuantity,
            'unit_price'        => 100.00,
            'total'             => (float) $returnedQuantity * 100.00,
            'reason'            => 'defective',
            'condition'         => 'damaged',
            // complete() now restocks only lines dispose() marked as kept, and
            // refuses to run at all until every line has been dispositioned.
            'disposition'       => 'restock',
        ]);

        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        return $rma->load('items');
    }

    public function test_customer_return_restock_inherits_destination_wac(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $by);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();

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

        $rma = $this->makeInspectedRma($by, $customer, $invoice, $item->id, 8);

        $completed = app(ReturnRequestService::class)->complete($rma, $by, $location->id);

        $this->assertSame(ReturnRequestStatus::Completed, $completed->status);

        $movement = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();

        $this->assertSame(StockMovementType::AdjustmentIn, $movement->movement_type);
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
        $freshLocation = WarehouseLocation::factory()->create();

        $rma = $this->makeInspectedRma($by, $customer, $invoice, $item->id, 4);

        $completed = app(ReturnRequestService::class)->complete($rma, $by, $freshLocation->id);

        $this->assertSame(ReturnRequestStatus::Completed, $completed->status);

        $movement = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();

        $this->assertSame(StockMovementType::AdjustmentIn, $movement->movement_type);
        $this->assertSame('0.0000', (string) $movement->unit_cost, 'A fresh location receives returned units at zero cost');
        $this->assertSame('0.00', (string) $movement->total_cost);

        $level = StockLevel::where('item_id', $item->id)->where('location_id', $freshLocation->id)->firstOrFail();
        $this->assertSame('4.000', (string) $level->quantity);
        $this->assertSame('0.0000', (string) $level->weighted_avg_cost);
    }
}
