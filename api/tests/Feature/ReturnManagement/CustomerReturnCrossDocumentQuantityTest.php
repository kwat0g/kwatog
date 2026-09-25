<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerReturnCrossDocumentQuantityTest extends TestCase
{
    use RefreshDatabase;

    private ReturnRequestService $service;
    private User $user;
    private Customer $customer;
    private Product $product;
    private Item $item;
    private SalesOrder $order;
    private SalesOrderItem $soLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(ReturnRequestService::class);
        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::create(['part_number' => 'RMA-CROSS-01', 'name' => 'Part']);
        $this->item = Item::factory()->create(['code' => $this->product->part_number, 'item_type' => ItemType::FinishedGood->value]);
        $this->order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id, 'status' => SalesOrderStatus::Delivered,
        ]);
        $this->soLine = SalesOrderItem::factory()->create([
            'sales_order_id' => $this->order->id, 'product_id' => $this->product->id,
            'quantity' => '10', 'quantity_delivered' => '10', 'unit_price' => '100',
        ]);
    }

    private function delivery(string $quantity): Delivery
    {
        $delivery = Delivery::create([
            'delivery_number' => 'DR-T-'.substr(uniqid(), -5),
            'sales_order_id' => $this->order->id, 'created_by' => $this->user->id,
            'status' => DeliveryStatus::Delivered, 'scheduled_date' => now()->toDateString(),
            'delivered_at' => now(),
        ]);
        $delivery->items()->create([
            'sales_order_item_id' => $this->soLine->id, 'quantity' => $quantity, 'unit_price' => '100',
        ]);

        return $delivery->load('items');
    }

    private function invoice(?Delivery $delivery = null): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => 'IV-T-'.substr(uniqid(), -5),
            'customer_id' => $this->customer->id, 'sales_order_id' => $this->order->id,
            'delivery_id' => $delivery?->id, 'status' => 'finalized', 'created_by' => $this->user->id,
            'subtotal' => '1000', 'vat_amount' => '120', 'total_amount' => '1120', 'balance' => '1120',
            'date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
        ]);
        $invoice->items()->create([
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'product_id' => $this->product->id, 'description' => 'Part', 'quantity' => $delivery?->items->first()->quantity ?? '10',
            'unit_price' => '100', 'total' => '1000',
        ]);

        return $invoice->load('items');
    }

    private function create(string $source, int $id, string $quantity, ?Invoice $invoice = null)
    {
        return $this->service->create([
            'type' => 'customer_return', 'customer_id' => $this->customer->id,
            'sales_order_id' => $this->order->id, 'invoice_id' => $invoice?->id,
            'items' => [[
                'product_id' => $this->product->id, 'item_id' => $this->item->id,
                'source_'.$source.'_id' => $id, 'quantity' => $quantity,
            ]],
        ], $this->user);
    }

    public function test_invoice_so_and_delivery_share_one_physical_cap_and_both_option_surfaces_agree(): void
    {
        $delivery = $this->delivery('10');
        $invoice = $this->invoice($delivery);
        $this->create('invoice_item', $invoice->items->first()->id, '6', $invoice);
        $this->create('sales_order_item', $this->soLine->id, '4');

        $options = $this->service->sourceOptionsForCustomer($this->customer->id)['customer'];
        $this->assertSame('0.000', $options['invoices'][0]['lines'][0]['remaining_quantity']);
        $this->assertSame('0.000', $options['salesOrders'][0]['lines'][0]['remaining_quantity']);
        $this->assertSame('0.000', $options['deliveries'][0]['lines'][0]['remaining_quantity']);

        try {
            $this->create('delivery_item', $delivery->items->first()->id, '0.001');
            $this->fail('The same shipped units were returned twice.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('remaining quantity', $e->getMessage());
        }
    }

    public function test_same_line_repeated_in_one_request_is_rejected_atomically(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->service->create([
            'type' => 'customer_return', 'customer_id' => $this->customer->id,
            'sales_order_id' => $this->order->id,
            'items' => array_fill(0, 2, [
                'product_id' => $this->product->id, 'item_id' => $this->item->id,
                'source_sales_order_item_id' => $this->soLine->id, 'quantity' => '6',
            ]),
        ], $this->user);
    }

    public function test_cancel_releases_the_shared_pool(): void
    {
        $invoice = $this->invoice();
        $rma = $this->create('invoice_item', $invoice->items->first()->id, '10', $invoice);
        $this->service->cancel($rma);
        $this->assertSame(ReturnRequestStatus::Cancelled, $rma->fresh()->status);
        $this->create('sales_order_item', $this->soLine->id, '10');
    }

    public function test_two_separate_deliveries_can_each_be_returned_but_the_same_delivery_cannot_be_overreturned(): void
    {
        $first = $this->delivery('5');
        $second = $this->delivery('5');
        $invoice = $this->invoice($first);
        $this->create('invoice_item', $invoice->items->first()->id, '5', $invoice);
        $this->create('delivery_item', $second->items->first()->id, '5');

        $this->expectException(BusinessRuleException::class);
        $this->create('delivery_item', $first->items->first()->id, '0.001');
    }

    public function test_portal_entry_observes_existing_internal_reservations(): void
    {
        $invoice = $this->invoice();
        $this->create('sales_order_item', $this->soLine->id, '10');

        $this->expectException(BusinessRuleException::class);
        $this->service->createCustomerReturnFromPortal($this->customer->id, [
            'items' => [['source_invoice_item_id' => $invoice->items->first()->id, 'quantity' => '1']],
        ], $this->user);
    }
}
