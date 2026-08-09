<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        return $rma->load('items');
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

    public function test_complete_accepts_a_warehouse_location_hash_id(): void
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
            ->assertOk();
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
        $loc      = WarehouseLocation::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        // 8 returned × 100.00 = 800.00 credited, NOT the 10 originally requested.
        $this->assertSame('800.00', $rma->fresh()->creditNote->subtotal);
    }

    public function test_scrapped_lines_are_not_credited_back_to_the_customer(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();
        $invoice  = $this->invoice($customer, $admin);
        $rma      = $this->inspectedRma($admin, $customer, $invoice, $this->product());

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
            ])
            ->assertOk();

        // A customer line routed onward to the supplier is not a customer credit.
        $this->assertNull($rma->fresh()->credit_note_id);
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
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        $rma->items->each->update(['disposition' => 'scrap']);
        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->where('movement_type', StockMovementType::AdjustmentIn->value)
            ->count());
    }

    public function test_complete_restocks_only_the_returned_quantity(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), null, null, $item);
        $rma->items->each->update(['disposition' => 'restock']);
        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [
                'location_id' => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertSame('8.000', StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
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

    public function test_reject_is_still_allowed_before_disposition(): void
    {
        // Nothing is booked to inventory until complete(), so rejecting a
        // received-but-undisposed RMA is stock-neutral and must stay possible.
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill(['status' => ReturnRequestStatus::Received->value])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/reject", [
                'reason' => 'Goods arrived outside the return window.',
            ])
            ->assertOk();

        $this->assertSame(ReturnRequestStatus::Rejected, $rma->fresh()->status);
    }

    public function test_reject_preserves_the_existing_internal_notes(): void
    {
        $admin = $this->admin();
        $rma   = $this->inspectedRma($admin, $this->customer());
        $rma->forceFill([
            'status'         => ReturnRequestStatus::PendingApproval->value,
            'internal_notes' => 'Original triage note.',
        ])->save();

        $this->actingAs($admin)
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
}
