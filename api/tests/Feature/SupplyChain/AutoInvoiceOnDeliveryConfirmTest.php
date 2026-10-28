<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Listeners\NotifyFinanceOnDeliveryConfirmed;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Listeners\CreateDraftInvoiceOnDeliveryInvoiceRequested;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C-1 — Auto-invoice on delivery confirm.
 *
 * Verifies that DeliveryService::confirm now resolves revenue_account_id per
 * line (product-override → settings default), that misconfiguration surfaces
 * to AR clerks via a notification, and that the delivery confirm itself never
 * fails because of an invoice-side problem.
 */
class AutoInvoiceOnDeliveryConfirmTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
        $this->seed(DashboardRoleLayoutSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->svc = app(DeliveryService::class);
    }

    public function test_happy_path_creates_invoice_with_default_revenue_account(): void
    {
        $user = $this->makeUser();
        $defaultId = Account::query()->where('code', '4010')->value('id');
        $this->assertNotNull($defaultId, 'COA seeder must provide account 4010 (Sales Revenue).');

        [$delivery, $item] = $this->seedDeliveryWithLine(
            $user,
            productRevenueAccountId: null,
            qty: '5',
            price: '100.00',
        );

        $this->addProof($delivery, $user);

        $invoicesBefore = Invoice::count();

        $confirmed = $this->svc->confirm($delivery, $user);

        $this->assertSame('confirmed', $confirmed->status->value);
        $this->assertSame($invoicesBefore + 1, Invoice::count(), 'Auto-invoice must be created.');

        $delivery = $delivery->fresh();
        $this->assertNotNull($delivery->invoice_id, 'Delivery.invoice_id must be linked.');
        $this->assertSame(DeliveryInvoiceHandoffStatus::Generated, $delivery->invoice_handoff_status);

        $invoice = Invoice::with('items')->find($delivery->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertCount(1, $invoice->items);

        $line = $invoice->items->first();
        $this->assertSame((int) $defaultId, (int) $line->revenue_account_id);
        $this->assertSame('500.00', (string) $line->total);
    }

    public function test_product_revenue_account_override_takes_precedence(): void
    {
        $user = $this->makeUser();
        $otherId = Account::query()->where('code', '4020')->value('id'); // Other Income — distinct from default 4010
        $defaultId = Account::query()->where('code', '4010')->value('id');
        $this->assertNotNull($otherId);
        $this->assertNotSame($otherId, $defaultId);

        [$delivery] = $this->seedDeliveryWithLine(
            $user,
            productRevenueAccountId: (int) $otherId,
            qty: '2',
            price: '250.00',
        );

        $this->addProof($delivery, $user);

        $confirmed = $this->svc->confirm($delivery, $user);
        $this->assertSame('confirmed', $confirmed->status->value);

        $invoice = Invoice::with('items')->find($confirmed->fresh()->invoice_id);
        $this->assertNotNull($invoice, 'Auto-invoice must exist.');
        $line = $invoice->items->first();
        $this->assertSame((int) $otherId, (int) $line->revenue_account_id,
            'Product-level revenue_account_id must override the default.');
        $this->assertSame('500.00', (string) $line->total);
    }

    public function test_misconfigured_invoice_handoff_is_counted_and_filtered_for_finance(): void
    {
        // Misconfigure: drop the setting AND null any product override AND
        // ensure no account with code '4010' exists either, so resolution
        // ultimately returns null.
        DB::table('settings')->where('key', 'accounting.default_sales_revenue_account_code')->delete();
        DB::table('accounts')->where('code', '4010')->delete();

        $arClerk = $this->makeArClerk(canViewDelivery: true);
        $financeWithoutDeliveryAccess = $this->makeArClerk();
        $user = $this->makeUser();

        [$delivery] = $this->seedDeliveryWithLine(
            $user,
            productRevenueAccountId: null,
            qty: '3',
            price: '40.00',
        );

        $this->addProof($delivery, $user);

        $invoicesBefore = Invoice::count();
        $notificationsBefore = DB::table('notifications')->count();

        $confirmed = $this->svc->confirm($delivery, $user);

        $this->assertSame('confirmed', $confirmed->status->value,
            'Delivery must still confirm even when auto-invoice fails.');
        $this->assertNull($confirmed->fresh()->invoice_id,
            'No invoice should be linked when the default account is misconfigured.');
        $this->assertSame(
            DeliveryInvoiceHandoffStatus::ManualRequired,
            $confirmed->fresh()->invoice_handoff_status,
            'The failed invoice handoff must remain visible on the confirmed delivery.',
        );
        $this->assertSame($invoicesBefore, Invoice::count(),
            'No invoice row should have been created.');

        $request = DB::table('event_outbox')
            ->where('event_type', DeliveryInvoiceRequested::class)
            ->first();
        $this->assertNotNull($request, 'A failed invoice handoff must create a durable recovery request.');
        $this->assertSame('published', $request->status);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $request->id,
            'step' => 'invoice_handoff',
        ]);
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => $request->id,
            'listener_class' => CreateDraftInvoiceOnDeliveryInvoiceRequested::class,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
        ]);

        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $summary = app(DashboardWidgetDataService::class)
            ->summaries(['finance.invoice_handoff_backlog'], $finance)['finance.invoice_handoff_backlog'];
        $this->assertSame('1', $summary['value']);
        $this->assertSame('confirmed deliveries missing an invoice', $summary['helper']);

        $widgets = collect(app(DashboardLayoutService::class)->listAvailableWidgets($finance));
        $widget = $widgets->firstWhere('key', 'finance.invoice_handoff_backlog');
        $this->assertSame(
            '/supply-chain/deliveries?invoice_handoff_status=manual_required',
            $widget['link_path'],
        );
        $this->assertContains(
            'finance.invoice_handoff_backlog',
            collect(app(DashboardLayoutService::class)->getEffectiveLayout($finance))
                ->pluck('key')->all(),
        );

        $this->seedDeliveryWithLine($user, productRevenueAccountId: null, qty: '1', price: '10.00');
        $this->actingAs($finance)
            ->getJson('/api/v1/supply-chain/deliveries?invoice_handoff_status=manual_required')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $confirmed->fresh()->hash_id);

        $note = DB::table('notifications')
            ->where('type', 'invoice.auto_failed')
            ->where('notifiable_id', $arClerk->id)
            ->first();

        $this->assertNotNull($note, 'AR clerk must receive an invoice.auto_failed notification.');
        $data = json_decode($note->data, true);
        $this->assertSame("Auto-invoice failed: {$confirmed->delivery_number}", $data['title']);
        $this->assertSame("/supply-chain/deliveries/{$confirmed->hash_id}", $data['link_to']);
        $this->assertSame('delivery', $data['entity_type']);
        $this->assertSame($confirmed->hash_id, $data['entity_id']);
        $this->assertSame(Delivery::class, $data['subject_type']);
        $this->assertSame((string) $confirmed->id, $data['subject_id']);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'invoice.auto_failed',
            'notifiable_id' => $financeWithoutDeliveryAccess->id,
        ]);

        $this->actingAs($arClerk)
            ->getJson("/api/v1/supply-chain/deliveries/{$confirmed->hash_id}")
            ->assertOk();
        $this->actingAs($financeWithoutDeliveryAccess)
            ->getJson("/api/v1/supply-chain/deliveries/{$confirmed->hash_id}")
            ->assertForbidden();

        app(\App\Common\Services\SettingsService::class)->set(
            'accounting.delivery_confirmed.notification_roles',
            [$arClerk->role->slug, $financeWithoutDeliveryAccess->role->slug],
        );
        app(NotifyFinanceOnDeliveryConfirmed::class)->handle(new DeliveryConfirmed($confirmed));
        $financeNotice = DB::table('notifications')
            ->where('type', 'invoice.auto_failed')
            ->where('notifiable_id', $arClerk->id)
            ->first();
        $this->assertNotNull($financeNotice);
        $this->assertSame(1, DB::table('notifications')
            ->where('type', 'invoice.auto_failed')
            ->where('notifiable_id', $arClerk->id)
            ->count());
        $this->assertDatabaseMissing('notifications', [
            'type' => 'chain.delivery_confirmed',
            'notifiable_id' => $arClerk->id,
        ]);
        $financeData = json_decode($financeNotice->data, true);
        $this->assertSame("/supply-chain/deliveries/{$confirmed->hash_id}", $financeData['link_to']);
        $this->assertSame('delivery', $financeData['entity_type']);
        $this->assertSame(Delivery::class, $financeData['subject_type']);
        $this->assertSame((string) $confirmed->id, $financeData['subject_id']);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'chain.delivery_confirmed',
            'notifiable_id' => $financeWithoutDeliveryAccess->id,
        ]);
    }

    public function test_invoice_handoff_replay_recovers_after_accounting_is_fixed_without_duplication(): void
    {
        DB::table('settings')->where('key', 'accounting.default_sales_revenue_account_code')->delete();
        DB::table('accounts')->where('code', '4010')->delete();

        $user = $this->makeUser();
        [$delivery] = $this->seedDeliveryWithLine(
            $user,
            productRevenueAccountId: null,
            qty: '3',
            price: '40.00',
        );
        $this->addProof($delivery, $user);
        $confirmed = $this->svc->confirm($delivery, $user);

        $request = DB::table('event_outbox')
            ->where('event_type', DeliveryInvoiceRequested::class)
            ->latest('created_at')
            ->firstOrFail();

        $this->travel(2)->seconds();
        $this->svc->markInvoiceHandoffManual($confirmed->id);

        // Restore the exact accounting prerequisites that the original
        // attempt was missing, then replay only the handoff listener.
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $event = app(OutboxEventCodec::class)->decode(
            (string) $request->event_type,
            json_decode((string) $request->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $listener = app(CreateDraftInvoiceOnDeliveryInvoiceRequested::class);
        $listener->handle($event);

        $recovered = $confirmed->fresh();
        $this->assertNotNull($recovered->invoice_id);
        $this->assertSame(DeliveryInvoiceHandoffStatus::Generated, $recovered->invoice_handoff_status);
        $invoiceCount = Invoice::count();

        // A second replay observes the link and must not create another draft.
        $listener->handle($event);
        $this->assertSame($invoiceCount, Invoice::count());
    }

    public function test_create_rejects_a_second_invoice_for_an_already_invoiced_delivery(): void
    {
        $user = $this->makeUser();
        $revenueId = Account::query()->where('code', '4010')->firstOrFail()->hash_id;

        [$delivery, $deliveryItem, $so, $customer] = $this->seedDeliveryWithLine(
            $user,
            productRevenueAccountId: null,
            qty: '5',
            price: '100.00',
        );
        $this->addProof($delivery, $user);
        $this->svc->confirm($delivery, $user);
        $invoiceCount = Invoice::count();
        $invoiceId = $delivery->fresh()->invoice_id;

        try {
            app(InvoiceService::class)->create([
                'customer_id' => $customer->hash_id,
                'sales_order_id' => $so->hash_id,
                'delivery_id' => $delivery->hash_id,
                'date' => now()->toDateString(),
                'vat_classification' => 'vat_exempt',
                'items' => [[
                    'revenue_account_id' => $revenueId,
                    'source_delivery_item_id' => $deliveryItem->hash_id,
                    'description' => 'Second billing attempt',
                    'quantity' => '5',
                    'unit_price' => '100.00',
                ]],
            ], $user);
            $this->fail('A delivery already billed by the auto-invoice handoff must not get another invoice draft.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('already has an invoice', $error->getMessage());
        }

        $this->assertSame($invoiceCount, Invoice::count());
        $this->assertSame($invoiceId, $delivery->fresh()->invoice_id);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name' => 'Auto Invoice Test Role '.uniqid(),
            'slug' => 'auto_inv_test_'.uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.confirm'],
            ['name' => 'Confirm Delivery', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeArClerk(bool $canViewDelivery = false): User
    {
        $role = Role::create([
            'name' => 'AR Clerk Test '.uniqid(),
            'slug' => 'ar_clerk_test_'.uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'accounting.invoices.create'],
            ['name' => 'Create Invoices', 'module' => 'accounting'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        if ($canViewDelivery) {
            $deliveryPermission = Permission::firstOrCreate(
                ['slug' => 'supply_chain.deliveries.view'],
                ['name' => 'View Deliveries', 'module' => 'supply_chain'],
            );
            $role->permissions()->syncWithoutDetaching([$deliveryPermission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function addProof(Delivery $d, User $by): void
    {
        DeliveryProof::create([
            'delivery_id' => $d->id,
            'proof_type' => 'photo',
            'file_name' => 'receipt.jpg',
            'file_path' => "deliveries/{$d->id}/receipt.jpg",
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * @return array{0: Delivery, 1: DeliveryItem, 2: SalesOrder, 3: Customer}
     */
    private function seedDeliveryWithLine(
        User $user,
        ?int $productRevenueAccountId,
        string $qty,
        string $price,
    ): array {
        $customer = Customer::create([
            'name' => 'Test Customer '.uniqid(),
            'is_active' => true,
            'payment_terms_days' => 30,
        ]);

        $product = Product::create([
            'part_number' => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name' => 'Wiper Bushing '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'revenue_account_id' => $productRevenueAccountId,
            'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'so_number' => 'SO-T-'.substr(uniqid(), -10),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $price,
            'total' => bcmul($qty, $price, 2),
            'quantity_delivered' => 0,
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]);

        $delivery = Delivery::create([
            'delivery_number' => 'DEL-TEST-'.uniqid(),
            'sales_order_id' => $so->id,
            'status' => 'delivered',
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => now(),
            'created_by' => $user->id,
        ]);

        $item = DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'inspection_id' => null,
            'quantity' => $qty,
            'unit_price' => $price,
        ]);

        return [$delivery, $item, $so, $customer];
    }

    public function test_a_later_delivery_touch_still_rehydrates_the_invoice_request(): void
    {
        DB::table('settings')->where('key', 'accounting.default_sales_revenue_account_code')->delete();
        DB::table('accounts')->where('code', '4010')->delete();

        $user = $this->makeUser();
        [$delivery] = $this->seedDeliveryWithLine($user, productRevenueAccountId: null, qty: '3', price: '40.00');
        $this->addProof($delivery, $user);

        $this->svc->confirm($delivery, $user);

        $outbox = DB::table('event_outbox')
            ->where('event_type', DeliveryInvoiceRequested::class)
            ->firstOrFail();

        // Confirmation, the failed handoff and any later AR follow-up all write
        // to the delivery row, so a replay must accept a row that moved on.
        $this->travel(5)->seconds();
        $delivery->fresh()->forceFill(['invoice_handoff_message' => 'AR followed up'])->save();

        $event = app(OutboxEventCodec::class)->decode(
            (string) $outbox->event_type,
            json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertInstanceOf(DeliveryInvoiceRequested::class, $event);
        $this->assertSame($delivery->id, $event->delivery->id);
    }
}
