<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_confirmation_action_is_scoped_to_permission_and_current_status(): void
    {
        $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft->value]);
        $sales = $this->userWith(
            'crm.sales_orders.view',
            'crm.sales_orders.confirm',
            'crm.sales_orders.update',
            'crm.sales_orders.cancel',
        );
        $viewer = $this->userWith('crm.sales_orders.view');

        $this->actingAs($sales)
            ->getJson("/api/v1/crm/sales-orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_confirm', true)
            ->assertJsonPath('data.available_actions.can_edit', true)
            ->assertJsonPath('data.available_actions.can_cancel', true);

        $this->actingAs($viewer)
            ->getJson("/api/v1/crm/sales-orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_confirm', false)
            ->assertJsonPath('data.available_actions.can_edit', false)
            ->assertJsonPath('data.available_actions.can_cancel', false);

        $order->update(['status' => SalesOrderStatus::Confirmed->value]);

        $this->actingAs($sales)
            ->getJson("/api/v1/crm/sales-orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_confirm', false)
            ->assertJsonPath('data.available_actions.can_edit', false)
            ->assertJsonPath('data.available_actions.can_cancel', true);
    }

    private function userWith(string ...$permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Sales action test '.uniqid(),
            'slug' => 'sales_action_test_'.uniqid(),
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('.', ' ', $slug)), 'module' => 'crm'],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
