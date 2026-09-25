<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — outbound dispatch needed the IT administrator:
 * `supply_chain.deliveries.create` / `.confirm` had no business holder, so
 * no seeded role could assign, move or confirm a delivery. ImpEx owns it now,
 * and Finance can open the delivery its auto-invoice failure notice links to.
 */
class OutboundDispatchOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    private function inTransitDelivery(User $by): Delivery
    {
        $customer = Customer::create(['name' => 'Dispatch Customer '.uniqid(), 'is_active' => true]);
        $so = SalesOrder::create([
            'so_number' => 'SO-T-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'created_by' => $by->id,
        ]);
        $so->forceFill(['status' => 'confirmed'])->save();
        $delivery = Delivery::create([
            'delivery_number' => 'DL-T-'.substr(uniqid(), -5),
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'created_by' => $by->id,
        ]);
        $delivery->forceFill(['status' => 'in_transit'])->save();

        return $delivery;
    }

    public function test_impex_officer_can_dispatch_and_confirm_deliveries(): void
    {
        $impex = $this->userWithRole('impex_officer');
        $delivery = $this->inTransitDelivery($impex);

        $this->assertTrue($impex->hasPermission('supply_chain.deliveries.create'));
        $this->assertTrue($impex->hasPermission('supply_chain.deliveries.confirm'));
        $this->actingAs($impex)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_warehouse_keeps_its_read_only_staging_view(): void
    {
        $warehouse = $this->userWithRole('warehouse_staff');
        $delivery = $this->inTransitDelivery($warehouse);

        $this->actingAs($warehouse)->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}")->assertOk();
        $this->actingAs($warehouse)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", ['status' => 'delivered'])
            ->assertForbidden();
    }

    public function test_finance_can_open_the_delivery_its_invoice_recovery_links_to(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $delivery = $this->inTransitDelivery($finance);

        $this->actingAs($finance)->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}")->assertOk();
        $this->actingAs($finance)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/status", ['status' => 'delivered'])
            ->assertForbidden();
    }

    public function test_migration_grants_existing_roles_and_rolls_back(): void
    {
        $migration = require database_path('migrations/0561_grant_outbound_dispatch_to_impex_officer.php');
        $impexId = Role::query()->where('slug', 'impex_officer')->value('id');
        $financeId = Role::query()->where('slug', 'finance_officer')->value('id');
        $has = fn (int $roleId, string $slug): bool => DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', Permission::query()->where('slug', $slug)->value('id'))
            ->exists();
        $granted = fn (): array => [
            $has($impexId, 'supply_chain.deliveries.create'),
            $has($impexId, 'supply_chain.deliveries.confirm'),
            $has($financeId, 'supply_chain.deliveries.view'),
        ];

        $migration->down();
        $this->assertSame([false, false, false], $granted());
        $this->assertTrue($has($impexId, 'supply_chain.deliveries.view'), 'down() leaves grants it did not add');

        $migration->up();
        $migration->up();
        $this->assertSame([true, true, true], $granted());
    }
}
