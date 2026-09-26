<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chain's Invoiced and Paid steps are facts about the invoice, not about
 * the status.
 *
 * Two ways that used to go wrong: an order sitting in `delivered` with its
 * invoice already finalized showed Invoiced as pending, and an order that went
 * straight from delivered to closed skipped the Invoiced and Paid transitions
 * entirely, so both steps were done and dateless. The status itself was never
 * mislabelled — `paid` has always required every invoice to be paid.
 */
class SalesOrderChainBillingDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /** @return array{0: SalesOrder, 1: Invoice, 2: User} */
    private function deliveredOrder(string $invoiceStatus): array
    {
        $by = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::create(['part_number' => 'BILL-'.uniqid(), 'name' => 'Billed part']);
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Delivered,
            'subtotal' => '1000.00', 'vat_amount' => '0.00', 'total_amount' => '1000.00',
            'delivered_at' => now(),
            'created_by' => $by->id,
        ]);
        SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id, 'product_id' => $product->id,
            'quantity' => 10, 'quantity_delivered' => 10, 'unit_price' => 100,
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-BILL-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'sales_order_id' => $so->id,
            'status' => $invoiceStatus,
            'subtotal' => '1000.00', 'vat_amount' => '0.00', 'total_amount' => '1000.00',
            'amount_paid' => $invoiceStatus === 'paid' ? '1000.00' : '0.00',
            'balance' => $invoiceStatus === 'paid' ? '0.00' : '1000.00',
            'date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(27)->toDateString(),
            'created_by' => $by->id,
        ]);

        return [$so, $invoice, $by];
    }

    /** @return array<string, array{state: string, date: ?string}> */
    private function tiles(SalesOrder $so): array
    {
        return collect(app(SalesOrderService::class)->chain($so->fresh()))
            ->keyBy('key')
            ->map(static fn (array $tile): array => ['state' => $tile['state'], 'date' => $tile['date'] ?? null])
            ->all();
    }

    public function test_a_finalized_invoice_marks_the_invoiced_step_done_even_before_payment(): void
    {
        [$so] = $this->deliveredOrder('finalized');

        $tiles = $this->tiles($so);

        $this->assertSame('done', $tiles['invoiced']['state'], 'A finalized invoice means the order has been billed.');
        $this->assertSame(now()->subDays(3)->toDateString(), $tiles['invoiced']['date'], 'Dated from the invoice, not from the status change.');
        $this->assertSame('pending', $tiles['paid']['state'], 'Nothing has been collected yet.');
        $this->assertSame(SalesOrderStatus::Delivered, $so->fresh()->status, 'The status is not "paid" until it is.');
    }

    public function test_closing_straight_after_the_last_collection_dates_both_steps(): void
    {
        [$so, $invoice, $by] = $this->deliveredOrder('paid');
        Collection::create([
            'invoice_id' => $invoice->id,
            'cash_account_id' => (int) Account::query()->where('code', '1020')->firstOrFail()->id,
            'collection_date' => now()->toDateString(),
            'amount' => '1000.00',
            'payment_method' => 'bank_transfer',
            'created_by' => $by->id,
        ]);

        app(SalesOrderService::class)->synchronizeCompletionState($so->id);

        $closed = $so->fresh();
        $this->assertSame(SalesOrderStatus::Closed, $closed->status);
        $this->assertNotNull($closed->invoiced_at, 'Closing in one step must still record when the order was billed.');
        $this->assertSame(now()->subDays(3)->toDateString(), $closed->invoiced_at->toDateString());
        $this->assertNotNull($closed->paid_at, 'Closing in one step must still record when it was settled.');
        $this->assertSame(now()->toDateString(), $closed->paid_at->toDateString());

        $tiles = $this->tiles($closed);
        $this->assertSame('done', $tiles['invoiced']['state']);
        $this->assertSame($closed->invoiced_at->toDateString(), $tiles['invoiced']['date']);
        $this->assertSame('done', $tiles['paid']['state']);
        $this->assertSame($closed->paid_at->toDateString(), $tiles['paid']['date']);
    }

    public function test_a_draft_invoice_leaves_the_invoiced_step_pending(): void
    {
        [$so] = $this->deliveredOrder('draft');

        $tiles = $this->tiles($so);

        $this->assertSame('pending', $tiles['invoiced']['state'], 'A delivery is not billed until the invoice is posted.');
        $this->assertNull($tiles['invoiced']['date']);
    }
}
