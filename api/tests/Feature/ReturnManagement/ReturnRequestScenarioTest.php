<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Models\ReturnRequestSourceAllocation;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Common\Services\ApprovalService;
use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end HTTP scenarios for the RMA lifecycle.
 *
 * The pre-existing suite only drove ReturnRequestService directly with raw
 * integer IDs, so every defect that lives on the controller / request boundary
 * (hash-id decoding, item keying, guard ordering) was invisible.
 */
class ReturnRequestScenarioTest extends TestCase
{
    use RefreshDatabase;

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
        return Customer::create(['name' => 'Scenario Customer', 'payment_terms_days' => 30]);
    }

    private function product(): Product
    {
        return Product::create([
            'part_number' => 'PT-' . substr(uniqid(), -5),
            'name'        => 'Scenario Product',
        ]);
    }

    /**
     * `invoice_items.revenue_account_id` is NOT NULL, so an invoice source line
     * cannot be built without one. 4010 is the sales-revenue account the
     * ChartOfAccountsSeeder installs, matching the other RMA fixtures.
     */
    private function revenueAccountId(): int
    {
        return (int) Account::query()->where('code', '4010')->firstOrFail()->id;
    }

    private function invoice(Customer $c, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-S-' . substr(uniqid(), -5),
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

    /** An inspected customer RMA with one line: 10 requested, 8 physically returned. */
    private function inspectedRma(User $by, Customer $c, ?Invoice $inv = null, ?Product $p = null, ?Item $item = null): ReturnRequest
    {
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-S-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $c->id,
            'invoice_id'  => $inv?->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $p?->id,
            'item_id'           => $item?->id,
            'quantity'          => 10,
            'returned_quantity' => 8,
            'unit_price'        => '100.00',
            'total'             => '1000.00',
        ]);

        // Disposition is gated on a passed product-linked return inspection.
        if ($p) {
            Inspection::create([
                'inspection_number' => 'QC-RMA-'.substr(uniqid(), -8),
                'stage'             => InspectionStage::CustomerReturn->value,
                'status'            => 'passed',
                'product_id'        => $p->id,
                'entity_type'       => InspectionEntityType::ReturnRequest->value,
                'entity_id'         => $rma->id,
                'batch_quantity'    => 8,
                'sample_size'       => 8,
                'accept_count'      => 0,
                'reject_count'      => 0,
                'defect_count'      => 0,
                'inspector_id'      => $by->id,
            ]);
        }

        return $rma->load('items');
    }

    /**
     * Classify an RMA as finance-only.
     *
     * A line carrying neither an inventory item nor a source document line is
     * exactly what finance-only exists for: `resolveSource()` short-circuits on
     * it (`ReturnRequestService::234-236`) and `createCreditNote()` refuses a
     * product-only credit without it. Without this the fixture is an
     * unsubmittable stockable return, and submit fails on the source contract
     * instead of on the behaviour under test.
     */
    private function asFinanceOnly(ReturnRequest $rma, User $by): ReturnRequest
    {
        $rma->forceFill([
            'finance_only'             => true,
            'finance_only_reason'      => 'Product-only credit scenario is non-stock.',
            'finance_only_approved_by' => $by->id,
        ])->save();

        return $rma;
    }

    /* ───────────────── Boundary: hash IDs ───────────────── */

    public function test_store_accepts_hash_ids_the_spa_actually_sends(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();
        $product  = $this->product();

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type'        => 'customer_return',
                'customer_id' => $customer->hash_id,
                'finance_only' => true,
                'finance_only_reason' => 'Customer supplied a non-stock service credit.',
                'reason_code' => 'defective',
                'return_date' => now()->toDateString(),
                'items'       => [[
                    'product_id' => $product->hash_id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated();

        $this->assertSame($customer->id, ReturnRequest::query()->value('customer_id'));
        $this->assertSame($product->id, ReturnRequestItem::query()->value('product_id'));
    }

    public function test_index_filters_by_customer_hash_id(): void
    {
        $admin = $this->admin();
        $a = $this->customer();
        $b = Customer::create(['name' => 'Other Customer', 'payment_terms_days' => 30]);

        $this->inspectedRma($admin, $a);
        $this->inspectedRma($admin, $b);

        $this->actingAs($admin)
            ->getJson('/api/v1/return-management/return-requests?customer_id=' . $a->hash_id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_complete_rejects_stockable_return_without_quarantine_lineage(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        $rma->forceFill(['disposition_status' => 'disposed'])->save();
        $rma->items->each->update(['disposition' => 'restock']);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertStatus(422);
    }

    /* ───────────────── Receiving ───────────────── */

    public function test_receive_records_returned_quantities_keyed_by_item_hash_id(): void
    {
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill(['status' => ReturnRequestStatus::Approved->value])->save();
        $line  = $rma->items->first();
        $line->update(['returned_quantity' => 0]);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/receive", [
                'received_quantities' => [$line->hash_id => 6],
            ])
            ->assertOk();

        $this->assertSame('6.000', $line->fresh()->returned_quantity);
    }

    public function test_receive_puts_stockable_customer_returns_in_quarantine(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $rma = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        $rma->forceFill(['status' => ReturnRequestStatus::Approved->value])->save();
        $zone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $line = $rma->items->first();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/receive", [
                'received_quantities' => [$line->hash_id => 6],
                'quarantine_location_id' => $location->hash_id,
            ])
            ->assertOk();

        $line = $line->fresh();
        $this->assertSame('held', $line->quarantine_status);
        $this->assertNotNull($line->quarantine_movement_id);
        $this->assertSame($location->id, $line->quarantine_location_id);
        $this->assertSame('adjustment_in', $line->quarantineMovement->movement_type->value);
    }

    public function test_receive_rejects_a_quantity_larger_than_the_requested_quantity(): void
    {
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill(['status' => ReturnRequestStatus::Approved->value])->save();
        $line  = $rma->items->first();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/receive", [
                'received_quantities' => [$line->hash_id => 999],
            ])
            ->assertStatus(422);
    }

    /* ───────────────── Disposition ───────────────── */

    public function test_dispose_rejects_a_partial_disposition_set(): void
    {
        $admin   = $this->admin();
        $product = $this->product();
        $rma     = $this->inspectedRma($admin, $this->customer(), null, $product);
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $this->product()->id,
            'quantity'          => 2,
            'returned_quantity' => 2,
            'unit_price'        => '50.00',
            'total'             => '100.00',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertStatus(422);

        $this->assertNull($rma->fresh()->disposition_status);
    }

    public function test_customer_credit_note_is_based_on_the_returned_quantity(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();
        $invoice  = $this->invoice($customer, $admin);
        $rma      = $this->inspectedRma($admin, $customer, $invoice, $this->product());
        $this->asFinanceOnly($rma, $admin);

        // `no_return` is the only disposition a finance-only RMA may take
        // (DispositionType::allowedFor) — it books the credit without moving
        // stock, and is deliberately exempt from the zero-quantity rule that
        // applies to stockable returns (ReturnRequestService::984-991).
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'no_return',
                ]],
            ])
            ->assertOk();

        // 8 returned × 100.00 = 800.00 credited, NOT the 10 originally requested.
        // This is the regression guard for settledQuantity(): the line carries
        // returned_quantity 8 without receipt_recorded, and reading `quantity`
        // there over-credited the customer by ₱200.
        $this->assertSame('800.00', $rma->fresh()->creditNote->subtotal);
    }

    public function test_a_customer_line_cannot_be_routed_onward_to_the_supplier(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();
        $invoice  = $this->invoice($customer, $admin);
        $rma      = $this->inspectedRma($admin, $customer, $invoice, $this->product());

        // `return_to_supplier` is a supplier-return disposition. Offering it on a
        // customer RMA would produce a terminal return that credits nobody, so
        // DispositionType::allowedFor() keeps it off the customer matrix and the
        // dispose validator refuses it at the boundary.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dispositions.0.disposition');

        // A customer line routed onward to the supplier is not a customer credit.
        $this->assertNull($rma->fresh()->credit_note_id);
        $this->assertNull($rma->fresh()->disposition_status);
    }

    /* ───────────────── Completion ───────────────── */

    public function test_complete_is_blocked_until_the_items_are_disposed(): void
    {
        $admin = $this->admin();
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), $this->invoice($this->customer(), $admin), null, Item::factory()->create());

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertStatus(422);

        $this->assertSame(ReturnRequestStatus::Inspected, $rma->fresh()->status);
    }

    public function test_complete_does_not_restock_scrapped_lines(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $zone  = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $loc   = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $rma   = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        // The credit contract now runs for every customer return, so a
        // stockable line must carry invoice/delivery/SO provenance.
        $rma->items->first()->update(['source_sales_order_item_id' => SalesOrderItem::factory()->create()->id]);
        $rma->forceFill(['status' => ReturnRequestStatus::Approved->value])->save();
        $line = $rma->items->first();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/receive", [
            'received_quantities' => [$line->hash_id => 8],
            'quarantine_location_id' => $loc->hash_id,
        ])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/inspect")
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
            'dispositions' => [['item_id' => $line->hash_id, 'disposition' => 'scrap']],
        ])->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertSame('8.000', StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->where('movement_type', StockMovementType::Scrap->value)
            ->value('quantity'));
        $this->assertSame('scrapped', $line->fresh()->quarantine_status);
    }

    public function test_complete_restocks_only_the_returned_quantity(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $zone  = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $loc   = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $destination = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        // The credit contract now runs for every customer return, so a
        // stockable line must carry invoice/delivery/SO provenance.
        $rma->items->first()->update(['source_sales_order_item_id' => SalesOrderItem::factory()->create()->id]);
        $rma->forceFill(['status' => ReturnRequestStatus::Approved->value])->save();
        $line = $rma->items->first();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/receive", [
            'received_quantities' => [$line->hash_id => 8],
            'quarantine_location_id' => $loc->hash_id,
        ])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/inspect")
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
            'dispositions' => [['item_id' => $line->hash_id, 'disposition' => 'restock']],
            'location_id' => $destination->hash_id,
        ])->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertSame('8.000', StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->where('movement_type', StockMovementType::Transfer->value)
            ->value('quantity'));
    }

    /* ───────────────── Terminal transitions ───────────────── */

    public function test_reject_is_blocked_once_the_items_have_been_disposed(): void
    {
        // Disposition is the point of no return: it issues the credit note and
        // reverses GRN / PO receipt quantities. Rejecting afterwards would leave
        // those financial artefacts standing against a "rejected" RMA.
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/reject", [
                'reason' => 'Changed our mind after the credit note went out.',
            ])
            ->assertStatus(422);
    }

    public function test_reject_is_blocked_after_physical_receipt(): void
    {
        // A received RMA already has physical evidence and may have quarantine
        // stock; rejection needs a compensating workflow and is therefore not
        // a status-only action.
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill(['status' => ReturnRequestStatus::Received->value])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/reject", [
                'reason' => 'Goods arrived outside the return window.',
            ])
            ->assertStatus(422);

        $this->assertSame(ReturnRequestStatus::Received, $rma->fresh()->status);
    }

    public function test_reject_preserves_the_existing_internal_notes(): void
    {
        $this->seed(WorkflowSeeder::class);
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill([
            'status'         => ReturnRequestStatus::PendingApproval->value,
            'internal_notes' => 'Original triage note.',
        ])->save();
        app(ApprovalService::class)->submit($rma, 'return_request');
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);

        $this->actingAs($approver)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/reject", [
                'reason' => 'Outside the return window.',
            ])
            ->assertOk();

        $this->assertStringContainsString('Original triage note.', (string) $rma->fresh()->internal_notes);
    }

    /* ───────────────── Approval chain ───────────────── */

    public function test_submit_fails_loudly_when_the_approval_chain_cannot_be_opened(): void
    {
        // No WorkflowSeeder → the return_request workflow definition is absent.
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $this->asFinanceOnly($rma, $admin);
        $rma->forceFill(['status' => ReturnRequestStatus::Draft->value])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertStatus(422);

        // The RMA must not be stranded in pending_approval with no chain to approve.
        $this->assertSame(ReturnRequestStatus::Draft, $rma->fresh()->status);
    }

    public function test_approve_reports_failure_instead_of_silently_doing_nothing(): void
    {
        $this->seed(WorkflowSeeder::class);
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $this->asFinanceOnly($rma, $admin);
        $rma->forceFill(['status' => ReturnRequestStatus::Draft->value])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertOk();

        // A user with no step in the chain must get an error, not a 200 no-op.
        $outsider = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $response = $this->actingAs($outsider)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/approve");

        $this->assertContains($response->status(), [403, 422], 'A non-approver must be refused.');
        $this->assertSame(ReturnRequestStatus::PendingApproval, $rma->fresh()->status);
    }

    /* ───────────────── Payload integrity ───────────────── */

    public function test_customer_return_requires_a_customer(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/return-management/return-requests', [
                'type'        => 'customer_return',
                'reason_code' => 'defective',
                'return_date' => now()->toDateString(),
                'items'       => [['product_id' => $this->product()->hash_id, 'quantity' => 1, 'unit_price' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_supplier_return_requires_a_vendor(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/return-management/return-requests', [
                'type'        => 'supplier_return',
                'reason_code' => 'defective',
                'return_date' => now()->toDateString(),
                'items'       => [['item_id' => Item::factory()->create()->hash_id, 'quantity' => 1, 'unit_price' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vendor_id');
    }

    public function test_store_persists_the_source_document_line_links(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();
        $invoice  = $this->invoice($customer, $admin);

        $so = \App\Modules\CRM\Models\SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $soItem = \App\Modules\CRM\Models\SalesOrderItem::factory()->create(['sales_order_id' => $so->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type'           => 'customer_return',
                'customer_id'    => $customer->hash_id,
                'sales_order_id' => $so->hash_id,
                'invoice_id'     => $invoice->hash_id,
                'finance_only' => true,
                'finance_only_reason' => 'Product-only legacy source-line coverage.',
                'reason_code'    => 'defective',
                'return_date'    => now()->toDateString(),
                'items'          => [[
                    'product_id'                 => $soItem->product_id,
                    'quantity'                   => 1,
                    'unit_price'                 => 10,
                    'source_sales_order_item_id' => $soItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $this->assertSame($soItem->id, ReturnRequestItem::query()->value('source_sales_order_item_id'));
    }

    public function test_store_resolves_an_invoice_source_kind_and_reserves_its_authoritative_price(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $invoice = $this->invoice($customer, $admin);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => $this->revenueAccountId(),
            'description' => 'Returned stock',
            'product_id' => $product->id,
            'quantity' => '5.000',
            'unit_price' => '77.00',
            'total' => '385.00',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'invoice_id' => $invoice->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '2.000',
                    'source_invoice_item_id' => $invoiceItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $line = ReturnRequestItem::query()->firstOrFail();
        $this->assertSame($invoiceItem->id, $line->source_invoice_item_id);
        $this->assertSame('77.00', $line->unit_price);
        $this->assertDatabaseHas('return_request_source_allocations', [
            'return_request_item_id' => $line->id,
            'source_kind' => 'invoice_item',
            'source_id' => $invoiceItem->id,
            'quantity' => '2.000',
        ]);
    }

    public function test_store_rejects_a_source_backed_customer_return_without_product_provenance(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $item = Item::factory()->create();
        $invoice = $this->invoice($customer, $admin);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => $this->revenueAccountId(),
            'description' => 'Returned stock',
            'product_id' => $this->product()->id,
            'quantity' => '5.000',
            'unit_price' => '77.00',
            'total' => '385.00',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'invoice_id' => $invoice->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'item_id' => $item->hash_id,
                    'quantity' => '1.000',
                    'source_invoice_item_id' => $invoiceItem->hash_id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_store_resolves_a_sales_order_source_kind(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'confirmed',
        ]);
        $salesOrderItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_delivered' => '4.000',
            'unit_price' => '88.00',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'sales_order_id' => $salesOrder->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '1.000',
                    'source_sales_order_item_id' => $salesOrderItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $line = ReturnRequestItem::query()->firstOrFail();
        $this->assertSame($salesOrderItem->id, $line->source_sales_order_item_id);
        $this->assertSame('88.00', $line->unit_price);
        $this->assertDatabaseHas('return_request_source_allocations', [
            'return_request_item_id' => $line->id,
            'source_kind' => 'sales_order_item',
            'source_id' => $salesOrderItem->id,
        ]);
    }

    public function test_store_resolves_a_delivery_source_kind(): void    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'confirmed',
        ]);
        $salesOrderItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_delivered' => '3.000',
            'unit_price' => '99.00',
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DLV-RMA-'.substr(uniqid(), -8),
            'sales_order_id' => $salesOrder->id,
            'status' => 'delivered',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);
        $deliveryItem = DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $salesOrderItem->id,
            'quantity' => '2.000',
            'unit_price' => '99.00',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'sales_order_id' => $salesOrder->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '1.000',
                    'source_delivery_item_id' => $deliveryItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $line = ReturnRequestItem::query()->firstOrFail();
        $this->assertSame($deliveryItem->id, $line->source_delivery_item_id);
        $this->assertSame('99.00', $line->unit_price);
        $this->assertDatabaseHas('return_request_source_allocations', [
            'return_request_item_id' => $line->id,
            'source_kind' => 'delivery_item',
            'source_id' => $deliveryItem->id,
        ]);
    }

    /* ───────────────── Service-level disposition invariant ───────────────── */

    /**
     * The HTTP validator already refuses a partial disposition set. This drives
     * the SERVICE directly — the path a queued workflow or console command would
     * take — because `dispose()` skips lines it has no entry for and then marks
     * the RMA `disposed`, stranding the undecided ones forever.
     */
    public function test_service_dispose_refuses_a_partial_disposition_set(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, $product);
        $this->asFinanceOnly($rma, $admin);
        // Same product as line 1 — a second product would have no passed return
        // inspection, and that gate fires before the completeness check.
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $product->id,
            'quantity'          => 2,
            'returned_quantity' => 2,
            'unit_price'        => '50.00',
            'total'             => '100.00',
        ]);
        $rma->load('items');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('1 line(s) are undecided');

        try {
            app(ReturnRequestService::class)->dispose($rma, [[
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'no_return',
            ]], $admin);
        } finally {
            // No side effect may survive the refusal.
            $this->assertNull($rma->fresh()->disposition_status);
            $this->assertNull($rma->fresh()->credit_note_id);
            foreach ($rma->fresh()->items as $line) {
                $this->assertNull($line->disposition);
            }
        }
    }

    public function test_service_dispose_refuses_the_same_line_twice(): void
    {
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, $this->product());
        $this->asFinanceOnly($rma, $admin);
        $line  = $rma->items->first();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('only one disposition');

        try {
            app(ReturnRequestService::class)->dispose($rma, [
                ['item_id' => $line->hash_id, 'disposition' => 'no_return'],
                ['item_id' => $line->hash_id, 'disposition' => 'no_return'],
            ], $admin);
        } finally {
            $this->assertNull($rma->fresh()->disposition_status);
        }
    }

    /* ───────────────── Source reservations & DB backstops ───────────────── */

    /**
     * RMA-005 — a reservation made at draft time is not evidence that the source
     * can still back it. Submit used to skip the check entirely whenever an
     * active allocation existed, so a draft raised against 5 available units
     * survived the invoice line being cut to 1.
     */
    public function test_submit_refuses_a_reservation_the_shrunken_source_can_no_longer_back(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $invoice = $this->invoice($customer, $admin);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => $this->revenueAccountId(),
            'description' => 'Returned stock',
            'product_id' => $product->id,
            'quantity' => '5.000',
            'unit_price' => '77.00',
            'total' => '385.00',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'invoice_id' => $invoice->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '4.000',
                    'source_invoice_item_id' => $invoiceItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $rma = ReturnRequest::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('return_request_source_allocations', [
            'return_request_item_id' => $rma->items()->value('id'),
            'quantity' => '4.000',
        ]);

        // The source document is cut below the standing reservation.
        $invoiceItem->update(['quantity' => '1.000']);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Return quantity exceeds the remaining quantity on the invoice_item source line.');

        $this->assertSame(ReturnRequestStatus::Draft, $rma->fresh()->status);
    }

    public function test_submit_still_passes_when_the_source_can_back_the_standing_reservation(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $invoice = $this->invoice($customer, $admin);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => $this->revenueAccountId(),
            'description' => 'Returned stock',
            'product_id' => $product->id,
            'quantity' => '5.000',
            'unit_price' => '77.00',
            'total' => '385.00',
        ]);
        $this->seed(WorkflowSeeder::class);

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'invoice_id' => $invoice->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '4.000',
                    'source_invoice_item_id' => $invoiceItem->hash_id,
                ]],
            ])
            ->assertCreated();

        $rma = ReturnRequest::query()->latest('id')->firstOrFail();

        // Revalidation must not double-count the line's OWN reservation.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertOk();

        $this->assertSame(ReturnRequestStatus::PendingApproval, $rma->fresh()->status);
        $this->assertSame(1, ReturnRequestSourceAllocation::query()
            ->whereNull('released_at')
            ->count(), 'Revalidation must reuse the reservation, not stack a second one.');
    }

    /**
     * RMA-006 — the state machine is an application table; the column was an
     * unconstrained string, so a direct writer could persist a status outside
     * `ReturnRequestStatus` and the failure surfaced later as an enum-hydration
     * error on read.
     */
    public function test_database_refuses_a_return_request_status_outside_the_enum(): void
    {
        $rma = $this->inspectedRma($this->admin(), $this->customer());

        $this->expectException(QueryException::class);

        DB::table('return_requests')->where('id', $rma->id)->update(['status' => 'not_a_status']);
    }

    public function test_database_refuses_a_malformed_source_allocation(): void
    {
        $rma  = $this->inspectedRma($this->admin(), $this->customer());
        $line = $rma->items->first();

        foreach ([
            'negative quantity'   => ['quantity' => '-1.000', 'unit_price' => '1.00', 'source_kind' => 'invoice_item'],
            'negative unit price' => ['quantity' => '1.000', 'unit_price' => '-1.00', 'source_kind' => 'invoice_item'],
            'unresolvable kind'   => ['quantity' => '1.000', 'unit_price' => '1.00', 'source_kind' => 'bill_item'],
        ] as $label => $attributes) {
            $threw = false;
            try {
                DB::table('return_request_source_allocations')->insert($attributes + [
                    'return_request_item_id' => $line->id,
                    'source_id'              => 1,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            } catch (QueryException) {
                $threw = true;
            }
            $this->assertTrue($threw, "The database must refuse a source allocation with a {$label}.");
        }
    }

    /**
     * RMA-010 — the source picker advertised the raw document quantity as
     * available, so two operators saw the same headroom and the second only
     * learned of the first's reservation from a late submit rejection.
     */
    public function test_source_options_report_the_quantity_actually_still_reservable(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $item = Item::factory()->create();
        $invoice = $this->invoice($customer, $admin);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => $this->revenueAccountId(),
            'description' => 'Returned stock',
            'product_id' => $product->id,
            'quantity' => '5.000',
            'unit_price' => '77.00',
            'total' => '385.00',
        ]);

        // Before any RMA: the whole line is reservable. `quantity` keeps the
        // source document's own cast (invoice_items is decimal:2); the reservable
        // figure is reported at the allocation ledger's 3 dp, which is the
        // precision an RMA actually reserves at.
        $this->actingAs($admin)
            ->getJson('/api/v1/return-management/return-requests/source-options?type=customer_return&customer_id='.$customer->hash_id)
            ->assertOk()
            ->assertJsonPath('data.customer.invoices.0.lines.0.quantity', '5.00')
            ->assertJsonPath('data.customer.invoices.0.lines.0.remaining_quantity', '5.000');

        $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type' => 'customer_return',
                'customer_id' => $customer->hash_id,
                'invoice_id' => $invoice->hash_id,
                'reason_code' => 'defective',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => '2.000',
                    'source_invoice_item_id' => $invoiceItem->hash_id,
                ]],
            ])
            ->assertCreated();

        // The document quantity is unchanged; only the reservable amount moves.
        $this->actingAs($admin)
            ->getJson('/api/v1/return-management/return-requests/source-options?type=customer_return&customer_id='.$customer->hash_id)
            ->assertOk()
            ->assertJsonPath('data.customer.invoices.0.lines.0.quantity', '5.00')
            ->assertJsonPath('data.customer.invoices.0.lines.0.remaining_quantity', '3.000');

        // And the reservation is now traceable from the RMA detail response.
        $rma = ReturnRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($admin)
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.source_allocation.source_kind', 'invoice_item')
            ->assertJsonPath('data.items.0.source_allocation.quantity', '2.000')
            ->assertJsonPath('data.items.0.source_allocation.unit_price', '77.00');
    }

    /* ───────────────── Feature toggle ───────────────── */
    /**
     * Turning the module off must close the API, not merely hide the sidebar
     * entry. The permission check is deliberately nested INSIDE the feature
     * boundary, so a user who still holds `return_management.*` is refused too.
     */
    public function test_reads_are_refused_when_the_return_management_feature_is_switched_off(): void
    {
        app(SettingsService::class)->set('modules.return_management', false, 'modules');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/return-management/return-requests')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_writes_are_refused_when_the_return_management_feature_is_switched_off(): void
    {
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, $this->product());
        app(SettingsService::class)->set('modules.return_management', false, 'modules');

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'scrap',
                ]],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_disabled');

        $this->assertNull($rma->fresh()->disposition_status, 'A disabled module must not mutate an RMA.');
    }
}
