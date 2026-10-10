<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Services\SystemUserResolver;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Customer portal delivery receipt confirmation. Pins ownership scoping, the
 * reused DeliveryService::confirm() effect, external-actor audit attribution,
 * state gating, and idempotency on the HTTP path.
 */
class CustomerPortalDeliveryConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $this->seed(ChartOfAccountsSeeder::class);
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

    private function seedSalesOrder(Customer $customer, User $creator): SalesOrder
    {
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'status' => 'confirmed',
        ]);
        $product = Product::factory()->create();
        SalesOrderItem::create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'total' => '100.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]);

        return $salesOrder;
    }

    private function seedDelivery(SalesOrder $salesOrder, User $creator, string $status = 'delivered'): Delivery
    {
        $delivery = Delivery::create([
            'delivery_number' => 'DR-'.now()->format('Ym').'-'.substr(uniqid(), -5),
            'sales_order_id'  => $salesOrder->id,
            'scheduled_date'  => now()->toDateString(),
            'status'          => $status,
            'delivered_at'    => in_array($status, ['delivered', 'confirmed'], true) ? now() : null,
            'created_by'      => $creator->id,
        ]);
        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => SalesOrderItem::query()->where('sales_order_id', $salesOrder->id)->firstOrFail()->id,
            'quantity' => '1.000',
            'customer_received_quantity' => in_array($status, ['delivered', 'confirmed'], true) ? '1.000' : null,
            'unit_price' => '100.00',
        ]);

        return $delivery;
    }

    private function receiptLines(Delivery $delivery): array
    {
        return $delivery->items()->get()->map(fn ($item): array => [
            'delivery_item_id' => $item->hash_id,
            'received_quantity' => rtrim(preg_replace('/(\.\d*?)0+$/', '$1', (string) ($item->customer_received_quantity ?? $item->quantity)), '.'),
        ])->all();
    }

    private function addProof(Delivery $delivery, User $uploader): DeliveryProof
    {
        return DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => 'signed_dr',
            'file_name'   => 'signed-dr.pdf',
            'file_path'   => 'deliveries/'.$delivery->id.'/signed-dr.pdf',
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $uploader->id,
        ]);
    }

    /* ─── Confirm ────────────────────────────────────────────────── */

    public function test_customer_confirms_own_delivered_delivery(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $salesOrder = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($salesOrder, $internal, 'delivered');
        $this->addProof($delivery, $internal);

        $systemUser = app(SystemUserResolver::class)->user();

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Juan Dela Cruz',
            'lines' => $this->receiptLines($delivery),
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Delivery confirmed. Thank you for confirming receipt.');
        $response->assertJsonPath('data.status', 'confirmed');

        $fresh = $delivery->fresh();
        $this->assertSame('confirmed', $fresh->status->value);
        $this->assertSame($systemUser->id, $fresh->confirmed_by);
        $this->assertSame('Juan Dela Cruz', $fresh->receiver_name);

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'customer_portal',
            'action' => 'customer.delivery.confirmed',
            'model_type' => Delivery::class,
            'model_id' => $delivery->id,
        ]);
        $log = AuditLog::where('action', 'customer.delivery.confirmed')->where('model_id', $delivery->id)->firstOrFail();
        $this->assertSame($user->hash_id, $log->new_values['portal_user_id']);
        $this->assertSame($delivery->delivery_number, $log->new_values['delivery_number']);
        $this->assertSame($this->receiptLines($delivery), $log->new_values['lines']);
    }

    public function test_customer_cannot_confirm_another_customers_delivery(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internal = User::factory()->create();

        $otherSalesOrder = $this->seedSalesOrder($otherCustomer, $internal);
        $delivery = $this->seedDelivery($otherSalesOrder, $internal, 'delivered');
        $this->addProof($delivery, $internal);

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Juan Dela Cruz',
            'lines' => $this->receiptLines($delivery),
        ])->assertNotFound();

        $this->assertSame('delivered', $delivery->fresh()->status->value);
    }

    public function test_customer_cannot_confirm_delivery_not_in_delivered_state(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $salesOrder = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($salesOrder, $internal, 'in_transit');
        $this->addProof($delivery, $internal);

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Juan Dela Cruz',
            'lines' => $this->receiptLines($delivery),
        ])->assertStatus(422);

        $this->assertSame('in_transit', $delivery->fresh()->status->value);
    }

    public function test_customer_cannot_confirm_delivered_delivery_without_proof(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $salesOrder = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($salesOrder, $internal, 'delivered');

        $this->actAs($user);
        Log::spy();

        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Juan Dela Cruz',
            'lines' => $this->receiptLines($delivery),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'At least one proof of delivery (signed DR or photo) must be uploaded before confirming.');
        Log::shouldNotHaveReceived('error');

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->confirmed_at);
        $this->assertNull($delivery->fresh()->confirmed_by);
        $this->assertSame(0, $delivery->proofs()->count());
        $this->assertSame(0, $salesOrder->invoices()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'actor_type' => 'customer_portal',
            'action' => 'customer.delivery.confirmed',
            'model_type' => Delivery::class,
            'model_id' => $delivery->id,
        ]);
    }

    public function test_customer_confirmation_rolls_back_when_external_actor_audit_fails(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $salesOrder = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($salesOrder, $internal, 'delivered');
        $this->addProof($delivery, $internal);
        $auditCountBefore = AuditLog::query()->where('model_type', Delivery::class)->where('model_id', $delivery->id)->count();
        $outboxCountBefore = DB::table('event_outbox')->count();
        $chainRunCountBefore = DB::table('chain_step_runs')->where('entity_type', 'delivery')->where('entity_id', $delivery->id)->count();
        $journalCountBefore = DB::table('journal_entries')->count();
        $stockMovementCountBefore = DB::table('stock_movements')->count();

        Event::listen('eloquent.creating: '.AuditLog::class, static function (AuditLog $log): void {
            if ($log->action === 'customer.delivery.confirmed') {
                throw new \RuntimeException('Injected external actor audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        $this->actAs($portalUser);

        try {
            $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
                'receiver_name' => 'Juan Dela Cruz',
                'lines' => $this->receiptLines($delivery),
            ]);
            $this->fail('The injected audit failure should escape the request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected external actor audit failure.', $exception->getMessage());
        }

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->confirmed_at);
        $this->assertNull($delivery->fresh()->confirmed_by);
        $this->assertNull($delivery->fresh()->invoice_id);
        $this->assertSame($auditCountBefore, AuditLog::query()->where('model_type', Delivery::class)->where('model_id', $delivery->id)->count());
        $this->assertSame(0, DB::table('invoices')->where('delivery_id', $delivery->id)->count());
        $this->assertSame(0, AuditLog::query()->where('actor_type', 'customer_portal')->where('action', 'customer.delivery.confirmed')->where('model_id', $delivery->id)->count());
        $this->assertSame($outboxCountBefore, DB::table('event_outbox')->count());
        $this->assertSame($chainRunCountBefore, DB::table('chain_step_runs')->where('entity_type', 'delivery')->where('entity_id', $delivery->id)->count());
        $this->assertSame($journalCountBefore, DB::table('journal_entries')->count());
        $this->assertSame($stockMovementCountBefore, DB::table('stock_movements')->count());
    }

    public function test_confirming_already_confirmed_delivery_is_idempotent(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $salesOrder = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($salesOrder, $internal, 'confirmed');
        $this->addProof($delivery, $internal);

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Juan Dela Cruz',
            'lines' => $this->receiptLines($delivery),
        ])->assertOk();

        $this->assertSame('confirmed', $delivery->fresh()->status->value);
        $this->assertSame(0, AuditLog::where('action', 'customer.delivery.confirmed')->where('model_id', $delivery->id)->count());
    }

    public function test_receipt_confirmation_requires_receiver_and_every_line_quantity_without_side_effects(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $order = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($order, $internal);
        $this->addProof($delivery, $internal);
        $this->actAs($portalUser);
        $auditCount = AuditLog::count();

        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['receiver_name', 'lines']);
        foreach (['-1', 'NaN', 'Infinity', '1e0', '1.0001', '9999999999999', 1, ''] as $quantity) {
            $lines = $this->receiptLines($delivery);
            $lines[0]['received_quantity'] = $quantity;
            $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
                'receiver_name' => 'Receiver', 'lines' => $lines,
            ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.received_quantity');
        }

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->receiver_name);
        $this->assertNull($delivery->fresh()->invoice_id);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame(0, $order->invoices()->count());
    }

    public function test_receipt_shortage_excess_and_fractional_piece_counts_cannot_confirm(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $order = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($order, $internal);
        $this->addProof($delivery, $internal);
        $this->actAs($portalUser);
        $auditCount = AuditLog::count();

        foreach (['0', '2', '0.500'] as $quantity) {
            $lines = $this->receiptLines($delivery);
            $lines[0]['received_quantity'] = $quantity;
            $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
                'receiver_name' => 'Receiver', 'lines' => $lines,
            ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.received_quantity');
        }
        $this->assertSame($auditCount, AuditLog::count());

        $item = $delivery->items()->sole();
        $item->update(['customer_received_quantity' => '0.500']);
        $response = $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Receiver', 'lines' => [['delivery_item_id' => $item->hash_id, 'received_quantity' => '0.50']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.received_quantity');
        $this->assertSame('Enter a whole number of pieces.', $response->json('errors')['lines.0.received_quantity'][0]);
        $item->update(['customer_received_quantity' => '1.000']);
        $auditCount = AuditLog::count();

        $this->assertSame('1.000', $delivery->items()->sole()->customer_received_quantity);
        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame(0, $order->invoices()->count());
    }

    public function test_receipt_confirmation_checks_current_ownership_instead_of_stale_order_relation(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $order = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($order, $internal);
        $this->addProof($delivery, $internal);
        $delivery->load('salesOrder');
        $otherOrder = $this->seedSalesOrder(Customer::factory()->create(), $internal);
        Delivery::query()->whereKey($delivery->id)->update(['sales_order_id' => $otherOrder->id]);
        $auditCount = AuditLog::count();

        try {
            app(\App\Modules\B2B\Services\CustomerPortalService::class)->confirmDelivery($customer->id, $delivery, [
                'receiver_name' => 'Receiver', 'lines' => $this->receiptLines($delivery),
            ], $portalUser);
            $this->fail('A stale delivery relation cannot authorize confirmation for another customer.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->invoice_id);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_receipt_confirmation_rejects_missing_duplicate_and_foreign_items(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $order = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($order, $internal);
        $this->addProof($delivery, $internal);
        $otherOrder = $this->seedSalesOrder($customer, $internal);
        $other = $this->seedDelivery($otherOrder, $internal);
        $this->actAs($portalUser);
        $first = $this->receiptLines($delivery)[0];
        $auditCount = AuditLog::count();

        foreach ([[], [$first, $first], $this->receiptLines($other)] as $lines) {
            $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
                'receiver_name' => 'Receiver', 'lines' => $lines,
            ])->assertUnprocessable();
        }
        DeliveryItem::create([
            'delivery_id' => $delivery->id, 'sales_order_item_id' => $delivery->items()->sole()->sales_order_item_id,
            'quantity' => '1.000', 'customer_received_quantity' => '1.000', 'unit_price' => '100.00',
        ]);
        $auditCount = AuditLog::count();
        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Receiver', 'lines' => [$first],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame(0, $order->invoices()->count());
    }

    public function test_receipt_confirmation_preserves_audited_partial_quantity_and_billing_precision(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = $this->makePortalUser($customer);
        $internal = User::factory()->create();
        $order = $this->seedSalesOrder($customer, $internal);
        $delivery = $this->seedDelivery($order, $internal);
        $this->addProof($delivery, $internal);
        $item = $delivery->items()->sole();
        $item->salesOrderItem->product->update(['unit_of_measure' => 'kg']);
        $item->update(['customer_received_quantity' => '0.125']);
        $this->actAs($portalUser);

        $this->getJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}")
            ->assertOk()->assertJsonPath('data.items.0.unit_of_measure', 'kg')
            ->assertJsonPath('data.items.0.quantity_delivered', '0.125');
        $auditCount = AuditLog::count();
        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Receiving Officer', 'lines' => $this->receiptLines($delivery),
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.received_quantity');
        $this->assertSame('0.125', $item->fresh()->customer_received_quantity);
        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->invoice_id);
        $this->assertSame($auditCount, AuditLog::count());
        $item->update(['customer_received_quantity' => '0.120']);
        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => '  Receiving Officer  ', 'receiver_position' => 'Warehouse Lead',
            'delivery_remarks' => 'Received the audited partial quantity.', 'lines' => $this->receiptLines($delivery),
        ])->assertOk()->assertJsonPath('data.receiver_name', 'Receiving Officer')
            ->assertJsonPath('data.receiver_position', 'Warehouse Lead')
            ->assertJsonPath('data.items.0.unit_of_measure', 'kg');

        $this->assertSame('0.120', $item->fresh()->customer_received_quantity);
        $this->assertSame('confirmed', $delivery->fresh()->status->value);
        $log = AuditLog::where('action', 'customer.delivery.confirmed')->where('model_id', $delivery->id)->sole();
        $this->assertSame('0.12', $log->new_values['lines'][0]['received_quantity']);
        $this->postJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}/confirm", [
            'receiver_name' => 'Different receiver', 'lines' => $this->receiptLines($delivery),
        ])->assertOk();
        $this->assertSame('Receiving Officer', $delivery->fresh()->receiver_name);
        $this->assertSame(1, AuditLog::where('action', 'customer.delivery.confirmed')->where('model_id', $delivery->id)->count());
    }
}
