<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\WarehouseDashboardService;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `supply_chain.deliveries.view` — the read counterpart the delivery slugs never
 * had.
 *
 * `supply_chain.deliveries.create` and `.confirm` existed; the only READ was
 * `supply_chain.view`, which also carries shipments, fleet and customs
 * documents. So the warehouse could not be shown which trucks leave today
 * without being handed the whole import/export desk — and it wasn't: its
 * delivery-schedule widget and outgoing-queue panel were both silently stripped
 * at render, while its default layout claimed to include them.
 *
 * The narrow slug is the fix. These tests pin BOTH halves: the warehouse gains
 * deliveries, and gains nothing else.
 */
class DeliveryReadPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolePermissionSeeder::class,
            DashboardWidgetSeeder::class,
            DashboardRoleLayoutSeeder::class,
        ]);
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'dlv+'.substr(uniqid(), -8).'@t.test',
            'is_active' => true,
        ]);
    }

    public function test_the_slug_is_seeded_in_the_supply_chain_module(): void
    {
        $this->assertDatabaseHas('permissions', [
            'slug' => 'supply_chain.deliveries.view',
            'module' => 'supply_chain',
        ]);
    }

    /** @return array<int, array{0: string}> */
    public static function holderProvider(): array
    {
        // Everyone who could already read deliveries through supply_chain.view,
        // plus the warehouse. Granting the narrow slug to the existing holders is
        // what keeps the re-gated widgets from vanishing for them.
        return [
            'warehouse_staff' => ['warehouse_staff'],
            'purchasing_officer' => ['purchasing_officer'],
            'impex_officer' => ['impex_officer'],
        ];
    }

    /** @dataProvider holderProvider */
    public function test_delivery_readers_hold_the_narrow_slug(string $slug): void
    {
        $this->assertTrue(
            $this->userWithRole($slug)->hasPermission('supply_chain.deliveries.view'),
            "{$slug} lost its delivery read",
        );
    }

    /** @dataProvider holderProvider */
    public function test_delivery_readers_keep_the_delivery_widgets(string $slug): void
    {
        $available = collect(app(DashboardLayoutService::class)
            ->listAvailableWidgets($this->userWithRole($slug)))
            ->pluck('key');

        $this->assertTrue($available->contains('supply.delivery_schedule'));
        $this->assertTrue($available->contains('supply.overdue_deliveries'));
    }

    /**
     * The whole point: the warehouse gains deliveries and NOT the module. If this
     * ever flips to true, the narrow slug has stopped being narrow.
     */
    public function test_the_warehouse_does_not_gain_the_rest_of_the_module(): void
    {
        $user = $this->userWithRole('warehouse_staff');

        $this->assertFalse($user->hasPermission('supply_chain.view'));
        $this->assertFalse($user->hasPermission('supply_chain.shipments.manage'));
        $this->assertFalse($user->hasPermission('supply_chain.fleet.manage'));
        $this->assertFalse($user->hasPermission('supply_chain.deliveries.create'));
    }

    public function test_the_warehouse_may_read_the_delivery_list_but_not_shipments(): void
    {
        $this->actingAs($this->userWithRole('warehouse_staff'));

        // The tile's "Open →" target must not 403 the role the tile is for.
        $this->getJson('/api/v1/supply-chain/deliveries')->assertOk();
        $this->getJson('/api/v1/supply-chain/shipments')->assertForbidden();
    }

    /** The broad module read still reaches deliveries on its own. */
    public function test_the_broad_module_read_still_opens_deliveries(): void
    {
        $role = Role::create(['name' => 'Legacy SC', 'slug' => 'legacy-sc', 'is_system' => false]);
        $role->permissions()->sync([
            Permission::query()->where('slug', 'supply_chain.view')->value('id'),
        ]);

        $this->actingAs(User::factory()->create(['role_id' => $role->id]))
            ->getJson('/api/v1/supply-chain/deliveries')
            ->assertOk();
    }

    public function test_the_delivery_tile_is_back_on_the_warehouse_default_layout(): void
    {
        $keys = collect(app(DashboardLayoutService::class)
            ->getEffectiveLayout($this->userWithRole('warehouse_staff')))
            ->pluck('key');

        $this->assertTrue(
            $keys->contains('supply.delivery_schedule'),
            'the default claims this tile; the registry must not strip it',
        );
    }

    public function test_the_warehouse_dashboard_now_carries_its_outgoing_queue(): void
    {
        $data = app(WarehouseDashboardService::class)
            ->warehouse($this->userWithRole('warehouse_staff'));

        $this->assertArrayHasKey('outgoing_queue', $data['panels']);
        $this->assertArrayHasKey('incoming_queue', $data['panels']);
    }

    /** A role with neither read gets neither the panel nor the widget. */
    public function test_a_role_with_no_delivery_read_gets_neither(): void
    {
        $user = $this->userWithRole('qc_inspector');

        $this->assertFalse($user->hasPermission('supply_chain.deliveries.view'));
        $this->assertFalse(
            collect(app(DashboardLayoutService::class)->listAvailableWidgets($user))
                ->pluck('key')
                ->contains('supply.delivery_schedule'),
        );
    }
}
