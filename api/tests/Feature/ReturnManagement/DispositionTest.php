<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DispositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // REC-13 — a customer return now posts a real credit note to the GL,
        // which needs the chart of accounts (AR 1100, VAT-out 2060, revenue 4010).
        $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name'               => 'Disp Test Customer',
            'payment_terms_days' => 30,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'part_number' => 'PT-' . substr(uniqid(), -5),
            'name'        => 'Test Product',
        ]);
    }

    private function makeInvoice(Customer $customer, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-T-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
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

    private function makeInspectedRma(
        User $by,
        ?Customer $customer = null,
        ?Invoice $invoice = null,
        ?Product $product = null,
        string $type = 'customer_return',
    ): ReturnRequest {
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-' . substr(uniqid(), -5),
            'type'        => $type,
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer?->id,
            'invoice_id'  => $invoice?->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $product?->id,
            'quantity'          => 10,
            'returned_quantity' => 8,
            'unit_price'        => 100.00,
            'total'             => 800.00,
            'reason'            => 'defective',
            'condition'         => 'damaged',
        ]);

        return $rma->load('items');
    }

    public function test_dispose_sets_item_dispositions(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $rma = $this->makeInspectedRma($by, $customer);
        // 2026-08-08 — restock lines are received back into stock at dispose
        // time, so the destination location is mandatory.
        $location = WarehouseLocation::factory()->create();

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'restock',
                'notes'       => 'Good condition after inspection',
            ],
        ], $by, false, $location->id);

        $this->assertSame('disposed', $result->disposition_status);

        $item = $result->items->first();
        $this->assertSame('restock', $item->disposition);
        $this->assertSame('Good condition after inspection', $item->disposition_notes);
    }

    public function test_dispose_creates_ncr_for_scrap_items(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $rma = $this->makeInspectedRma($by, $customer, product: $product);

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'scrap',
                'notes'       => 'Irreparable damage',
            ],
        ], $by);

        $item = $result->items->first();
        $this->assertSame('scrap', $item->disposition);
        $this->assertNotNull($item->ncr_id);

        $ncr = NonConformanceReport::find($item->ncr_id);
        $this->assertNotNull($ncr);
        $this->assertSame($product->id, $ncr->product_id);
        $this->assertStringContains('Auto-created from RMA', $ncr->defect_description);
        $this->assertSame(8, $ncr->affected_quantity);
    }

    public function test_dispose_creates_credit_note_for_customer_return(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $by);
        $rma = $this->makeInspectedRma($by, $customer, $invoice);
        $location = WarehouseLocation::factory()->create();

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'restock',
            ],
        ], $by, false, $location->id);

        // REC-13 — a REAL credit note (positive amounts), not a negative-invoice
        // hack. 2026-08-08 — staged as a DRAFT (GL untouched) so finance reviews
        // before finalize posts the VAT-reversing entry; mirrors the
        // auto-bill/auto-invoice review-then-post pattern.
        $this->assertNotNull($result->credit_note_id);

        $creditNote = \App\Modules\Accounting\Models\CreditNote::find($result->credit_note_id);
        $this->assertNotNull($creditNote);
        $this->assertSame($customer->id, $creditNote->customer_id);
        $this->assertSame('customer', $creditNote->type->value);
        $this->assertSame('draft', $creditNote->status->value);
        $this->assertTrue((float) $creditNote->total_amount > 0, 'Credit note total should be positive');
        $this->assertNull($creditNote->journal_entry_id, 'Draft credit note must NOT touch the GL');
        $this->assertSame($invoice->id, $creditNote->invoice_id);
        $this->assertSame($rma->id, $creditNote->return_request_id);

        // One line per returned item (sourced from the invoice lines).
        $this->assertGreaterThanOrEqual(1, $creditNote->lines()->count());
    }

    public function test_dispose_rejects_non_inspected_rma(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-' . substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Received->value,
            'customer_id' => $customer->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $svc = app(ReturnRequestService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected status inspected, got received.');

        $svc->dispose($rma, [], $by);
    }

    public function test_dispose_creates_ncr_for_rework_items(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $rma = $this->makeInspectedRma($by, $customer, product: $product);
        // Rework lines also go back into stock on disposal → location required.
        $location = WarehouseLocation::factory()->create();

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'rework',
                'notes'       => 'Can be reworked',
            ],
        ], $by, false, $location->id);

        $item = $result->items->first();
        $this->assertSame('rework', $item->disposition);
        $this->assertNotNull($item->ncr_id);
    }

    public function test_supplier_disposition_reverses_receipt_applies_credit_and_creates_replacement_po(): void
    {
        app(\App\Common\Services\SettingsService::class)->set('budgeting.enforcement_mode', 'off');

        $by = $this->makeUser();
        $vendor = Vendor::factory()->create(['created_by' => null]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $expense = Account::query()->where('type', 'expense')->firstOrFail();

        $po = PurchaseOrder::factory()->create([
            'vendor_id'   => $vendor->id,
            'created_by'  => $by->id,
            'subtotal'    => '1000.00',
            'vat_amount'  => '120.00',
            'total_amount'=> '1120.00',
            'is_vatable'  => true,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin received for supplier-return test',
            'quantity'          => '100.00',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '100.00',
        ]);

        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_by'       => $by->id,
            'status'            => 'accepted',
        ]);
        $grnItem = GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '100.000',
            'quantity_accepted'      => '100.000',
            'unit_cost'              => '10.0000',
        ]);

        $bill = Bill::create([
            'bill_number'      => 'BILL-RMA-'.substr(uniqid(), -5),
            'vendor_id'        => $vendor->id,
            'purchase_order_id'=> $po->id,
            'status'           => 'unpaid',
            'subtotal'         => '1000.00',
            'vat_amount'       => '120.00',
            'total_amount'     => '1120.00',
            'amount_paid'      => '0.00',
            'balance'          => '1120.00',
            'date'             => now()->toDateString(),
            'due_date'         => now()->addDays(30)->toDateString(),
            'is_vatable'       => true,
            'created_by'       => $by->id,
        ]);
        $billItem = BillItem::create([
            'bill_id'            => $bill->id,
            'expense_account_id' => $expense->id,
            'item_id'            => $item->id,
            'description'        => 'Received resin',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        $rma = ReturnRequest::create([
            'rma_number'        => 'RMA-SUP-'.substr(uniqid(), -5),
            'type'              => ReturnRequestType::SupplierReturn->value,
            'status'            => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $po->id,
            'bill_id'           => $bill->id,
            'vendor_id'         => $vendor->id,
            'reason_code'       => 'quality_issue',
            'return_date'       => now()->toDateString(),
            'created_by'        => $by->id,
        ]);
        $rmaItem = ReturnRequestItem::create([
            'return_request_id'  => $rma->id,
            'item_id'            => $item->id,
            'quantity'           => '20.000',
            'returned_quantity'  => '20.000',
            'unit_price'         => '10.00',
            'total'              => '200.00',
            'source_po_item_id'  => $poItem->id,
            'source_grn_item_id' => $grnItem->id,
            'source_bill_item_id'=> $billItem->id,
        ]);

        // 2026-08-08 — return_to_supplier lines ship out at dispose time, so
        // put the goods on the shelf first and name the shipping location.
        app(\App\Modules\Inventory\Services\StockMovementService::class)->move(
            new \App\Modules\Inventory\Support\StockMovementInput(
                type: \App\Modules\Inventory\Enums\StockMovementType::AdjustmentIn,
                itemId: $item->id,
                toLocationId: $location->id,
                quantity: '100',
                unitCost: '10.00',
                referenceType: 'opening',
                createdBy: $by->id,
            )
        );

        $result = app(ReturnRequestService::class)->dispose($rma->load('items'), [[
            'item_id'     => $rmaItem->hash_id,
            'disposition' => 'return_to_supplier',
            'notes'       => 'Failed incoming QC',
        ]], $by, true, $location->id);

        $this->assertSame('80.000', (string) $grnItem->fresh()->quantity_received);
        $this->assertSame('80.000', (string) $grnItem->fresh()->quantity_accepted);
        $this->assertSame('80.00', (string) $poItem->fresh()->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        $credit = CreditNote::findOrFail($result->credit_note_id);
        $this->assertSame('supplier', $credit->type->value);
        $this->assertSame('224.00', (string) $credit->total_amount);
        $this->assertSame('applied', $credit->status->value);
        $this->assertSame('896.00', (string) $bill->fresh()->balance);

        $replacement = $result->replacementPurchaseOrder;
        $this->assertNotNull($replacement);
        $this->assertSame($vendor->id, $replacement->vendor_id);
        $this->assertSame(PurchaseOrderStatus::Draft, $replacement->status);
        $this->assertSame('20.00', (string) $replacement->items()->firstOrFail()->quantity);
        $this->assertSame('disposed', $result->disposition_status);

        // The goods leave stock the moment the disposition is recorded.
        $movement = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();
        $this->assertSame(\App\Modules\Inventory\Enums\StockMovementType::ReturnToVendor, $movement->movement_type);
        $this->assertSame('20.000', (string) $movement->quantity);
        $this->assertSame('80.000', (string) \App\Modules\Inventory\Models\StockLevel::where('item_id', $item->id)
            ->where('location_id', $location->id)->firstOrFail()->quantity, '100 on shelf − 20 shipped back');

        try {
            app(ReturnRequestService::class)->dispose($result->fresh('items'), [[
                'item_id' => $rmaItem->hash_id,
                'disposition' => 'return_to_supplier',
            ]], $by, true);
            $this->fail('A disposed supplier return must not run twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already been disposed', $e->getMessage());
        }
        $this->assertSame(1, CreditNote::query()->where('return_request_id', $rma->id)->count());
        $this->assertSame('80.000', (string) $grnItem->fresh()->quantity_received);
    }

    public function test_supplier_disposition_rolls_back_when_source_lineage_is_invalid(): void
    {
        $by = $this->makeUser();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'created_by' => $by->id]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id,
            'description' => 'Original line', 'quantity' => 10, 'unit' => 'pcs',
            'unit_price' => 5, 'total' => 50, 'quantity_received' => 10,
        ]);
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-SUP-'.substr(uniqid(), -5),
            'type' => ReturnRequestType::SupplierReturn->value,
            'status' => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $po->id, 'vendor_id' => $vendor->id,
            'reason_code' => 'quality_issue', 'return_date' => now()->toDateString(),
            'created_by' => $by->id,
        ]);
        $rmaItem = ReturnRequestItem::create([
            'return_request_id' => $rma->id, 'item_id' => $item->id,
            'quantity' => 2, 'returned_quantity' => 2, 'unit_price' => 5, 'total' => 10,
            'source_po_item_id' => $poItem->id,
            // Deliberately no GRN lineage.
        ]);

        // A location is supplied so the dispose-time location rule passes and
        // the service actually reaches the lineage guard this test is about.
        $location = WarehouseLocation::factory()->create();

        try {
            app(ReturnRequestService::class)->dispose($rma->load('items'), [[
                'item_id' => $rmaItem->hash_id,
                'disposition' => 'return_to_supplier',
            ]], $by, false, $location->id);
            $this->fail('Expected invalid supplier-return lineage to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source GRN and PO lines', $e->getMessage());
        }

        $this->assertNull($rmaItem->fresh()->disposition, 'Item update must roll back with the transaction.');
        $this->assertNull($rma->fresh()->disposition_status);
        $this->assertSame('10.00', (string) $poItem->fresh()->quantity_received);
    }

    /**
     * Custom assertion: str_contains wrapper for readability.
     */
    private static function assertStringContains(string $needle, ?string $haystack, string $message = ''): void
    {
        static::assertNotNull($haystack, $message ?: "Expected non-null string containing '{$needle}'");
        static::assertTrue(
            str_contains($haystack, $needle),
            $message ?: "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
