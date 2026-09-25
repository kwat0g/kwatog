<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderFormOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_production_roles_can_load_complete_work_order_lookups_without_crm_or_attendance_access(): void
    {
        $products = Product::factory()->count(101)->sequence(
            ...array_map(static fn (int $n): array => ['part_number' => sprintf('ZZZ-FORM-%03d', $n)], range(1, 101)),
        )->create();

        Machine::factory()->count(101)->sequence(
            ...array_map(static fn (int $n): array => ['machine_code' => sprintf('ZZZ-FM-%03d', $n)], range(1, 101)),
        )->create();

        foreach (range(1, 101) as $n) {
            Mold::query()->create([
                'mold_code' => sprintf('ZZZ-FOLD-%03d', $n),
                'name' => sprintf('Form options mold %03d', $n),
                'product_id' => $products->first()->id,
                'cavity_count' => 2,
                'cycle_time_seconds' => 30,
                'output_rate_per_hour' => 120,
                'max_shots_before_maintenance' => 100000,
                'lifetime_max_shots' => 1000000,
                'status' => 'available',
            ]);
        }
        Shift::query()->create([
            'name' => 'Production lookup shift',
            'start_time' => '07:00',
            'end_time' => '16:00',
            'break_minutes' => 60,
            'grace_minutes' => 0,
            'is_active' => true,
        ]);

        $manager = $this->userWithRole('production_manager');
        $this->assertTrue($manager->hasPermission('production.wo.create'));
        $this->assertFalse($manager->hasPermission('crm.products.view'));
        $this->assertFalse($manager->hasPermission('attendance.edit'));

        $lookups = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/production/work-orders/form-options')
            ->assertOk()
            ->json('data');

        $this->assertCount(101, $lookups['products']);
        $this->assertCount(101, $lookups['machines']);
        $this->assertCount(101, $lookups['molds']);
        $this->assertSame('ZZZ-FORM-101', $lookups['products'][100]['part_number']);
        $this->assertSame('ZZZ-FM-101', $lookups['machines'][100]['machine_code']);
        $this->assertSame('ZZZ-FOLD-101', $lookups['molds'][100]['mold_code']);
        $this->assertContains('Production lookup shift', array_column($lookups['shifts'], 'name'));

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/crm/products')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/attendance/shifts')->assertForbidden();

        $ppc = $this->userWithRole('ppc_head');
        $this->actingAs($ppc, 'sanctum')
            ->getJson('/api/v1/production/work-orders/form-options')
            ->assertOk()
            ->assertJsonPath('data.products.100.part_number', 'ZZZ-FORM-101')
            ->assertJsonFragment(['name' => 'Production lookup shift']);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }
}
