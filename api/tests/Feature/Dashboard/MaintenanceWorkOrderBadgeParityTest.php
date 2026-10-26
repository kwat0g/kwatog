<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MaintenanceWorkOrderBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_open_and_unassigned_badges_match_their_filtered_lists_and_permissions(): void
    {
        $supervisor = User::factory()->withRole('maintenance_supervisor')->create();
        $this->assertTrue($supervisor->hasPermission('maintenance.view'));
        $this->assertTrue($supervisor->hasPermission('maintenance.wo.assign'));
        $assignee = Employee::factory()->create();

        $this->order($supervisor, 'open');
        $this->order($supervisor, 'assigned', $assignee->id);
        $this->order($supervisor, 'in_progress', $assignee->id);
        $this->order($supervisor, 'assigned');
        $this->order($supervisor, 'completed');
        $this->order($supervisor, 'cancelled');

        $this->assertSame(4, $this->assertBadgeMatchesList($supervisor, 'maintenance_wo', 'open'));
        $this->assertSame(2, $this->assertBadgeMatchesList($supervisor, 'maintenance_wo_unassigned', 'unassigned'));

        $viewOnly = $this->userWithPermissions('Maintenance Viewer', ['maintenance.view']);
        $viewBadges = $this->actingAs($viewOnly, 'sanctum')->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertSame(4, $viewBadges['maintenance_wo']['count']);
        $this->assertArrayNotHasKey('maintenance_wo_unassigned', $viewBadges);

        $assignOnly = $this->userWithPermissions('Maintenance Assign Only', ['maintenance.wo.assign']);
        $assignBadges = $this->actingAs($assignOnly, 'sanctum')->getJson('/api/v1/dashboards/badges')->assertOk()->json('data');
        $this->assertArrayNotHasKey('maintenance_wo', $assignBadges);
        $this->assertArrayNotHasKey('maintenance_wo_unassigned', $assignBadges);
    }

    private function assertBadgeMatchesList(User $user, string $key, string $queue): int
    {
        $badge = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json("data.{$key}.count");
        $total = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/maintenance/work-orders?queue='.$queue)
            ->assertOk()
            ->json('meta.total');

        $this->assertSame($total, $badge);

        return $total;
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(string $name, array $permissions): User
    {
        $role = Role::create(['name' => $name, 'slug' => str($name)->slug('_')->value()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function order(User $creator, string $status, ?int $assigneeId = null): MaintenanceWorkOrder
    {
        $order = (new MaintenanceWorkOrder)->forceFill([
            'mwo_number' => 'BADGE-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create(['status' => 'idle'])->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Badge parity test',
            'status' => $status,
            'assigned_to' => $assigneeId,
            'created_by' => $creator->id,
            'verification_required' => true,
        ]);
        $order->save();

        return $order;
    }
}
