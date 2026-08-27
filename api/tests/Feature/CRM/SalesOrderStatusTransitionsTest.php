<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-2 — Wire SalesOrder lifecycle transitions.
 *
     * Asserts the four mark* helpers are idempotent, null-safe, reject
     * disallowed transitions, and that the WorkOrder / Delivery / Invoice
     * hooks advance the SO through the full O2C chain.
 */
class SalesOrderStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderService $soService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->soService = app(SalesOrderService::class);
    }

    public function test_mark_in_production_promotes_confirmed_so(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Confirmed);
        $this->soService->markInProduction($so->id);

        $this->assertSame(SalesOrderStatus::InProduction->value, $so->fresh()->status->value);
    }

    public function test_mark_in_production_is_idempotent(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Confirmed);
        $this->soService->markInProduction($so->id);
        $updatedAtAfterFirst = $so->fresh()->updated_at;

        // Sleep one second so a second update would visibly bump updated_at.
        sleep(1);
        $this->soService->markInProduction($so->id);

        $this->assertSame(SalesOrderStatus::InProduction->value, $so->fresh()->status->value);
        $this->assertEquals(
            $updatedAtAfterFirst->toIso8601String(),
            $so->fresh()->updated_at->toIso8601String(),
            'Second call must be a no-op — updated_at should not change.',
        );
    }

    public function test_mark_in_production_rejects_cancelled_so(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Cancelled);
        try {
            $this->soService->markInProduction($so->id);
            $this->fail('A cancelled sales order must reject downstream lifecycle promotion.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->assertSame(SalesOrderStatus::Cancelled->value, $so->fresh()->status->value);
    }

    public function test_mark_in_production_is_noop_on_null_id(): void
    {
        // Just assert no exception is thrown and no rows change.
        $countBefore = SalesOrder::count();
        $this->soService->markInProduction(null);
        $this->assertSame($countBefore, SalesOrder::count());
    }

    public function test_mark_delivered_promotes_from_in_production(): void
    {
        $so = $this->makeSo(SalesOrderStatus::InProduction);
        $this->soService->markDelivered($so->id);

        $this->assertSame(SalesOrderStatus::Delivered->value, $so->fresh()->status->value);
    }

    public function test_mark_partially_delivered_promotes_from_in_production(): void
    {
        $so = $this->makeSo(SalesOrderStatus::InProduction);
        $this->soService->markPartiallyDelivered($so->id);

        $this->assertSame(SalesOrderStatus::PartiallyDelivered->value, $so->fresh()->status->value);
    }

    /**
     * The transition table is FORWARD-ONLY, not strictly linear, and nothing
     * used to assert that. When it was narrowed to a strict chain, confirming
     * any delivery for an order that never entered production threw and rolled
     * the delivery confirmation back — 8 suites went red at once.
     *
     * `in_production` is set only by WorkOrderService::start(). An order filled
     * from finished-goods stock has no work order to start, and
     * DeliveryService::create() does not require the SO to be in production,
     * so `confirmed → delivered` is a reachable, supported path.
     *
     */
    #[DataProvider('forwardSkipProvider')]
    public function test_forward_skips_are_permitted(
        SalesOrderStatus $from,
        string $method,
        SalesOrderStatus $expected,
    ): void {
        $so = $this->makeSo($from);

        $this->soService->{$method}($so->id);

        $this->assertSame($expected->value, $so->fresh()->status->value,
            "{$from->value} → {$expected->value} must be permitted; it is reachable in production.");
    }

    /**
     * @return array<string, array{0: SalesOrderStatus, 1: string, 2: SalesOrderStatus}>
     */
    public static function forwardSkipProvider(): array
    {
        return [
            // Fulfilled from stock — production was never entered.
            'confirmed → delivered' => [
                SalesOrderStatus::Confirmed, 'markDelivered', SalesOrderStatus::Delivered,
            ],
            'confirmed → partially_delivered' => [
                SalesOrderStatus::Confirmed, 'markPartiallyDelivered', SalesOrderStatus::PartiallyDelivered,
            ],
            // Advance / proforma billing before any delivery exists.
            'confirmed → invoiced' => [
                SalesOrderStatus::Confirmed, 'markInvoiced', SalesOrderStatus::Invoiced,
            ],
            'in_production → invoiced' => [
                SalesOrderStatus::InProduction, 'markInvoiced', SalesOrderStatus::Invoiced,
            ],
            // Billing a partial delivery. InvoiceService::finalize() calls
            // markInvoiced() AFTER posting the journal entry, in the same
            // transaction — refusing this rolled back a posted JE.
            'partially_delivered → invoiced' => [
                SalesOrderStatus::PartiallyDelivered, 'markInvoiced', SalesOrderStatus::Invoiced,
            ],
        ];
    }

    /**
     * The other half of the same contract: forward-only must still mean no
     * going back, and no leaving a terminal state.
     *
     */
    #[DataProvider('refusedTransitionProvider')]
    public function test_backwards_and_terminal_transitions_are_refused(
        SalesOrderStatus $from,
        string $method,
    ): void {
        $so = $this->makeSo($from);

        try {
            $this->soService->{$method}($so->id);
            $this->fail("{$from->value} must not accept {$method}().");
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->assertSame($from->value, $so->fresh()->status->value,
            'A refused transition must leave the status untouched.');
    }

    /**
     * @return array<string, array{0: SalesOrderStatus, 1: string}>
     */
    public static function refusedTransitionProvider(): array
    {
        return [
            'invoiced is terminal'           => [SalesOrderStatus::Invoiced, 'markDelivered'],
            'cancelled is terminal'          => [SalesOrderStatus::Cancelled, 'markDelivered'],
            'draft must be confirmed first'  => [SalesOrderStatus::Draft, 'markDelivered'],
            'delivered cannot go back'       => [SalesOrderStatus::Delivered, 'markPartiallyDelivered'],
            'invoiced cannot go back'        => [SalesOrderStatus::Invoiced, 'markInProduction'],
        ];
    }

    public function test_backwards_transition_throws_and_records_reason(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Delivered);
        try {
            $this->soService->markInProduction($so->id);
            $this->fail('A backwards transition must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->assertSame(SalesOrderStatus::Delivered->value, $so->fresh()->status->value);
        $this->assertDatabaseHas('sales_order_transition_rejections', [
            'sales_order_id' => $so->id,
            'from_status' => SalesOrderStatus::Delivered->value,
            'to_status' => SalesOrderStatus::InProduction->value,
            'reason_code' => 'illegal_transition',
        ]);
    }

    public function test_delivery_confirm_promotes_so_to_delivered_when_fully_covered(): void
    {
        $user = $this->makeUser();
        [$so, $soItem] = $this->makeSoWithLine(
            qty: '10',
            price: '50.00',
            status: SalesOrderStatus::InProduction,
        );

        [$delivery] = $this->makeDelivery($so, $soItem, qty: '10', user: $user);
        $this->addProof($delivery, $user);

        app(DeliveryService::class)->confirm($delivery->fresh(), $user);

        $this->assertSame(SalesOrderStatus::Delivered->value, $so->fresh()->status->value,
            'Full coverage must promote SO to Delivered.');
    }

    public function test_delivery_confirm_promotes_so_to_partially_delivered_when_partial(): void
    {
        $user = $this->makeUser();
        [$so, $soItem] = $this->makeSoWithLine(
            qty: '10',
            price: '50.00',
            status: SalesOrderStatus::InProduction,
        );

        [$delivery] = $this->makeDelivery($so, $soItem, qty: '4', user: $user);
        $this->addProof($delivery, $user);

        app(DeliveryService::class)->confirm($delivery->fresh(), $user);

        $this->assertSame(SalesOrderStatus::PartiallyDelivered->value, $so->fresh()->status->value,
            'Partial coverage must promote SO to PartiallyDelivered.');
    }

    public function test_invoice_finalize_promotes_so_to_invoiced(): void
    {
        $user = $this->makeArClerk();
        [$so, $soItem] = $this->makeSoWithLine(
            qty: '5',
            price: '100.00',
            status: SalesOrderStatus::Delivered,
        );

        $revenueId = (int) Account::query()->where('code', '4010')->value('id');
        [$delivery, $deliveryItem] = $this->makeDelivery($so, $soItem, qty: '5', user: $user);
        $delivery->forceFill(['status' => DeliveryStatus::Confirmed->value])->save();

        $svc = app(InvoiceService::class);
        $invoice = $svc->create([
            'customer_id'    => app('hashids')->encode((int) $so->customer_id),
            'date'           => now()->toDateString(),
            'is_vatable'     => true,
            'items'          => [[
                'revenue_account_id' => app('hashids')->encode($revenueId),
                'description'        => 'Test line',
                'quantity'           => '5',
                'unit_price'         => '100.00',
                'source_delivery_item_id' => $deliveryItem->hash_id,
            ]],
            'sales_order_id' => app('hashids')->encode((int) $so->id),
            'delivery_id'    => $delivery->hash_id,
        ], $user);

        $this->assertSame((int) $so->id, (int) $invoice->fresh()->sales_order_id,
            'InvoiceService::create must persist sales_order_id when passed.');

        $svc->finalize($invoice->fresh(), $user);

        $this->assertSame(SalesOrderStatus::Invoiced->value, $so->fresh()->status->value);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name' => 'SO Transitions Test ' . uniqid(),
            'slug' => 'so_trans_test_' . uniqid(),
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.confirm'],
            ['name' => 'Confirm Delivery', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeArClerk(): User
    {
        $role = Role::create([
            'name' => 'AR Clerk Test ' . uniqid(),
            'slug' => 'ar_clerk_so_' . uniqid(),
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'accounting.invoices.create'],
            ['name' => 'Create Invoices', 'module' => 'accounting'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeSo(SalesOrderStatus $status): SalesOrder
    {
        $customer = Customer::create([
            'name'               => 'Cust ' . uniqid(),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $user = $this->makeUser();

        return SalesOrder::create([
            'so_number'    => 'SO-TR-' . substr(uniqid(), -10),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '0.00',
            'vat_amount'   => '0.00',
            'total_amount' => '0.00',
            'status'       => $status->value,
            'created_by'   => $user->id,
        ]);
    }

    /**
     * @return array{0: SalesOrder, 1: SalesOrderItem}
     */
    private function makeSoWithLine(string $qty, string $price, SalesOrderStatus $status): array
    {
        $so = $this->makeSo($status);

        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Wiper Bushing ' . uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        $item = SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => $qty,
            'unit_price'         => $price,
            'total'              => bcmul($qty, $price, 2),
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(7)->toDateString(),
        ]);

        return [$so, $item];
    }

    /**
     * @return array{0: Delivery, 1: DeliveryItem}
     */
    private function makeDelivery(SalesOrder $so, SalesOrderItem $soItem, string $qty, User $user): array
    {
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-TR-' . substr(uniqid(), -10),
            'sales_order_id'  => $so->id,
            'status'          => DeliveryStatus::Delivered->value,
            'scheduled_date'  => now()->toDateString(),
            'delivered_at'    => now(),
            'created_by'      => $user->id,
        ]);

        $item = DeliveryItem::create([
            'delivery_id'         => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'inspection_id'       => null,
            'quantity'            => $qty,
            'unit_price'          => (string) $soItem->unit_price,
        ]);

        return [$delivery, $item];
    }

    private function addProof(Delivery $d, User $by): void
    {
        DeliveryProof::create([
            'delivery_id' => $d->id,
            'proof_type'  => 'photo',
            'file_name'   => 'receipt.jpg',
            'file_path'   => "deliveries/{$d->id}/receipt.jpg",
            'mime_type'   => 'image/jpeg',
            'uploaded_by' => $by->id,
        ]);
    }
}
