<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderResponse;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales-order negotiation through the B2B customer portal: the respond
 * endpoint, the can_respond capability, and the latest_response contract the
 * SPA is built to.
 */
class SalesOrderResponsePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
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

    private function makeSo(Customer $customer, string $status = 'draft', bool $requested = true): SalesOrder
    {
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $so->forceFill([
            'status' => $status,
            'customer_confirmation_requested_at' => $requested ? now() : null,
        ])->save();

        return $so->refresh();
    }

    private function makeLine(SalesOrder $so, string $quantity = '100.00', string $unitPrice = '10.00'): SalesOrderItem
    {
        return SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => Product::factory()->create()->id,
            'quantity'           => $quantity,
            'unit_price'         => $unitPrice,
            'total'              => Money::mul($quantity, $unitPrice),
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(7)->toDateString(),
        ]);
    }

    private function respondUrl(SalesOrder $so): string
    {
        return "/api/v1/b2b/customer/orders/{$so->hash_id}/respond";
    }

    /* ─── respond endpoint ───────────────────────────────────────── */

    public function test_portal_accept_marks_the_response_accepted(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), [
            'type'                   => 'accept',
            'proposed_delivery_date' => '2026-10-20',
            'notes'                  => 'Accepted as ordered.',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'accept')
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('sales_order_responses', [
            'sales_order_id' => $so->id,
            'customer_id'    => $customer->id,
            'response_type'  => 'accept',
            'status'         => 'accepted',
        ]);
        // The order is not confirmed by the customer's acceptance.
        $this->assertSame('draft', $so->fresh()->status->value);
    }

    public function test_portal_propose_stores_items(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '120.00',
                'proposed_unit_price' => '9.50',
                'reason'              => 'Volume discount.',
            ]],
        ])->assertStatus(201)->assertJsonPath('data.type', 'propose');

        $response = SalesOrderResponse::query()->where('sales_order_id', $so->id)->firstOrFail();
        $this->assertSame('pending', $response->status->value);
        $this->assertSame(1, $response->items()->count());
        $stored = $response->items()->firstOrFail();
        $this->assertSame((int) $line->id, (int) $stored->sales_order_item_id);
        $this->assertSame('120.00', (string) $stored->proposed_quantity);
        $this->assertSame('9.50', (string) $stored->proposed_unit_price);
    }

    public function test_portal_propose_requires_at_least_one_item(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), ['type' => 'propose', 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_portal_decline_is_recorded(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), ['type' => 'decline', 'notes' => 'Cannot fulfil.'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'decline');
    }

    public function test_portal_respond_is_forbidden_for_another_customer(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $userA = $this->makePortalUser($customerA);
        $soB = $this->makeSo($customerB);

        $this->actAs($userA);

        $this->postJson($this->respondUrl($soB), ['type' => 'accept'])->assertStatus(403);

        $this->assertDatabaseCount('sales_order_responses', 0);
    }

    public function test_portal_respond_rejects_a_draft_not_released_to_the_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer, requested: false);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), ['type' => 'accept'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This sales order is not open to customer response.');
    }

    /* ─── capabilities + latest_response contract ────────────────── */

    public function test_capabilities_and_confirmation_timestamp_are_exposed(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);

        $this->actAs($user);

        $row = $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($row['capabilities']['can_respond']);
        $this->assertTrue($row['capabilities']['can_confirm']);
        $this->assertNotNull($row['customer_confirmation_requested_at']);
        $this->assertNull($row['latest_response']);
    }

    public function test_order_detail_returns_the_latest_response_block(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so);

        $this->actAs($user);

        $this->postJson($this->respondUrl($so), [
            'type'                   => 'propose',
            'proposed_delivery_date' => '2026-11-05',
            'notes'                  => 'Proposing adjusted terms.',
            'items'                  => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '110.00',
                'proposed_unit_price' => '9.00',
                'reason'              => 'Material cost.',
            ]],
        ])->assertStatus(201);

        $block = $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}")
            ->assertOk()
            ->json('data.latest_response');

        $this->assertSame([
            'id', 'type', 'status', 'proposed_delivery_date', 'notes',
            'responded_at', 'resolved_at', 'resolution_notes', 'items',
        ], array_keys($block));
        $this->assertSame('propose', $block['type']);
        $this->assertSame('pending', $block['status']);
        $this->assertSame('2026-11-05', $block['proposed_delivery_date']);
        $this->assertSame('Proposing adjusted terms.', $block['notes']);
        $this->assertNotNull($block['responded_at']);
        $this->assertNull($block['resolved_at']);
        $this->assertNull($block['resolution_notes']);
        $this->assertCount(1, $block['items']);
        $this->assertSame($line->hash_id, $block['items'][0]['sales_order_item_id']);
        $this->assertSame('110.00', $block['items'][0]['proposed_quantity']);
        $this->assertSame('9.00', $block['items'][0]['proposed_unit_price']);
        $this->assertSame('Material cost.', $block['items'][0]['reason']);
    }
}
