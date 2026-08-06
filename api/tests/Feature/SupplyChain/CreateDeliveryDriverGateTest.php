<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Driver assignment gate (REC).
 *
 * Assigning a non-driver user as delivery driver would hand that user the
 * driver PWA surface — customer names/addresses, delivery status mutation.
 * The role must be validated at assignment time, not only at PWA login.
 */
class CreateDeliveryDriverGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_non_driver_cannot_be_assigned_as_delivery_driver(): void
    {
        $officer = $this->officer();
        $soItem = $this->soItem();
        $nonDriver = User::factory()->create(); // default (employee) role

        $this->actingAs($officer)
            ->postJson('/api/v1/supply-chain/deliveries', $this->payload($soItem, $nonDriver))
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_driver_role_can_be_assigned(): void
    {
        $officer = $this->officer();
        $soItem = $this->soItem();
        $driver = $this->driver();

        $response = $this->actingAs($officer)
            ->postJson('/api/v1/supply-chain/deliveries', $this->payload($soItem, $driver))
            ->assertStatus(201);

        $this->assertDatabaseHas('deliveries', [
            'id'        => $this->deliveryId($response),
            'driver_id' => $driver->id,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function officer(): User
    {
        // No seeded role carries supply_chain.deliveries.create, so grant the
        // permission to a throwaway role (same convention as DeliveryConfirmTest).
        $role = Role::create([
            'name'        => 'Gate Test Role ' . uniqid(),
            'slug'        => 'gate_test_' . uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.create'],
            ['name' => 'Create Deliveries', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function driver(): User
    {
        $role = Role::query()->where('slug', 'driver')->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function deliveryId($response): int
    {
        $decoded = app('hashids')->decode($response->json('data.id'));
        return (int) ($decoded[0] ?? 0);
    }

    private function soItem(): SalesOrderItem
    {
        $role = Role::firstOrCreate(['slug' => 'so_test'], ['name' => 'SO Test']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $customer = Customer::create([
            'name'      => 'Cust ' . uniqid(),
            'is_active' => true,
        ]);

        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Wiper Bushing ' . uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        $so = SalesOrder::create([
            'so_number'    => 'SO-GT-' . substr(uniqid(), -10),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '1000.00',
            'vat_amount'   => '120.00',
            'total_amount' => '1120.00',
            'status'       => 'confirmed',
            'created_by'   => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id'     => $product->id,
            'quantity'       => '10',
            'unit_price'     => '100.00',
            'total'          => '1000.00',
            'delivery_date'  => now()->toDateString(),
        ]);

        Inspection::create([
            'inspection_number' => 'QC-GT-' . substr(uniqid(), -8),
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => InspectionStatus::Passed->value,
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => $so->id,
            'batch_quantity'    => 10,
            'sample_size'       => 5,
            'accept_count'      => 0,
            'reject_count'      => 0,
            'defect_count'      => 0,
            'completed_at'      => now(),
        ]);

        return $soItem;
    }

    private function payload(SalesOrderItem $soItem, User $driver): array
    {
        return [
            'sales_order_id' => $soItem->salesOrder->hash_id,
            'driver_id'      => $driver->hash_id,
            'scheduled_date' => now()->toDateString(),
            'items'          => [
                ['sales_order_item_id' => $soItem->hash_id, 'quantity' => 5],
            ],
        ];
    }
}
