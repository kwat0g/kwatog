<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\Alert;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionCenterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/dashboards/action-center')->assertUnauthorized();
    }

    public function test_user_without_source_permissions_gets_an_empty_queue(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_only_permitted_sources_are_returned_with_summary_metadata(): void
    {
        $user = $this->userWithPermissions(['alerts.view']);

        Alert::query()->create([
            'type' => 'stock_critical',
            'severity' => 'critical',
            'title' => 'Resin stock is critical',
            'message' => 'Available stock is below the configured safety level.',
            'is_read' => false,
            'is_dismissed' => false,
        ]);

        Alert::query()->create([
            'type' => 'stock_low',
            'severity' => 'warning',
            'title' => 'Dismissed alert',
            'message' => 'This item must not enter the action queue.',
            'is_read' => true,
            'is_dismissed' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.critical', 1)
            ->assertJsonPath('data.summary.by_category.alert', 1)
            ->assertJsonPath('data.items.0.category', 'alert')
            ->assertJsonPath('data.items.0.priority', 'critical')
            ->assertJsonPath('data.items.0.title', 'Resin stock is critical')
            ->assertJsonStructure(['data' => ['items', 'summary', 'generated_at']]);
    }

    public function test_exception_can_be_claimed_and_resolved_with_an_audit_event(): void
    {
        $user = $this->userWithPermissions(['alerts.view', 'alerts.dismiss']);
        $alert = Alert::query()->create([
            'type' => 'stock_low', 'severity' => 'warning', 'title' => 'Low stock',
            'message' => 'Replenishment required.', 'is_read' => false, 'is_dismissed' => false,
        ]);
        $itemKey = 'alert:'.$alert->hash_id;

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/dashboards/action-center/tasks', [
            'item_ids' => [$itemKey], 'action' => 'claim',
        ])->assertOk()->assertJsonPath('data.0.state', 'acknowledged')
            ->assertJsonPath('data.0.assigned_to.name', $user->name);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/dashboards/action-center/tasks', [
            'item_ids' => [$itemKey], 'action' => 'resolve', 'notes' => 'Replenishment submitted.',
        ])->assertOk()->assertJsonPath('data.0.state', 'resolved');

        $this->assertTrue($alert->fresh()->is_dismissed);
        $this->assertDatabaseCount('action_center_task_events', 2);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/exceptions')
            ->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Action Center Test',
            'slug' => 'action_center_test_'.bin2hex(random_bytes(3)),
            'is_system' => false,
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'module' => 'test',
            ]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
