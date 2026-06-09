<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3 — M-29. DeliveryService::delete() must:
 *  - Refuse a delivery whose invoice_id is set, with a precise message
 *    pointing the user at the invoice (more useful than the generic
 *    confirmed-status message).
 *  - Continue to refuse a Confirmed-status delivery as a fallback (covers
 *    edge cases where the invoice failed to attach but the delivery was
 *    confirmed).
 *  - Allow deletion for benign statuses like Scheduled.
 */
class DeliveryDeleteGuardsTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(DeliveryService::class);
    }

    public function test_delete_refuses_delivery_with_linked_invoice(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, status: 'scheduled');

        $invoice = Invoice::factory()->create([
            'customer_id'    => $delivery->salesOrder->customer_id,
            'sales_order_id' => $delivery->sales_order_id,
            'delivery_id'    => $delivery->id,
            'created_by'     => $user->id,
        ]);
        $delivery->forceFill(['invoice_id' => $invoice->id])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked invoice');

        $this->svc->delete($delivery->fresh());
    }

    public function test_delete_refuses_confirmed_delivery_without_invoice_link(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, status: 'confirmed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete a confirmed delivery');

        $this->svc->delete($delivery);
    }

    public function test_delete_succeeds_for_scheduled_delivery(): void
    {
        $user     = $this->makeUser();
        $delivery = $this->seedDelivery($user, status: 'scheduled');
        $id = $delivery->id;

        $this->svc->delete($delivery);

        $this->assertNull(Delivery::find($id),
            'Scheduled delivery without an invoice link must delete cleanly.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name'        => 'Delete Test Role ' . uniqid(),
            'slug'        => 'delete_test_' . uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.delete'],
            ['name' => 'Delete Delivery', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function seedSalesOrder(User $user): SalesOrder
    {
        $customer = Customer::create([
            'name'      => 'M29 Customer ' . uniqid(),
            'is_active' => true,
        ]);
        return SalesOrder::create([
            'so_number'    => 'SO-M29-' . substr(uniqid(), -8),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '1000.00',
            'vat_amount'   => '120.00',
            'total_amount' => '1120.00',
            'status'       => 'confirmed',
            'created_by'   => $user->id,
        ]);
    }

    private function seedDelivery(User $user, string $status = 'scheduled'): Delivery
    {
        $so = $this->seedSalesOrder($user);
        return Delivery::create([
            'delivery_number' => 'DEL-M29-' . substr(uniqid(), -8),
            'sales_order_id'  => $so->id,
            'status'          => $status,
            'scheduled_date'  => now()->toDateString(),
            'created_by'      => $user->id,
        ]);
    }
}
