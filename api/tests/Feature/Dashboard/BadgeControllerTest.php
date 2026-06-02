<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Polish Task S2 — sidebar badge count system.
 *
 * Covers the unified /badges endpoint: auth gate, empty payload for low-trust
 * users, populated payload for approver roles, and severity threshold.
 */
class BadgeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/dashboards/badges')->assertStatus(401);
    }

    public function test_employee_role_gets_no_action_keys(): void
    {
        $employee = Role::where('slug', 'employee')->firstOrFail();
        $user = User::factory()->create(['role_id' => $employee->id]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        // Employee role lacks every approver permission, so the action-only
        // keys must be absent. The shared `approvals.board.view` permission
        // is granted to every seeded role, so `approvals` may be present
        // (with a count of 0).
        $this->assertArrayNotHasKey('purchase_requests', $resp);
        $this->assertArrayNotHasKey('leaves',            $resp);
        $this->assertArrayNotHasKey('overtime',          $resp);
        $this->assertArrayNotHasKey('maintenance_wo',    $resp);
        $this->assertArrayNotHasKey('low_stock',         $resp);
        $this->assertArrayNotHasKey('ncrs',              $resp);
        $this->assertArrayNotHasKey('profile_requests',  $resp);
    }

    public function test_department_head_sees_pending_approvals_with_severity(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        // 25 pending records routed to this role → severity must be 'danger'.
        for ($i = 0; $i < 25; $i++) {
            ApprovalRecord::create([
                'approvable_type' => 'X',
                'approvable_id'   => $i + 1,
                'step_order'      => 1,
                'role_slug'       => 'department_head',
                'action'          => 'pending',
                'created_at'      => now(),
            ]);
        }

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('approvals', $resp);
        $this->assertSame(25, $resp['approvals']['count']);
        $this->assertSame('danger', $resp['approvals']['severity']);
    }

    public function test_severity_warning_for_small_count(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        ApprovalRecord::create([
            'approvable_type' => 'X',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now(),
        ]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $resp['approvals']['count']);
        $this->assertSame('warning', $resp['approvals']['severity']);
    }

    public function test_zero_count_yields_neutral_severity(): void
    {
        $deptHead = Role::where('slug', 'department_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $deptHead->id]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $resp['approvals']['count']);
        $this->assertSame('neutral', $resp['approvals']['severity']);
    }

    public function test_severity_thresholds_come_from_config(): void
    {
        config()->set('badges.severity.danger', 2);
        config()->set('badges.severity.warning', 0);

        $svc = app(\App\Modules\Dashboard\Services\BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('severity');
        $m->setAccessible(true);

        $this->assertSame('danger', $m->invoke($svc, 3));   // > 2
        $this->assertSame('warning', $m->invoke($svc, 1));   // > 0, <= 2
        $this->assertSame('neutral', $m->invoke($svc, 0));
    }
}
