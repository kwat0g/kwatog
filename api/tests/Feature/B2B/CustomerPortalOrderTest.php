<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Mail\UserNotificationMail;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemUserResolver;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\CustomerPortalService;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer self-service ordering — the customer places a Sales Order through
 * the B2B portal. Pins catalog scoping, price-resolution, audit attribution,
 * internal review notification, and cross-customer isolation on the HTTP path.
 */
class CustomerPortalOrderTest extends TestCase
{
    use RefreshDatabase;

    private int $orderRequestNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        // Tests that care about VAT enable it explicitly; the default here is
        // non-VAT so total == subtotal regardless of the host env.
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(?Customer $customer = null): CustomerPortalUser
    {
        $customer ??= Customer::factory()->create();

        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'CustUser-'.substr(uniqid(), -5),
            'email' => 'cu-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(CustomerPortalUser $user): self
    {
        $this->actingAs($user, 'customer_portal');

        return $this;
    }

    private function agreement(Customer $customer, Product $product, string $price = '100.00'): PriceAgreement
    {
        return PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => $price,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postOrder(array $payload, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/b2b/customer/orders', $payload, [
            'Idempotency-Key' => $key ?? 'customer-order-test-'.(++$this->orderRequestNumber),
        ]);
    }

    /* ─── Catalog ────────────────────────────────────────────────── */

    public function test_catalog_returns_only_products_with_active_agreement_for_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $inCatalog = Product::factory()->create();
        $this->agreement($customer, $inCatalog, '250.00');

