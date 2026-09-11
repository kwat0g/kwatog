<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service-level coverage for the customer-portal return entry points:
 * server-side item resolution and the create-from-portal contract.
 */
class CustomerReturnSourceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private ReturnRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        $this->svc = app(ReturnRequestService::class);
    }

    /** @return array{0: Product, 1: Item} */
    private function productWithItem(string $partNumber): array
    {
        $product = Product::create(['part_number' => $partNumber, 'name' => 'Finished part']);
        $item = Item::factory()->create([
            'code'      => $partNumber,
            'item_type' => ItemType::FinishedGood->value,
        ]);

        return [$product, $item];
    }

    private function finalizedInvoice(Customer $customer, Product $product): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-SO-'.substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => User::factory()->create()->id,
        ]);
        $invoice->items()->create([
            'revenue_account_id' => (int) Account::query()->where('code', '4010')->firstOrFail()->id,
            'product_id'         => $product->id,
            'description'        => 'Returned part',
            'quantity'           => '10.00',
            'unit_price'         => '100.00',
            'total'              => '1000.00',
        ]);

        return $invoice->load('items');
    }

    public function test_source_options_resolve_items_and_remaining_quantity(): void
    {
        $customer = Customer::factory()->create();
        [$product, $item] = $this->productWithItem('PT-SVC-01');
        $invoice = $this->finalizedInvoice($customer, $product);

        $options = $this->svc->sourceOptionsForCustomer((int) $customer->id);

        $line = $options['customer']['invoices'][0]['lines'][0];
        $this->assertSame($invoice->hash_id, $options['customer']['invoices'][0]['id']);
        $this->assertSame($product->hash_id, $line['product_id']);
        $this->assertSame($item->hash_id, $line['item_id']);
        $this->assertSame('10.000', $line['remaining_quantity']);
    }

    public function test_create_customer_return_from_portal_pins_identity_and_source_price(): void
    {
        $customer = Customer::factory()->create();
        [$product, $item] = $this->productWithItem('PT-SVC-02');
        $invoice = $this->finalizedInvoice($customer, $product);
        $sourceLine = $invoice->items->first();

        $rma = $this->svc->createCustomerReturnFromPortal((int) $customer->id, [
            'reason_code' => 'defective',
            'items'       => [[
                'source_invoice_item_id' => (int) $sourceLine->id,
                'quantity'               => '2',
                'unit_price'             => '1.00',
            ]],
        ]);

        $this->assertSame(ReturnRequestType::CustomerReturn, $rma->type);
        $this->assertSame((int) $customer->id, (int) $rma->customer_id);
        $this->assertFalse((bool) $rma->finance_only);
        $this->assertSame((int) $invoice->id, (int) $rma->invoice_id);

        $line = $rma->items()->firstOrFail();
        $this->assertSame((int) $item->id, (int) $line->item_id);
        $this->assertSame('100.00', (string) $line->unit_price);
        $this->assertSame('200.00', (string) $line->total);
    }

    public function test_create_customer_return_from_portal_rejects_a_foreign_source_line(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        [$productB] = $this->productWithItem('PT-SVC-03');
        $invoiceB = $this->finalizedInvoice($customerB, $productB);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);

        $this->svc->createCustomerReturnFromPortal((int) $customerA->id, [
            'items' => [[
                'source_invoice_item_id' => (int) $invoiceB->items->first()->id,
                'quantity'               => '1',
            ]],
        ]);
    }
}
