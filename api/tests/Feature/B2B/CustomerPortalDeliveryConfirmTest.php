<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Services\SystemUserResolver;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        return SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
        ]);
    }

    private function seedDelivery(SalesOrder $salesOrder, User $creator, string $status = 'delivered'): Delivery
    {
        return Delivery::create([
            'delivery_number' => 'DR-'.now()->format('Ym').'-'.substr(uniqid(), -5),
            'sales_order_id'  => $salesOrder->id,
            'scheduled_date'  => now()->toDateString(),
            'status'          => $status,
            'created_by'      => $creator->id,
        ]);
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
        ])->assertStatus(403);

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
        ])->assertStatus(422);

        $this->assertSame('in_transit', $delivery->fresh()->status->value);
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
        ])->assertOk();

        $this->assertSame('confirmed', $delivery->fresh()->status->value);
    }
}