        $otherCustomer = Customer::factory()->create();
        $otherProduct = Product::factory()->create();
        $this->agreement($otherCustomer, $otherProduct, '500.00');

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($inCatalog->hash_id, $items[0]['id']);
        $this->assertSame('250.00', $items[0]['unit_price']);
        $this->assertSame('flat', $items[0]['pricing_method']);
    }

    public function test_catalog_excludes_archived_products_and_expired_agreements(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $archived = Product::factory()->create();
        $this->agreement($customer, $archived);
        $archived->delete();

        $expired = Product::factory()->create();
        PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $expired->id,
            'price' => '75.00',
            'effective_from' => now()->subMonths(2)->toDateString(),
            'effective_to' => now()->subDay()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_catalog_search_filters_by_part_number(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $target = Product::factory()->create(['part_number' => 'XX-NEEDLE-01']);
        $this->agreement($customer, $target);
        $other = Product::factory()->create(['part_number' => 'ZZ-OTHER-02']);
        $this->agreement($customer, $other);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog?search=NEEDLE');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($target->hash_id, $items[0]['id']);
    }

    /* ─── Place Order ────────────────────────────────────────────── */

    public function test_place_order_awaits_confirmation_for_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product, '120.50');

        $this->actAs($user);

        $response = $this->postOrder([
            'items' => [
                ['product_id' => $product->hash_id, 'quantity' => 4, 'delivery_date' => now()->addDays(7)->toDateString()],
            ],
            'notes' => 'Rush order via portal',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'awaiting_confirmation');
        $response->assertJsonPath('data.submission_source', SalesOrderSubmissionSource::CustomerPortal->value);

        $so = SalesOrder::where('customer_id', $customer->id)->first();
        $this->assertNotNull($so);
        $this->assertSame('customer_portal', $so->submission_source->value);
        $this->assertSame('awaiting_confirmation', $so->status->value);
        $this->assertSame('482.00', (string) $so->subtotal);   // 4 × 120.50
        $this->assertSame('482.00', (string) $so->total_amount);

        $line = $so->items()->first();
        $this->assertNotNull($line);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('4.00', (string) $line->quantity);
        $this->assertSame('120.50', (string) $line->unit_price);
        $this->assertSame('482.00', (string) $line->total);
    }

    public function test_place_order_sets_submission_date_and_replays_after_midnight(): void
    {
        $this->travelTo(now()->setTime(23, 59));
        $submissionDate = now()->toDateString();
        $deliveryDate = now()->addDays(4)->toDateString();
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $this->actAs($user);

        foreach ([now()->subDays(10)->toDateString(), now()->addDays(10)->toDateString()] as $index => $date) {
            $this->postOrder([
                'date' => $date,
                'items' => [['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => $deliveryDate]],
            ], 'portal-fixed-date-'.$index)->assertCreated()->assertJsonPath('data.date', $submissionDate);
        }

        $payload = [
            'date' => $submissionDate,
            'items' => [['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => $deliveryDate]],
        ];
        $id = $this->postOrder($payload, 'portal-midnight-retry')->assertCreated()->json('data.id');
        $this->travel(2)->minutes();
        $this->postOrder($payload, 'portal-midnight-retry')->assertOk()->assertJsonPath('data.id', $id);
        $this->postOrder($payload, 'portal-new-next-day')->assertStatus(422);
        $this->travelBack();
    }

    public function test_customer_owns_draft_changes_and_sales_cannot_mutate_portal_orders(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product, '100.00');
        $this->actAs($portalUser);
        $id = $this->postOrder(['items' => [[
            'product_id' => $product->hash_id, 'quantity' => '2.00', 'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertCreated()->json('data.id');
        $so = SalesOrder::query()->where('customer_id', $customer->id)->firstOrFail();
        $date = $so->date->toDateString();
        $change = ['notes' => 'Updated by customer', 'date' => '2000-01-01', 'unit_price' => 1, 'items' => [[
            'product_id' => $product->hash_id, 'quantity' => '3.00', 'delivery_date' => now()->addDays(5)->toDateString(),
        ]]];
        $this->putJson('/api/v1/b2b/customer/orders/'.$id, $change)->assertOk()
            ->assertJsonPath('data.total_amount', '300.00')->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.notes', 'Updated by customer');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.order.updated', 'model_id' => $so->id]);

        $other = $this->makePortalUser();
        $this->actAs($other)->putJson('/api/v1/b2b/customer/orders/'.$id, $change)->assertNotFound();
        $this->postJson('/api/v1/b2b/customer/orders/'.$id.'/cancel')->assertNotFound();

        $sales = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales_officer')->value('id'), 'is_active' => true]);
        $this->actingAs($sales, 'sanctum');
        $this->getJson('/api/v1/crm/sales-orders/'.$id)->assertOk()
            ->assertJsonPath('data.is_editable', false)->assertJsonPath('data.is_cancellable', false);
        $this->putJson('/api/v1/crm/sales-orders/'.$id, ['date' => $date, 'items' => $change['items']])->assertStatus(422);
        $this->postJson('/api/v1/crm/sales-orders/'.$id.'/cancel')->assertStatus(422);
        $this->deleteJson('/api/v1/crm/sales-orders/'.$id)->assertStatus(422);
        $this->assertSame('300.00', (string) $so->fresh()->total_amount);

        $this->actAs($portalUser)->postJson('/api/v1/b2b/customer/orders/'.$id.'/cancel', ['reason' => 'No longer needed'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.order.cancelled', 'model_id' => $so->id]);
        $this->putJson('/api/v1/b2b/customer/orders/'.$id, $change)->assertStatus(422);
    }

    public function test_customer_draft_edit_is_guarded_when_delivery_date_enters_lead_window(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $this->actAs($portalUser);

        $deliveryDate = today()->addDays(4)->toDateString();
        $id = $this->postOrder(['items' => [[
            'product_id' => $product->hash_id,
            'quantity' => 2,
            'delivery_date' => $deliveryDate,
        ]]])->assertCreated()->json('data.id');
        $order = SalesOrder::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->travel(1)->day();
        $earliest = today()->addDays(4)->toDateString();
        try {
            $this->putJson('/api/v1/b2b/customer/orders/'.$id, [
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => 3,
                    'delivery_date' => $deliveryDate,
                ]],
            ])->assertStatus(422)
                ->assertJsonPath('message', "items.0.delivery_date must be more than 3 days from today. Earliest date: {$earliest}.");
        } finally {
            $this->travelBack();
        }

        $this->assertSame('awaiting_confirmation', $order->fresh()->status->value);
        $line = $order->items()->firstOrFail()->fresh();
        $this->assertSame('2.00', (string) $line->quantity);
        $this->assertSame($deliveryDate, $line->delivery_date->toDateString());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.order.updated', 'model_id' => $order->id]);
    }

    public function test_customer_draft_mutations_recheck_confirmation_and_preserve_internal_orders(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $this->actAs($portalUser);
        $id = $this->postOrder(['items' => [[
            'product_id' => $product->hash_id, 'quantity' => 2, 'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertCreated()->json('data.id');
        $so = SalesOrder::query()->where('customer_id', $customer->id)->firstOrFail();
        $stale = $so->fresh();
        $so->forceFill(['status' => 'confirmed'])->save();
        $this->putJson('/api/v1/b2b/customer/orders/'.$id, ['items' => [[
            'product_id' => $product->hash_id, 'quantity' => 5, 'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertStatus(422);
        $this->postJson('/api/v1/b2b/customer/orders/'.$id.'/cancel')->assertStatus(422);
        try {
            app(CustomerPortalService::class)->cancelOrder($stale, $portalUser, null);
            $this->fail('A stale customer cancellation must not cancel a confirmed order.');
        } catch (BusinessRuleException) {
            $this->assertSame('confirmed', $so->fresh()->status->value);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.order.cancelled', 'model_id' => $so->id]);

        $so->forceFill(['status' => 'draft', 'submission_source' => 'internal'])->save();
        $this->postJson('/api/v1/b2b/customer/orders/'.$id.'/cancel')->assertStatus(422);
        $sales = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales_officer')->value('id'), 'is_active' => true]);
        $this->actingAs($sales, 'sanctum')->putJson('/api/v1/crm/sales-orders/'.$id, ['date' => $so->date->toDateString(), 'items' => [[
            'product_id' => $product->hash_id, 'quantity' => 5, 'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertOk()->assertJsonPath('data.total_amount', '500.00');
    }

    public function test_place_order_uses_customer_default_payment_terms_and_applies_vat(): void
    {
        app(SettingsService::class)->set('company.vat_status', 'VAT Registered', 'company');
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.12, 'tax');

        $customer = Customer::factory()->create(['payment_terms_days' => 45]);
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product, '1000.00');

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 2, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('2000.00', (string) $so->subtotal);
        $this->assertSame('240.00', (string) $so->vat_amount);
        $this->assertSame('2240.00', (string) $so->total_amount);
        $this->assertSame(45, $so->payment_terms_days);
    }

    public function test_place_order_rejects_product_without_active_agreement(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_another_customers_agreed_product(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($otherCustomer, $product, '99.00');

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_delivery_date_before_order_date(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $this->actAs($user)
            ->postOrder([
                'date' => now()->toDateString(),
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->subDay()->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_invalid_product_hash(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => 'not-a-real-hash', 'quantity' => 1, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_attributes_to_system_user_and_writes_external_audit(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $systemUser = app(SystemUserResolver::class)->user();

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($systemUser->id, $so->created_by);

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'customer_portal',
            'action' => 'customer.order.placed',
            'model_type' => SalesOrder::class,
            'model_id' => $so->id,
        ]);
        $log = AuditLog::where('action', 'customer.order.placed')->where('model_id', $so->id)->firstOrFail();
        $this->assertSame($user->hash_id, $log->new_values['portal_user_id']);
    }

    public function test_place_order_notifies_sales_staff_who_can_confirm(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $role = Role::query()->where('slug', 'sales_officer')->firstOrFail();
        $sales = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $salesWithoutView = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        UserPermissionOverride::create([
            'user_id' => $salesWithoutView->id,
            'permission_id' => Permission::query()->where('slug', 'crm.sales_orders.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $sales->id,
            'reason' => 'Notification route access test.',
        ]);
        $salesWithoutView->flushPermissionsCache();

        $this->actAs($user)
            ->postOrder([
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(4)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $sales->id,
            'type' => 'portal.order_awaiting_review',
        ]);

        $notification = DB::table('notifications')
            ->where('notifiable_id', $sales->id)
            ->where('type', 'portal.order_awaiting_review')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString($so->so_number, $notification->data);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $salesWithoutView->id,
            'type' => 'portal.order_awaiting_review',
        ]);
    }

    public function test_place_order_replays_the_original_result_for_the_same_key_and_payload(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $payload = [
            'items' => [[
                'product_id' => $product->hash_id,
                'quantity' => '2.00',
                'delivery_date' => now()->addDays(4)->toDateString(),
            ]],
        ];

        $this->actAs($user);
        $first = $this->postOrder($payload, 'portal-order-replay-01')->assertCreated()->json('data.id');
        $replay = $this->postOrder($payload, 'portal-order-replay-01')->assertOk()->json('data.id');

        $this->assertSame($first, $replay);
        $this->assertSame(1, SalesOrder::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.order.placed')->count());
    }

    public function test_place_order_rejects_a_key_reused_for_a_different_payload(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $payload = [
            'items' => [[
                'product_id' => $product->hash_id,
                'quantity' => '2.00',
                'delivery_date' => now()->addDays(4)->toDateString(),
            ]],
        ];

        $this->actAs($user);
        $this->postOrder($payload, 'portal-order-replay-02')->assertCreated();
        $payload['items'][0]['quantity'] = '3.00';

        $this->postOrder($payload, 'portal-order-replay-02')->assertStatus(422);
        $this->assertSame(1, SalesOrder::query()->where('customer_id', $customer->id)->count());
    }

    public function test_place_order_requires_an_idempotency_key(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', ['items' => [[
                'product_id' => $product->hash_id,
                'quantity' => '1.00',
                'delivery_date' => now()->addDays(4)->toDateString(),
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_customer_portal_order_writes_are_rate_limited_per_user(): void
    {
        $user = $this->makePortalUser();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($user, 'customer_portal')
                ->postJson('/api/v1/b2b/customer/orders', [])
                ->assertStatus(422);
        }

        $this->actingAs($user, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/orders', [])
            ->assertStatus(429);
    }

    public function test_sales_delivery_proposal_requires_customer_acceptance_and_changes_only_dates(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->firstOrFail()->id]);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $requested = now()->addDays(4)->toDateString();
        $proposed = now()->addDays(7)->toDateString();
        $this->actAs($user)->postOrder(['items' => [[
            'product_id' => $product->hash_id, 'quantity' => '50', 'delivery_date' => $requested,
        ]]])->assertCreated();
        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $before = $so->items()->firstOrFail()->only(['id', 'product_id', 'quantity', 'unit_price', 'total']);
        $this->actingAs($sales, 'sanctum');
        $proposalId = $this->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/propose-delivery-date", [
            'proposed_delivery_date' => $proposed, 'notes' => 'Production capacity requires four more days.',
        ])->assertCreated()->json('data.id');
        $this->assertSame('delivery_negotiation', $so->fresh()->status->value);
        $this->assertSame($requested, $so->items()->firstOrFail()->delivery_date->toDateString());
        $this->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")->assertStatus(422);
        $this->patchJson("/api/v1/crm/sales-order-responses/{$proposalId}/accept")->assertStatus(422);
        $other = $this->makePortalUser();
        $this->actAs($other)->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$proposalId}/respond", ['decision' => 'accept'])->assertNotFound();
        $this->actAs($user)->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")
            ->assertOk()->assertJsonPath('data.capabilities.can_respond_delivery', true)
            ->assertJsonPath('data.requested_delivery_dates.0', $requested);
        $this->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$proposalId}/respond", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$proposalId}/respond", ['decision' => 'accept'])->assertOk();
        $line = $so->items()->firstOrFail();
        $this->assertSame($before, $line->only(array_keys($before)));
        $this->assertSame($proposed, $line->delivery_date->toDateString());
        $this->assertSame('awaiting_confirmation', $so->fresh()->status->value);
        $this->assertSame($requested, $line->requested_delivery_date->toDateString());
        $this->assertSame('5000.00', $so->fresh()->total_amount);
        $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")->assertOk()
            ->assertJsonPath('data.requested_delivery_dates.0', $requested)
            ->assertJsonPath('data.delivery_dates.0', $proposed)
            ->assertJsonPath('data.capabilities.can_respond_delivery', false);
        $this->assertDatabaseHas('sales_order_responses', ['sales_order_id' => $so->id, 'portal_user_id' => $user->id, 'status' => 'accepted']);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_type', CustomerPortalUser::class)->where('notifiable_id', $user->id)->where('type', 'sales_order.delivery_decided')->count());
        $this->assertDatabaseHas('notifications', ['notifiable_type' => CustomerPortalUser::class, 'notifiable_id' => $user->id, 'type' => 'sales_order.delivery_proposed']);
        $this->actingAs($sales, 'sanctum')->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")->assertOk();
        $this->assertSame('confirmed', $so->fresh()->status->value);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => CustomerPortalUser::class, 'notifiable_id' => $user->id, 'type' => 'sales_order.confirmed']);

    }

    public function test_delivery_proposal_email_is_sent_once_per_valid_portal_mailbox(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Duplicate address',
            'email' => strtoupper($portalUser->email),
            'password' => 'test-password',
            'is_active' => true,
        ]);
        foreach ([['invalid-address', true], ['inactive@example.test', false]] as [$email, $active]) {
            CustomerPortalUser::create([
                'customer_id' => $customer->id,
                'name' => 'Excluded portal user',
                'email' => $email,
                'password' => 'test-password',
                'is_active' => $active,
            ]);
        }
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $this->actAs($portalUser)->postOrder(['items' => [[
            'product_id' => $product->hash_id,
            'quantity' => '10',
            'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertCreated();
        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->firstOrFail()->id]);
        Mail::fake();

        $this->actingAs($sales, 'sanctum')->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/propose-delivery-date", [
            'proposed_delivery_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Production capacity requires four more days.',
        ])->assertCreated();

        Mail::assertQueued(UserNotificationMail::class, 1);
        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) use ($portalUser): bool {
            self::assertCount(1, $mail->to);
            self::assertSame($portalUser->email, $mail->to[0]['address']);
            self::assertSame([(int) $portalUser->id], $mail->recipientUserIds);

            return true;
        });
    }

    public function test_delivery_proposal_without_valid_customer_email_notifies_sales(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $portalUser->update(['email' => 'invalid-address']);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);
        $this->actAs($portalUser)->postOrder(['items' => [[
            'product_id' => $product->hash_id,
            'quantity' => '10',
            'delivery_date' => now()->addDays(4)->toDateString(),
        ]]])->assertCreated();
        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->firstOrFail()->id]);
        Mail::fake();

        $this->actingAs($sales, 'sanctum')->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/propose-delivery-date", [
            'proposed_delivery_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Production capacity requires four more days.',
        ])->assertCreated();

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $sales->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    public function test_delivery_negotiation_can_repeat_until_customer_accepts(): void
    {
        $user = $this->makePortalUser();
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->firstOrFail()->id]);
        $product = Product::factory()->create();
        $this->agreement($user->customer, $product);
        $requested = now()->addDays(4)->toDateString();
        $this->actAs($user)->postOrder(['items' => [[
            'product_id' => $product->hash_id, 'quantity' => '100', 'delivery_date' => $requested,
        ]]])->assertCreated()->assertJsonPath('data.status', 'awaiting_confirmation');
        $so = SalesOrder::where('customer_id', $user->customer_id)->firstOrFail();
        for ($round = 1; $round <= 4; $round++) {
            $this->actingAs($sales, 'sanctum');
            $proposal = $this->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/propose-delivery-date", [
                'proposed_delivery_date' => now()->addDays(4 + $round)->toDateString(), 'notes' => "Alternative round {$round}",
            ])->assertCreated()->json('data.id');
            $this->assertFalse(SalesOrder::planningRelevant()->whereKey($so->id)->exists());
            $this->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")->assertStatus(422);
            $decision = $round === 4 ? 'accept' : 'reject';
            $this->actAs($user)->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$proposal}/respond", ['decision' => $decision])->assertOk();
        }
        $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonCount(4, 'data.delivery_negotiations')
            ->assertJsonPath('data.delivery_negotiations.0.status', 'accepted')
            ->assertJsonPath('data.delivery_negotiations.3.status', 'rejected')
            ->assertJsonPath('data.requested_delivery_dates.0', $requested);
        $this->assertFalse(SalesOrder::planningRelevant()->whereKey($so->id)->exists());
    }

    public function test_delivery_proposal_rejection_and_customer_edits_keep_stale_dates_from_applying(): void
    {
        $user = $this->makePortalUser();
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->firstOrFail()->id]);
        $product = Product::factory()->create();
        $this->agreement($user->customer, $product);
        $requested = now()->addDays(4)->toDateString();
        $this->actAs($user)->postOrder(['items' => [[
            'product_id' => $product->hash_id, 'quantity' => '2', 'delivery_date' => $requested,
        ]]])->assertCreated();
        $so = SalesOrder::where('customer_id', $user->customer_id)->firstOrFail();
        $proposalPath = "/api/v1/crm/sales-orders/{$so->hash_id}/propose-delivery-date";
        $this->actingAs($sales, 'sanctum');
        $this->postJson($proposalPath, ['proposed_delivery_date' => $requested, 'notes' => 'Same date'])->assertStatus(422);
        $this->postJson($proposalPath, ['proposed_delivery_date' => now()->subDay()->toDateString(), 'notes' => 'Past date'])->assertStatus(422);
        $proposal = $this->postJson($proposalPath, ['proposed_delivery_date' => now()->addDays(8)->toDateString(), 'notes' => 'Capacity delay'])->assertCreated()->json('data.id');
        $this->actAs($user)->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$proposal}/respond", ['decision' => 'reject'])->assertOk();
        $this->assertSame('delivery_negotiation', $so->fresh()->status->value);
        $this->assertFalse(SalesOrder::planningRelevant()->whereKey($so->id)->exists());
        $this->assertSame($requested, $so->items()->firstOrFail()->delivery_date->toDateString());
        $this->actingAs($sales, 'sanctum');
        $old = $this->postJson($proposalPath, ['proposed_delivery_date' => now()->addDays(9)->toDateString(), 'notes' => 'First alternative'])->assertCreated()->json('data.id');
        $new = $this->postJson($proposalPath, ['proposed_delivery_date' => now()->addDays(10)->toDateString(), 'notes' => 'Second alternative'])->assertCreated()->json('data.id');
        $this->actAs($user)->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$old}/respond", ['decision' => 'accept'])->assertStatus(422);
        $this->putJson("/api/v1/b2b/customer/orders/{$so->hash_id}", ['items' => [[
            'product_id' => $product->hash_id, 'quantity' => '3', 'delivery_date' => $requested,
        ]]])->assertOk();
        $this->postJson("/api/v1/b2b/customer/orders/{$so->hash_id}/delivery-proposals/{$new}/respond", ['decision' => 'accept'])->assertStatus(422);
        $this->assertSame('awaiting_confirmation', $so->fresh()->status->value);
        $this->assertSame($requested, $so->items()->firstOrFail()->delivery_date->toDateString());
        $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")->assertOk()->assertJsonCount(3, 'data.delivery_negotiations');
        $so->update(['status' => 'confirmed']);
        $this->actingAs($sales, 'sanctum')->postJson($proposalPath, ['proposed_delivery_date' => now()->addDays(12)->toDateString(), 'notes' => 'Too late'])->assertStatus(422);
    }

    public function test_customer_order_quantities_must_be_positive_whole_numbers(): void
    {
        $user = $this->makePortalUser();
        $product = Product::factory()->create();
        $this->agreement($user->customer, $product);
        $payload = ['items' => [['product_id' => $product->hash_id, 'quantity' => '1.5', 'delivery_date' => now()->addDays(4)->toDateString()]]];
        $this->actAs($user)->postOrder($payload)->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('sales_orders', 0);
        $payload['items'][0]['quantity'] = '100.00';
        $id = $this->postOrder($payload)->assertCreated()->json('data.id');
        $payload['items'][0]['quantity'] = '0.5';
        $this->putJson("/api/v1/b2b/customer/orders/{$id}", $payload)->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $payload['items'][0]['quantity'] = '101';
        $this->putJson("/api/v1/b2b/customer/orders/{$id}", $payload)->assertOk();
        $this->assertSame('101.00', SalesOrder::where('customer_id', $user->customer_id)->firstOrFail()->items()->firstOrFail()->quantity);
    }

    public function test_customer_requested_delivery_requires_more_than_three_days_notice_and_replays_original_order(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00', 'Asia/Manila'));
        $user = $this->makePortalUser();
        $product = Product::factory()->create();
        $this->agreement($user->customer, $product);
        $this->actAs($user);
        $payload = ['items' => [['product_id' => $product->hash_id, 'quantity' => '10', 'delivery_date' => '2026-10-07']]];
        $this->postOrder($payload)->assertStatus(422);
        $payload['items'][0]['delivery_date'] = '2026-10-08';
        $id = $this->postOrder($payload, 'minimum-delivery-replay')->assertCreated()->json('data.id');
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila'));
        $this->postOrder($payload, 'minimum-delivery-replay')->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson("/api/v1/b2b/customer/orders/{$id}", $payload)->assertStatus(422);
        $payload['items'][0]['delivery_date'] = '2026-10-09';
        $this->putJson("/api/v1/b2b/customer/orders/{$id}", $payload)->assertOk();
        $this->travelBack();
    }
}
