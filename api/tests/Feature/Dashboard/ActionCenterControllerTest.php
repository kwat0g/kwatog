<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\Alert;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Services\ActionCenterTaskService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class ActionCenterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/dashboards/action-center')->assertUnauthorized();
    }

    /**
     * The SPA PermissionGuard on /action-center is UX only — the routes must
     * enforce dashboard.action_center.view themselves so a revoked permission
     * actually closes the API (backend enforces independently).
     */
    public function test_route_requires_action_center_permission(): void
    {
        $user = $this->userWithPermissions(['alerts.view']); // no dashboard.action_center.view

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/action-center')
            ->assertForbidden();

        $alert = $this->createAlert();
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id], 'action' => 'claim',
            ])
            ->assertForbidden();
    }

    public function test_user_without_source_permissions_gets_an_empty_queue(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_only_permitted_sources_are_returned_with_summary_metadata(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'alerts.view']);

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

    /**
     * Approvals left the Action Center for the Approval Queue (2026-09):
     * approval keys were gated only on the cross-cutting approvals.board.view
     * that EVERY role holds, so any user could snooze/resolve a pending
     * approval out of every approver's queue. The prefix must now be unknown.
     */
    public function test_approval_keys_are_no_longer_actionable_by_any_role(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'approvals.board.view']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['approval:payroll:some-hash'], 'action' => 'resolve',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown action-center item.');

        $this->assertDatabaseCount('action_center_tasks', 0);
    }

    public function test_exception_can_be_claimed_and_resolved_with_an_audit_event(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'alerts.view', 'alerts.dismiss']);
        $alert = $this->createAlert();
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
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/action-center')
            ->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    /**
     * AC-2 — a hide only outlives the record it hid. Resolving an NCR in the
     * queue while the NCR stays open must not bury it forever: once the
     * record changes (work continues), the item re-enters the queue as open.
     */
    public function test_a_resolved_item_reenters_the_queue_when_its_record_changes(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'quality.ncr.view']);
        $ncr = NonConformanceReport::factory()->create();
        $itemKey = 'quality:ncr:'.$ncr->hash_id;

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/dashboards/action-center/tasks', [
            'item_ids' => [$itemKey], 'action' => 'resolve',
        ])->assertOk();

        // Record untouched: stays hidden for everyone.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/action-center')
            ->assertOk()->assertJsonPath('data.summary.total', 0);

        // The triage decision predates the record change, so the hide expires.
        DB::table('action_center_tasks')->update(['updated_at' => now()->subMinute()]);
        $ncr->touch();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.id', $itemKey)
            ->assertJsonPath('data.items.0.task_state', 'open')
            ->assertJsonPath('data.items.0.updated_by', null);
    }

    /** AC-2 — the same expiry applies to snoozes: a changed record wakes the item up early. */
    public function test_a_snoozed_item_reenters_the_queue_when_its_record_changes(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'quality.ncr.view']);
        $ncr = NonConformanceReport::factory()->create();
        $itemKey = 'quality:ncr:'.$ncr->hash_id;

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/dashboards/action-center/tasks', [
            'item_ids' => [$itemKey], 'action' => 'snooze',
            'snoozed_until' => now()->addHours(4)->toIso8601String(),
        ])->assertOk();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/action-center')
            ->assertOk()->assertJsonPath('data.summary.total', 0);

        DB::table('action_center_tasks')->update(['updated_at' => now()->subMinute()]);
        $ncr->touch();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.task_state', 'open')
            ->assertJsonPath('data.items.0.snoozed_until', null);
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
        $user = $this->userWithPermissions(['dashboard.action_center.view']); // no alerts.view
        $alert = $this->createAlert();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id], 'action' => 'claim',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to this action-center item.');

        $this->assertDatabaseCount('action_center_tasks', 0);
    }

    /**
     * Task gates mirror the owning module's READ routes exactly. A role that
     * holds only quality.ncr.view can never touch an inspection item (and vice
     * versa), and the broad quality.view alone grants NOTHING here — it does
     * not open the module's list endpoints either.
     */
    public function test_quality_task_permissions_are_source_specific(): void
    {
        $inspection = $this->createInspection();
        $ncr = NonConformanceReport::factory()->create();

        $ncrOnly = $this->userWithPermissions(['dashboard.action_center.view', 'quality.ncr.view']);
        $this->actingAs($ncrOnly, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:inspection:'.$inspection->hash_id], 'action' => 'claim',
            ])
            ->assertForbidden();

        $inspectionOnly = $this->userWithPermissions(['dashboard.action_center.view', 'quality.inspections.view']);
        $this->actingAs($inspectionOnly, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:ncr:'.$ncr->hash_id], 'action' => 'claim',
            ])
            ->assertForbidden();

        $broadOnly = $this->userWithPermissions(['dashboard.action_center.view', 'quality.view']);
        $this->actingAs($broadOnly, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:inspection:'.$inspection->hash_id], 'action' => 'claim',
            ])
            ->assertForbidden();

        $both = $this->userWithPermissions([
            'dashboard.action_center.view', 'quality.inspections.view', 'quality.ncr.view',
        ]);
        $this->actingAs($both, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:inspection:'.$inspection->hash_id, 'quality:ncr:'.$ncr->hash_id], 'action' => 'claim',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Keys must resolve to a record CURRENTLY in the queue. Before this check,
     * any holder of a source's view permission could write task rows for
     * fabricated keys — or for records the queue no longer shows.
     */
    public function test_items_absent_from_the_queue_are_refused(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'quality.inspections.view']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:inspection:not-a-real-hash'], 'action' => 'claim',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is no longer in the action queue.');

        $finished = $this->createInspection(['status' => 'passed']);
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:inspection:'.$finished->hash_id], 'action' => 'claim',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is no longer in the action queue.');

        $this->assertDatabaseCount('action_center_tasks', 0);
    }

    /**
     * Warehouse staff stage outbound loads with only the narrow deliveries
     * slug — deliberately NOT supply_chain.view, which would also open
     * shipments, fleet and customs docs. The queue must match the module.
     */
    public function test_deliveries_are_visible_with_the_narrow_deliveries_permission(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'supply_chain.deliveries.view']);

        Delivery::query()->create([
            'delivery_number' => 'DLV-202609-0001',
            'sales_order_id' => SalesOrder::factory()->create()->id,
            'status' => 'in_transit',
            'scheduled_date' => today()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/action-center')
            ->assertOk()
            ->assertJsonPath('data.summary.by_category.supply_chain', 1)
            ->assertJsonPath('data.items.0.category', 'supply_chain');
    }

    /**
     * A snooze hides the item for EVERY role (shared queue), so it is bounded
     * to 30 days — an unbounded snooze was an indefinite global hide.
     */
    public function test_snooze_beyond_30_days_is_refused(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'alerts.view']);
        $alert = $this->createAlert();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id],
                'action' => 'snooze',
                'snoozed_until' => now()->addDays(31)->toIso8601String(),
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['alert:'.$alert->hash_id],
                'action' => 'snooze',
                'snoozed_until' => now()->addHours(4)->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.0.state', 'snoozed');
    }

    public function test_unknown_quality_item_kind_is_rejected_as_malformed(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'quality.inspections.view']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/dashboards/action-center/tasks', [
                'item_ids' => ['quality:certificate:unknown'], 'action' => 'claim',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown action-center item.');
    }

    /**
     * An unrecognised key prefix stays a 422 — it is the same class of failure as
     * 'Unsupported action-center task action.', which was already a
     * BusinessRuleException a few lines above it.
     */
    public function test_an_unknown_item_key_is_a_422_with_its_message(): void
    {
        $user = $this->userWithPermissions(['dashboard.action_center.view', 'alerts.view']);

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

        $user = $this->userWithPermissions(['dashboard.action_center.view', 'alerts.view']);
        $alert = $this->createAlert();

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

    private function createAlert(): Alert
    {
        return Alert::query()->create([
            'type' => 'stock_low', 'severity' => 'warning', 'title' => 'Low stock',
            'message' => 'Replenishment required.', 'is_read' => false, 'is_dismissed' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createInspection(array $overrides = []): Inspection
    {
        return Inspection::create(array_merge([
            'inspection_number' => 'QC-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'stage' => 'incoming',
            'status' => 'in_progress',
            'batch_quantity' => 100,
            'sample_size' => 10,
        ], $overrides));
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
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => 'test'],
            );
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
