<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ReceivesProductionOutput;
use Tests\TestCase;

/**
 * Mission Phase 2 — end-to-end ORDER-TO-CASH chain over the real HTTP API,
 * every step performed by its real seeded role (never system_admin).
 *
 * Chain: sales officer drafts + confirms SO → impex creates the delivery from
 * produced, outgoing-QC-passed stock (dispatchableDelivery fixture) → assigns
 * van + driver → loading → in_transit → delivered → proof of delivery →
 * confirm (auto-stages a draft invoice) → finance finalizes (delivery-gated)
 * → finance collects → invoice paid → SO reconciled.
 *
 * Adversarial probes ride along: skip-ahead (delivery before confirm, confirm
 * before delivered, collect on draft), wrong actor (employee confirming,
 * sales officer collecting), wrong time (double confirm, over-collection),
 * and integrity (invoice math, collection GL, SO reconciliation).
 */
class OrderToCashChainTest extends TestCase
{
    use ReceivesProductionOutput;
    use RefreshDatabase;

    private User $soOfficer;
    private User $impex;
    private User $finance;
    private User $financeChecker;
    private User $vp;
    private User $driver;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->soOfficer = $make('sales_officer');
        $this->impex = $make('impex_officer');
        $this->finance = $make('finance_officer');
        $this->financeChecker = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->driver = $make('driver');
        $this->employee = $make('employee');

        // Queued automation listeners (auto-invoice on confirm) attribute their
        // writes to a configured automation actor; without one they throw.
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        Storage::fake('local');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @template T @param class-string<T> $class @return T */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    /**
     * A postable (leaf) asset account. Header accounts — any account with
     * children — refuse new postings.
     */
    private function cashAccount(): Account
    {
        $cash = Account::query()
            ->where('type', 'asset')
            ->where('is_active', true)
            ->whereNotExists(function ($q): void {
                $q->selectRaw(1)
                    ->from('accounts as children')
                    ->whereColumn('children.parent_id', 'accounts.id');
            })
            ->orderBy('code')
            ->first();
        $this->assertNotNull($cash, 'seeded COA must contain a postable (leaf) asset account');

        return $cash;
    }

