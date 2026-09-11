<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer self-service returns (RMA) through the B2B customer portal:
 * server-resolved inventory items, customer-scoped source options, draft-only
 * creation, and cross-customer isolation.
 */
class CustomerReturnPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(?Customer $customer = null): CustomerPortalUser
    {
        $customer ??= Customer::factory()->create();

        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name'        => 'CustUser-'.substr(uniqid(), -5),
            'email'       => 'cu-'.uniqid().'@t.test',
            'password'    => bcrypt('Password1!'),
            'is_active'   => true,
        ]);
    }

    private function actAs(CustomerPortalUser $user): self
    {
        $this->actingAs($user, 'customer_portal');

        return $this;
    }

    /**
     * A finished-goods inventory item whose code matches the product's part
     * number — the mapping the return service resolves server-side.
     *
     * @return array{0: Product, 1: Item}
     */
    private function productWithItem(string $partNumber): array
    {
        $product = Product::create(['part_number' => $partNumber, 'name' => 'Finished part']);
        $item = Item::factory()->create([
            'code'      => $partNumber,
            'item_type' => ItemType::FinishedGood->value,
        ]);

        return [$product, $item];
    }

    private function finalizedInvoice(Customer $customer, Product $product, string $quantity = '10.00'): Invoice
    {
        $by = User::factory()->create();
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CP-'.substr(uniqid(), -5),
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

        $invoice->items()->create([
            'revenue_account_id' => (int) Account::query()->where('code', '4010')->firstOrFail()->id,
            'product_id'         => $product->id,
            'description'        => 'Returned part',
            'quantity'           => $quantity,
            'unit_price'         => '100.00',
            'total'              => '1000.00',
        ]);

        return $invoice->load('items');
    }

    /* ─── Source options ─────────────────────────────────────────── */

    public function test_source_options_resolve_the_finished_good_item_and_remaining_quantity(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        [$product, $item] = $this->productWithItem('PT-RET-01');
        $invoice = $this->finalizedInvoice($customer, $product, '10.00');

        $this->actAs($user);

        $data = $this->getJson('/api/v1/b2b/customer/return-requests/source-options')
            ->assertOk()
            ->json('data.customer');

        $this->assertCount(1, $data['invoices']);
        $this->assertSame($invoice->hash_id, $data['invoices'][0]['id']);
        $line = $data['invoices'][0]['lines'][0];
        $this->assertSame($product->hash_id, $line['product_id']);
        $this->assertSame($item->hash_id, $line['item_id'], 'The portal must never ask the customer to pick a stock item.');
        $this->assertSame('10.00', $line['quantity']);
        $this->assertSame('10.000', $line['remaining_quantity']);
        $this->assertSame('100.00', $line['unit_price']);
    }

    public function test_source_options_are_customer_scoped(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $user = $this->makePortalUser($customerA);
        [$productA] = $this->productWithItem('PT-A-01');
        [$productB] = $this->productWithItem('PT-B-01');
        $invoiceA = $this->finalizedInvoice($customerA, $productA);
        $this->finalizedInvoice($customerB, $productB);

        $this->actAs($user);

        $data = $this->getJson('/api/v1/b2b/customer/return-requests/source-options')
            ->assertOk()
            ->json('data.customer');

        $ids = array_column($data['invoices'], 'id');
        $this->assertSame([$invoiceA->hash_id], $ids);
    }

    public function test_source_options_flag_a_product_with_no_finished_good_item(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::create(['part_number' => 'PT-NOITEM-01', 'name' => 'Unmapped part']);
        $this->finalizedInvoice($customer, $product);

        $this->actAs($user);

        $line = $this->getJson('/api/v1/b2b/customer/return-requests/source-options')
            ->assertOk()
            ->json('data.customer.invoices.0.lines.0');

        $this->assertNull($line['item_id']);
    }

    /* ─── Create draft RMA ───────────────────────────────────────── */

    public function test_customer_creates_a_draft_return_with_a_server_resolved_item(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        [$product, $item] = $this->productWithItem('PT-RET-02');
        $invoice = $this->finalizedInvoice($customer, $product, '10.00');
        $sourceLine = $invoice->items->first();

        // A manager who should be notified of a portal-submitted return.
        $manager = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'ppc_head')->value('id'),
            'is_active' => true,
        ]);

        $this->actAs($user);

        $response = $this->postJson('/api/v1/b2b/customer/return-requests', [
            'reason_code'        => 'defective',
            'reason_description' => 'Cracked on arrival.',
            'items'              => [[
                'source_invoice_item_id' => $sourceLine->hash_id,
                'quantity'               => '3',
                // A client-supplied price must be ignored.
                'unit_price'             => '1.00',
                // A client-supplied item must be ignored.
                'item_id'                => Item::factory()->create()->hash_id,
                'reason'                 => 'Cracked housing',
            ]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', ReturnRequestType::CustomerReturn->value)
            ->assertJsonPath('data.status', ReturnRequestStatus::Draft->value);

        $rma = ReturnRequest::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(ReturnRequestType::CustomerReturn, $rma->type);
        $this->assertSame(ReturnRequestStatus::Draft, $rma->status);
        $this->assertFalse((bool) $rma->finance_only);
        $this->assertSame((int) $invoice->id, (int) $rma->invoice_id);

        $line = $rma->items()->firstOrFail();
        $this->assertSame((int) $item->id, (int) $line->item_id);
        $this->assertSame((int) $product->id, (int) $line->product_id);
        $this->assertSame('3.000', (string) $line->quantity);
        // Price came from the invoice line, not the request body.
        $this->assertSame('100.00', (string) $line->unit_price);
        $this->assertSame('300.00', (string) $line->total);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id'   => $manager->id,
            'type'            => 'customer.rma_created',
        ]);
    }

    public function test_customer_cannot_return_more_than_the_source_line_holds(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        [$product] = $this->productWithItem('PT-RET-03');
        $invoice = $this->finalizedInvoice($customer, $product, '2.00');

        $this->actAs($user);

        $this->postJson('/api/v1/b2b/customer/return-requests', [
            'items' => [[
                'source_invoice_item_id' => $invoice->items->first()->hash_id,
                'quantity'               => '9',
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_customer_cannot_return_another_customers_source_line(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $userA = $this->makePortalUser($customerA);
        [$productB] = $this->productWithItem('PT-RET-04');
        $invoiceB = $this->finalizedInvoice($customerB, $productB);

        $this->actAs($userA);

        $this->postJson('/api/v1/b2b/customer/return-requests', [
            'items' => [[
                'source_invoice_item_id' => $invoiceB->items->first()->hash_id,
                'quantity'               => '1',
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('return_requests', 0);
    }

    /* ─── Listing / detail isolation ─────────────────────────────── */

    public function test_foreign_customers_rma_detail_is_forbidden(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $userA = $this->makePortalUser($customerA);
        [$productB, $itemB] = $this->productWithItem('PT-RET-05');

        // A draft RMA belonging to customer B.
        $rmaB = ReturnRequest::create([
            'rma_number'  => 'RMA-CP-'.substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Draft->value,
            'finance_only'=> false,
            'customer_id' => $customerB->id,
            'return_date' => now()->toDateString(),
        ]);
        $rmaB->items()->create([
            'product_id' => $productB->id,
            'item_id'    => $itemB->id,
            'quantity'   => '1.000',
            'unit_price' => '100.00',
            'total'      => '100.00',
        ]);

        $this->actAs($userA);

        // 403 (explicit service guard) — never the row's contents.
        $this->getJson("/api/v1/b2b/customer/return-requests/{$rmaB->hash_id}")
            ->assertStatus(403);

        // And the list never surfaces it.
        $this->getJson('/api/v1/b2b/customer/return-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_customer_sees_their_own_return_in_the_list(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        [$product, $item] = $this->productWithItem('PT-RET-06');

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-CP-'.substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Draft->value,
            'finance_only'=> false,
            'customer_id' => $customer->id,
            'return_date' => now()->toDateString(),
        ]);
        $rma->items()->create([
            'product_id' => $product->id,
            'item_id'    => $item->id,
            'quantity'   => '1.000',
            'unit_price' => '100.00',
            'total'      => '100.00',
        ]);

        $this->actAs($user);

        $this->getJson('/api/v1/b2b/customer/return-requests')
            ->assertOk()
            ->assertJsonPath('data.0.rma_number', $rma->rma_number)
            ->assertJsonPath('data.0.status', 'draft');
    }
}
