<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderResponseStatus;
use App\Modules\CRM\Enums\SalesOrderResponseType;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Services\SalesOrderResponseService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Customer response / negotiation lifecycle (internal sales side).
 *
 * Mirrors Purchasing\SupplierResponseTest: respond() transitions, supersede,
 * and resolve() — the internal decision that either applies a counter-offer
 * with exact decimal money math and re-runs the confirmation credit gate, or
 * rejects the reply and leaves the order untouched.
 */
class SalesOrderResponseTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderResponseService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
        $this->svc = app(SalesOrderResponseService::class);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makeSo(Customer $customer, string $status = 'draft'): SalesOrder
    {
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'subtotal'    => '0.00',
            'vat_amount'  => '0.00',
            'total_amount'=> '0.00',
        ]);
        $so->forceFill([
            'status' => $status,
            'customer_confirmation_requested_at' => now(),
        ])->save();

        return $so->refresh();
    }

    private function makeLine(SalesOrder $so, string $quantity, string $unitPrice): SalesOrderItem
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

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }

    /* ─── respond(): customer side ───────────────────────────────── */

    public function test_accept_resolves_immediately_and_leaves_the_order_draft(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'                   => 'accept',
            'proposed_delivery_date' => '2026-10-15',
            'notes'                  => 'We accept these terms.',
        ]);

        $this->assertSame(SalesOrderResponseType::Accept, $response->response_type);
        $this->assertSame(SalesOrderResponseStatus::Accepted, $response->status);
        $this->assertSame('2026-10-15', $response->proposed_delivery_date->toDateString());

        // The forward-only status enum is untouched and MRP is not triggered.
        $this->assertSame('draft', $so->fresh()->status->value);
    }

    public function test_propose_stores_items_and_stays_pending(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so, '100.00', '10.00');

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '120.00',
                'proposed_unit_price' => '9.50',
                'reason'              => 'Better volume.',
            ]],
        ]);

        $this->assertSame(SalesOrderResponseType::Propose, $response->response_type);
        $this->assertSame(SalesOrderResponseStatus::Pending, $response->status);
        $this->assertCount(1, $response->items);
        $stored = $response->items->first();
        $this->assertSame((int) $line->id, (int) $stored->sales_order_item_id);
        $this->assertSame('120.00', (string) $stored->proposed_quantity);
        $this->assertSame('9.50', (string) $stored->proposed_unit_price);

        // The original order line is untouched until sales accepts.
        $this->assertSame('100.00', (string) $line->fresh()->quantity);
        $this->assertSame('10.00', (string) $line->fresh()->unit_price);
    }

    public function test_decline_stays_pending(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'decline',
            'notes' => 'Cannot proceed at this price.',
        ]);

        $this->assertSame(SalesOrderResponseStatus::Pending, $response->status);
        $this->assertSame('draft', $so->fresh()->status->value);
    }

    public function test_respond_requires_the_order_to_belong_to_the_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $so = $this->makeSo($other);

        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);

        $this->svc->respond($so, (int) $customer->id, null, ['type' => 'accept']);
    }

    public function test_respond_refuses_a_draft_not_released_to_the_customer(): void
    {
        $customer = Customer::factory()->create();
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $so->forceFill(['status' => 'draft', 'customer_confirmation_requested_at' => null])->save();

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('This sales order is not open to customer response.');

        $this->svc->respond($so, (int) $customer->id, null, ['type' => 'accept']);
    }

    public function test_resubmission_supersedes_the_prior_pending_response(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so, '100.00', '10.00');

        $first = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '90.00',
            ]],
        ]);
        $second = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '110.00',
            ]],
        ]);

        $this->assertSame(SalesOrderResponseStatus::Superseded, $first->fresh()->status);
        $this->assertSame(SalesOrderResponseStatus::Pending, $second->fresh()->status);
        $this->assertSame(
            1,
            SalesOrderResponse::query()
                ->where('sales_order_id', $so->id)
                ->where('status', SalesOrderResponseStatus::Pending)
                ->count(),
        );
    }

    /* ─── resolve(): internal sales side ─────────────────────────── */

    public function test_accept_of_propose_applies_values_and_recomputes_totals_exactly(): void
    {
        app(SettingsService::class)->set('company.vat_status', 'VAT Registered', 'company');
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.12, 'tax');

        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $lineA = $this->makeLine($so, '100.00', '10.00'); // 1000.00
        $lineB = $this->makeLine($so, '50.00', '20.00');  // 1000.00

        // Only line A is proposed, so a subtotal computed from response items
        // alone would be wrong — it must be summed across ALL order lines.
        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $lineA->hash_id,
                'proposed_quantity'   => '120.00',
                'proposed_unit_price' => '9.50',
            ]],
        ]);

        $approver = $this->userWithRole('sales_officer');
        Mail::fake();

        $resolved = $this->svc->resolve($response, $approver, 'accept', 'Agreed.');

        $this->assertSame(SalesOrderResponseStatus::Accepted, $resolved->status);
        $this->assertSame($approver->id, $resolved->resolved_by);
        $this->assertSame('Agreed.', $resolved->resolution_notes);

        // A: 120 × 9.50 = 1140.00 ; B unchanged: 50 × 20.00 = 1000.00
        $lineA->refresh();
        $this->assertSame('120.00', (string) $lineA->quantity);
        $this->assertSame('9.50', (string) $lineA->unit_price);
        $this->assertSame('1140.00', (string) $lineA->total);
        $lineB->refresh();
        $this->assertSame('1000.00', (string) $lineB->total);

        // subtotal 2140.00 → vat 12% = 256.80 → total 2396.80
        $fresh = $so->fresh();
        $this->assertSame('2140.00', (string) $fresh->subtotal);
        $this->assertSame('256.80', (string) $fresh->vat_amount);
        $this->assertSame('2396.80', (string) $fresh->total_amount);
        $this->assertSame('draft', $fresh->status->value, 'Accepting a proposal must not confirm the order.');

        Mail::assertQueued(\App\Modules\CRM\Mail\CustomerSalesOrderDecisionMail::class, function ($mail) use ($so) {
            return $mail->decision === 'accept' && (int) $mail->salesOrder->id === (int) $so->id;
        });
    }

    public function test_accept_of_propose_reruns_the_credit_gate(): void
    {
        // A tiny AR balance consumes the credit line once the proposal raises
        // this order's value.
        \App\Modules\Accounting\Models\Invoice::factory()->create([
            'customer_id' => ($customer = Customer::factory()->create(['credit_limit' => '500.00']))->id,
            'status'      => 'finalized',
            'balance'     => '0.00',
        ]);
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so, '10.00', '100.00');

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_unit_price' => '200.00', // 10 × 200 = 2000 > 500
            ]],
        ]);

        $approver = $this->userWithRole('sales_officer');
        Mail::fake();

        try {
            $this->svc->resolve($response, $approver, 'accept');
            $this->fail('Accepting a counter-offer beyond the credit limit must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_limit', $e->errors());
        }

        // The whole resolution rolled back: the line and the response are intact.
        $this->assertSame('100.00', (string) $line->fresh()->unit_price);
        $this->assertSame(SalesOrderResponseStatus::Pending, $response->fresh()->status);
    }

    public function test_reject_leaves_the_order_untouched(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so, '100.00', '10.00');

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_quantity'   => '90.00',
            ]],
        ]);

        $approver = $this->userWithRole('sales_officer');
        Mail::fake();

        $resolved = $this->svc->resolve($response, $approver, 'reject', 'Price unacceptable.');

        $this->assertSame(SalesOrderResponseStatus::Rejected, $resolved->status);
        $this->assertSame('Price unacceptable.', $resolved->resolution_notes);
        $this->assertSame('100.00', (string) $line->fresh()->quantity);
        $this->assertSame('draft', $so->fresh()->status->value);
    }

    public function test_resolve_refuses_a_non_pending_response(): void
    {
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $response = $this->svc->respond($so, (int) $customer->id, null, ['type' => 'accept']);

        $approver = $this->userWithRole('sales_officer');

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Only a pending customer response can be resolved.');

        $this->svc->resolve($response, $approver, 'accept');
    }

    /* ─── Internal endpoints ─────────────────────────────────────── */

    public function test_internal_responses_index_lists_rows_for_the_order(): void
    {
        $officer = $this->userWithRole('sales_officer');
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $this->svc->respond($so, (int) $customer->id, null, ['type' => 'decline']);

        $this->actingAs($officer, 'sanctum')
            ->getJson("/api/v1/crm/sales-orders/{$so->hash_id}/responses")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'decline')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_internal_responses_index_requires_crm_sales_order_view(): void
    {
        $outsider = $this->userWithRole('employee');
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/crm/sales-orders/{$so->hash_id}/responses")
            ->assertForbidden();
    }

    public function test_internal_accept_endpoint_resolves_a_proposal(): void
    {
        $officer = $this->userWithRole('sales_officer');
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $line = $this->makeLine($so, '10.00', '100.00');

        $response = $this->svc->respond($so, (int) $customer->id, null, [
            'type'  => 'propose',
            'items' => [[
                'sales_order_item_id' => $line->hash_id,
                'proposed_unit_price' => '120.00',
            ]],
        ]);
        Mail::fake();

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/v1/crm/sales-order-responses/{$response->hash_id}/accept", ['notes' => 'Approved.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.resolution_notes', 'Approved.');

        $this->assertSame('120.00', (string) $line->fresh()->unit_price);
        $this->assertSame('1200.00', (string) $line->fresh()->total);
    }

    public function test_internal_reject_endpoint_requires_crm_sales_order_confirm(): void
    {
        $outsider = $this->userWithRole('employee');
        $customer = Customer::factory()->create();
        $so = $this->makeSo($customer);
        $response = $this->svc->respond($so, (int) $customer->id, null, ['type' => 'accept']);

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/v1/crm/sales-order-responses/{$response->hash_id}/reject", ['reason' => 'no'])
            ->assertForbidden();
    }

    public function test_request_customer_confirmation_stamps_the_order(): void
    {
        $officer = $this->userWithRole('sales_officer');
        $customer = Customer::factory()->create();
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status'      => 'draft',
        ]);
        $this->makeLine($so, '5.00', '10.00');

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/request-customer-confirmation")
            ->assertOk()
            ->assertJsonPath('data.customer_confirmation_requested_at', fn ($v) => $v !== null);

        $this->assertNotNull($so->fresh()->customer_confirmation_requested_at);
    }
}