    /** SO pricing resolves through an active customer+product price agreement. */
    private function makePriceAgreement(Customer $customer, Product $product, string $price = '100.00'): void
    {
        \App\Modules\CRM\Models\PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => $price,
            'pricing_method' => \App\Modules\CRM\Enums\PricingMethod::Flat,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addYear()->toDateString(),
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Toyota dealer '.substr(uniqid(), -6),
            'is_active' => true,
            'payment_terms_days' => 30,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'part_number' => strtoupper(substr(uniqid('PB-'), 0, 12)),
            'name' => 'Wiper bushing '.substr(uniqid(), -6),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);
    }

    /** Draft SO via the real HTTP endpoint as the sales officer. */
    private function createSo(Customer $customer, Product $product, string $qty = '10.00'): SalesOrder
    {
        $res = $this->actingAs($this->soOfficer)
            ->postJson('/api/v1/crm/sales-orders', [
                'customer_id' => $customer->hash_id,
                'date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => $qty,
                    'delivery_date' => now()->addDay()->toDateString(),
                ]],
            ]);
        $res->assertCreated();

        $so = $this->fromApiId(SalesOrder::class, $res->json('data.id'));
        $this->assertSame(SalesOrderStatus::Draft->value, $so->status->value, 'SO must start as a draft');

        return $so;
    }

    private function confirmSo(SalesOrder $so): void
    {
        $this->actingAs($this->soOfficer)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertOk();
        $so->refresh();
        $this->assertSame(SalesOrderStatus::Confirmed->value, $so->status->value);
    }

    /**
     * Full delivery walk as impex: assign van + driver, loading → in_transit →
     * delivered, upload proof, confirm (auto-invoice fires). Delivered through
     * the status route; confirmation through the dedicated endpoint.
     */
    private function deliverAndConfirm(Delivery $delivery): Invoice
    {
        $vehicle = Vehicle::create([
            'plate_number' => 'FD-'.substr(uniqid(), -8),
            'name' => 'O2C test van '.substr(uniqid(), -6),
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);

        $this->actingAs($this->impex)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'vehicle_id' => $vehicle->id,
                'driver_id' => $this->driver->id,
                'reason' => 'Scheduled customer run',
            ])
            ->assertOk();

        foreach (['loading', 'in_transit', 'delivered'] as $status) {
            $this->actingAs($this->impex)
                ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", [
                    'status' => $status,
                ])
                ->assertOk();
        }
        // NOTE: refresh() re-loads every loaded relation by name; the fixture
        // model carries the computed `preparation` payload (not an Eloquent
        // relation), so a plain refresh() throws RelationNotFoundException.
        // Re-read through a fresh query instead.
        $delivery = Delivery::query()->findOrFail($delivery->id);
        $this->assertSame(DeliveryStatus::Delivered->value, $delivery->status->value);

        // ADV7 — confirmation is proof-gated. Try without, then upload.
        $this->actingAs($this->impex)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/confirm")
            ->assertStatus(422);

        $this->actingAs($this->impex)
            ->post("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/proofs", [
                'proof_type' => 'signed_dr',
                'file' => UploadedFile::fake()->image('signed-dr.jpg'),
                'notes' => 'Signed by receiver',
            ])
            ->assertSuccessful();

        $this->actingAs($this->impex)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/confirm")
            ->assertOk();

        $delivery = Delivery::query()->findOrFail($delivery->id);
        $this->assertSame(DeliveryStatus::Confirmed->value, $delivery->status->value);
        $this->assertNotNull($delivery->invoice_id, 'confirming must stage the draft invoice');

        return Invoice::query()->findOrFail($delivery->invoice_id);
    }

    private function finalizeInvoice(Invoice $invoice): void
    {
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/invoices/{$invoice->hash_id}/finalize")
            ->assertOk();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Finalized->value, $invoice->status->value);
        $this->assertNotNull($invoice->invoice_number, 'finalizing must assign the invoice number');
    }

    private function collect(Invoice $invoice, string $amount, string $idemKey): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->finance)
            ->postJson("/api/v1/invoices/{$invoice->hash_id}/collections", [
                'cash_account_id' => $this->cashAccount()->hash_id,
                'collection_date' => now()->toDateString(),
                'amount' => $amount,
                'payment_method' => 'bank_transfer',
                'idempotency_key' => $idemKey,
            ]);
    }

    // ------------------------------------------------------------------
    // 1. HAPPY PATH — SO to collected cash, every step as its real role
    // ------------------------------------------------------------------

    public function test_full_order_to_cash_chain_with_each_step_as_its_real_role(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makePriceAgreement($customer, $product);

        $so = $this->createSo($customer, $product, '5.00');

        // -- Wrong actor: a bare employee cannot confirm SOs ------------------
        $this->actingAs($this->employee)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertForbidden();

        $this->confirmSo($so);

        // The dispatchable fixture manufactures its own confirmed SO upstream
        // (produced stock + passed outgoing QC) — the chain's SO is that one.
        $delivery = $this->dispatchableDelivery($this->impex, '5.00', $this->driver)[0];
        $chainSo = SalesOrder::query()->findOrFail($delivery->sales_order_id);

        // -- Wrong time: the status route must refuse jumping to delivered ----
        $this->actingAs($this->impex)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", [
                'status' => 'delivered',
            ])
            ->assertStatus(422);

        $invoice = $this->deliverAndConfirm($delivery);

        // -- Invoice math: 5 × 15.00 (fixture SO price), consistent VAT ------
        $this->assertSame('75.00', (string) $invoice->subtotal);
        $expectedTotal = (float) $invoice->subtotal + (float) $invoice->vat_amount;
        $this->assertEqualsWithDelta($expectedTotal, (float) $invoice->total_amount, 0.001,
            'total must equal subtotal + VAT');
        if ($invoice->is_vatable) {
            $this->assertEqualsWithDelta((float) $invoice->subtotal * 0.12, (float) $invoice->vat_amount, 0.001,
                'VAT must be 12% of the subtotal');
        }

        // -- Wrong actor: sales officer holds no collection permission --------
        $this->actingAs($this->soOfficer)
            ->postJson("/api/v1/invoices/{$invoice->hash_id}/collections", [
                'cash_account_id' => $this->cashAccount()->hash_id,
                'collection_date' => now()->toDateString(),
                'amount' => '10.00',
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        // -- Skip ahead: collecting while still a draft is refused ------------
        $this->collect($invoice, '10.00', 'o2c-draft-'.substr(uniqid(), -8))
            ->assertStatus(422);

        $this->finalizeInvoice($invoice);

        // -- Wrong time: finalizing twice is refused --------------------------
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/invoices/{$invoice->hash_id}/finalize")
            ->assertStatus(422);

        // -- Partial then full collection closes the invoice ------------------
        $this->collect($invoice, '25.00', 'o2c-p1-'.substr(uniqid(), -8))
            ->assertSuccessful();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Partial->value, $invoice->status->value,
            'a partial collection must move the invoice to partial');

        $balance = (string) $invoice->balance;
        $this->collect($invoice, $balance, 'o2c-p2-'.substr(uniqid(), -8))
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid->value, $invoice->status->value, 'full collection closes the invoice');
        $this->assertSame('0.00', (string) $invoice->balance);

        // -- Integrity: a collection carries its posted GL entry --------------
        $collection = Collection::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(2, $collection->count(), 'exactly two collections must exist');
        $posted = $collection->filter(fn ($c) => $c->journal_entry_id !== null);
        $this->assertSame(2, $posted->count(), 'every collected payment must carry its journal entry');

        // -- Wrong time: collecting beyond a zero balance is refused ----------
        $this->collect($invoice, '1.00', 'o2c-over-'.substr(uniqid(), -8))
            ->assertStatus(422);

        // -- Reconciliation: the SO followed the chain ------------------------
        $chainSo->refresh();
        $this->assertContains(
            $chainSo->status->value,
            [SalesOrderStatus::Invoiced->value, SalesOrderStatus::Paid->value, SalesOrderStatus::Closed->value, SalesOrderStatus::Delivered->value],
            "SO must reconcile to a delivered/invoiced state, got {$chainSo->status->value}",
        );
    }

    // ------------------------------------------------------------------
    // 2. DELIVERY GATE — a standard invoice cannot be finalized undelivered
    // ------------------------------------------------------------------

    public function test_standard_invoice_cannot_be_finalized_without_a_confirmed_delivery(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makePriceAgreement($customer, $product);
        $so = $this->createSo($customer, $product);
        $this->confirmSo($so);

        // Draft invoice with no delivery linkage is allowed…
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $res = $this->actingAs($this->finance)
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->hash_id,
                'date' => now()->toDateString(),
                'is_vatable' => true,
                'sales_order_id' => $so->hash_id,
                'items' => [[
                    'revenue_account_id' => $revenue->hash_id,
                    'description' => 'Wiper bushings',
                    'quantity' => '5.00',
                    'unit_price' => '100.00',
                ]],
            ]);
        $res->assertCreated();
        $invoice = $this->fromApiId(Invoice::class, $res->json('data.id'));
        $this->assertSame(InvoiceStatus::Draft->value, $invoice->status->value);

        // …but finalize is delivery-gated: no confirmed delivered quantity.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/invoices/{$invoice->hash_id}/finalize")
            ->assertStatus(422);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft->value, $invoice->status->value);

        // And while stuck as a draft, collection is refused too.
        $this->collect($invoice, '10.00', 'o2c-gate-'.substr(uniqid(), -8))
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 3. OVER-COLLECTION — the centavo contract holds at the API edge
    // ------------------------------------------------------------------

    public function test_collection_amounts_are_bounded_by_the_outstanding_balance(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makePriceAgreement($customer, $product);
        $so = $this->createSo($customer, $product, '5.00');
        $this->confirmSo($so);
        $delivery = $this->dispatchableDelivery($this->impex, '5.00', $this->driver)[0];
        $invoice = $this->deliverAndConfirm($delivery);
        $this->finalizeInvoice($invoice);

        // One centavo over the balance is refused, not silently clipped.
        $over = (string) ((float) $invoice->balance + 0.01);
        $this->collect($invoice, $over, 'o2c-bound-'.substr(uniqid(), -8))
            ->assertStatus(422);

        // An idempotent replay returns the original collection.
        $partial = (string) ((float) $invoice->balance / 2);
        $key = 'o2c-idem-'.substr(uniqid(), -8);
        $first = $this->collect($invoice, $partial, $key);
        $first->assertSuccessful();
        $second = $this->collect($invoice, $partial, $key);
        $second->assertSuccessful();
        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'idempotent replay must return the original collection',
        );
        $this->assertSame(1, Collection::query()
            ->where('invoice_id', $invoice->id)
            ->count(), 'replay must not create a second collection row');
    }

    // ------------------------------------------------------------------
    // 4. DOUBLE CONFIRM — confirmation is idempotent-safe, not repeatable
    // ------------------------------------------------------------------

    public function test_delivery_confirmation_cannot_be_replayed_or_status_routed(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makePriceAgreement($customer, $product);
        $so = $this->createSo($customer, $product, '5.00');
        $this->confirmSo($so);
        $delivery = $this->dispatchableDelivery($this->impex, '5.00', $this->driver)[0];

        $this->deliverAndConfirm($delivery);

        // Re-confirming a confirmed delivery is a deliberate idempotent no-op
        // (the concurrency guard folds a losing racer into the same state).
        $this->actingAs($this->impex)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/confirm")
            ->assertOk();

        // Wrong time: routing a confirmed delivery backwards via the generic
        // status route is refused (confirm has its own audited endpoint).
        $this->actingAs($this->impex)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", [
                'status' => 'confirmed',
            ])
            ->assertStatus(422);

        // Only one draft invoice was staged by the double attempts.
        $this->assertSame(1, Delivery::query()->whereKey($delivery->id)->value('invoice_id') !== null ? 1 : 0);
    }

    // ------------------------------------------------------------------
    // 5. WRONG ACTOR — delivery dispatch is impex's surface
    // ------------------------------------------------------------------

    public function test_delivery_dispatch_is_refused_for_roles_without_the_permission(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makePriceAgreement($customer, $product);
        $so = $this->createSo($customer, $product, '5.00');
        $this->confirmSo($so);
        $delivery = $this->dispatchableDelivery($this->impex, '5.00', $this->driver)[0];

        // Sales officer cannot move someone else's delivery.
        $this->actingAs($this->soOfficer)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", [
                'status' => 'loading',
            ])
            ->assertForbidden();

        // The driver cannot self-dispatch either.
        $this->actingAs($this->driver)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", [
                'status' => 'loading',
            ])
            ->assertForbidden();
    }
}
