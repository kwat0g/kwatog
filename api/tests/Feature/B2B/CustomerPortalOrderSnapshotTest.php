<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalOrderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_snapshot_has_real_work_order_quantities_and_matches_crm_delivery_progress_without_writing(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $customer = Customer::factory()->create();
        $portal = CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Customer', 'email' => 'snapshot@test.test',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::InProduction]);
        $line = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id, 'quantity' => '500.00', 'quantity_delivered' => '500.00',
        ]);
        $workOrder = WorkOrder::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $line->product_id,
            'quantity_target' => 500, 'quantity_produced' => 500, 'quantity_good' => 500,
            'planned_start' => '2026-10-09 08:00:00', 'status' => 'completed',
        ]);
        WorkOrder::factory()->create(['sales_order_id' => SalesOrder::factory()->create()->id, 'quantity_target' => 999]);
        $before = $order->fresh()->getRawOriginal();

        $response = $this->actingAs($portal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/orders/'.$order->hash_id)->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.status_label', 'Delivered')
            ->assertJsonCount(1, 'data.work_orders')
            ->assertJsonPath('data.work_orders.0.id', $workOrder->hash_id)
            ->assertJsonPath('data.work_orders.0.quantity_target', 500)
            ->assertJsonPath('data.work_orders.0.quantity_produced', 500)
            ->assertJsonPath('data.work_orders.0.planned_start', $workOrder->planned_start->toIso8601String());
        $crm = (new SalesOrderResource(app(SalesOrderService::class)->show($order->fresh())))->resolve();
        $this->assertSame($crm['status'], $response->json('data.status'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertArrayNotHasKey('machine', $response->json('data.work_orders.0'));
        $this->assertArrayNotHasKey('mold', $response->json('data.work_orders.0'));
        $this->assertArrayNotHasKey('unit_cost', $response->json('data.work_orders.0'));
        $this->getJson('/api/v1/b2b/customer/orders/'.SalesOrder::factory()->create()->hash_id)->assertForbidden();
    }
}
