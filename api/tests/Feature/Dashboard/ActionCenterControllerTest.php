<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\Alert;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\ActionCenterTaskService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
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

    /**
     * An item the caller may not touch is a 403, not a 422.
     *
     * Both refusals in ActionCenterTaskService::assertAllowed were a bare
     * RuntimeException, and the controller answered every one of them with 422
     * and the message — so "you do not have access" arrived as "fix your input",
     * and the SPA's 403 handling (which is what routes a refusal to the right
     * toast) never ran.
     */
    public function test_an_item_the_caller_cannot_see_is_refused_with_403(): void
    {
        $user  = $this->userWithPermissions([]); // no alerts.view
        $alert = Alert::query()->create([
            'type' => 'stock_low', 'severity' => 'warning', 'title' => 'Low stock',
            'message' => 'Replenishment required.', 'is_read' => false, 'is_dismissed' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id], 'action' => 'claim',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to this action-center item.');

        $this->assertDatabaseCount('action_center_tasks', 0);
    }

    /**
     * An unrecognised key prefix stays a 422 — it is the same class of failure as
     * 'Unsupported action-center task action.', which was already a
     * BusinessRuleException a few lines above it.
     */
    public function test_an_unknown_item_key_is_a_422_with_its_message(): void
    {
        $user = $this->userWithPermissions(['alerts.view']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['not-a-known-source:abc'], 'action' => 'claim',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown action-center item.');
    }

    /**
     * The reason the controller's `catch (RuntimeException)` had to go:
     * QueryException extends PDOException extends RuntimeException, so a
     * deadlock or unique violation inside apply()'s transaction was answered
     * with a 422 carrying SQLSTATE, the statement and the column names.
     */
    public function test_a_sql_fault_is_a_500_and_leaks_no_sql(): void
    {
        config(['app.debug' => false]);

        $user  = $this->userWithPermissions(['alerts.view']);
        $alert = Alert::query()->create([
            'type' => 'stock_low', 'severity' => 'warning', 'title' => 'Low stock',
            'message' => 'Replenishment required.', 'is_read' => false, 'is_dismissed' => false,
        ]);

        $previous = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        $previous->errorInfo = ['40P01', 7, 'deadlock detected'];

        $this->mock(ActionCenterTaskService::class, function ($mock) use ($previous): void {
            $mock->shouldReceive('apply')->once()->andThrow(new QueryException(
                'pgsql',
                'update "action_center_tasks" set "state" = ? where "item_key" = ?',
                ['acknowledged', 'alert:x'],
                $previous,
            ));
        });

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id], 'action' => 'claim',
            ]);

        $response->assertStatus(500);
        foreach (['SQLSTATE', '40P01', 'action_center_tasks', 'deadlock'] as $fragment) {
            $this->assertStringNotContainsString($fragment, (string) $response->getContent());
        }
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
