<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalEscalationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalAutoResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('settings:approvals.auto_resolve.enabled');
        Cache::forget('settings:approvals.auto_resolve.default_hours');
        Cache::forget('settings:approvals.auto_resolve.default_action');

        // Make sure system_admin role + a user exists (auto-resolver attribution).
        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Administrator']);
        User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_auto_rejects_a_pending_record_past_sla(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', true, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        $rec = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subDays(5),
            'escalated_at'    => now()->subHours(80), // past 72h default
        ]);

        $count = app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame(1, $count);
        $rec->refresh();
        $this->assertSame('rejected', $rec->action);
        $this->assertNotNull($rec->auto_resolved_at);
        $this->assertNotNull($rec->approver_id);
    }

    public function test_auto_rejects_cascades_skip_to_later_steps(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', true, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        $r1 = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 99,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subDays(5),
            'escalated_at'    => now()->subHours(80),
        ]);
        $r2 = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 99,
            'step_order'      => 2,
            'role_slug'       => 'production_manager',
            'action'          => 'pending',
            'created_at'      => now()->subDays(5),
        ]);

        app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame('rejected', $r1->fresh()->action);
        $this->assertSame('skipped',  $r2->fresh()->action);
    }

    public function test_disabled_flag_short_circuits(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', false, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        $rec = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subDays(5),
            'escalated_at'    => now()->subHours(200),
        ]);

        $count = app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame(0, $count);
        $this->assertSame('pending', $rec->fresh()->action);
    }

    public function test_record_not_escalated_yet_is_left_alone(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', true, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        $rec = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subDays(5),
            // no escalated_at
        ]);

        $count = app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame(0, $count);
        $this->assertSame('pending', $rec->fresh()->action);
    }

    public function test_record_recently_escalated_below_sla_is_left_alone(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', true, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        $rec = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subDays(2),
            'escalated_at'    => now()->subHours(10), // below 72h default
        ]);

        $count = app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame(0, $count);
        $this->assertSame('pending', $rec->fresh()->action);
    }

    public function test_workflow_step_policy_overrides_global_default(): void
    {
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', true, 'approvals');
        Cache::forget('settings:approvals.auto_resolve.enabled');

        // Workflow with step that auto-APPROVES after only 1 hour.
        DB::table('workflow_definitions')->insert([
            'workflow_type' => 'test_wf',
            'name' => 'Test',
            'steps' => json_encode([
                ['order' => 1, 'role' => 'department_head',
                 'auto_resolve_after_hours' => 1,
                 'auto_resolve_action' => 'approve'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rec = ApprovalRecord::create([
            'approvable_type' => 'TestApprovable',
            'approvable_id'   => 1,
            'step_order'      => 1,
            'role_slug'       => 'department_head',
            'action'          => 'pending',
            'created_at'      => now()->subHours(5),
            'escalated_at'    => now()->subHours(3), // > 1h step SLA
        ]);

        app(ApprovalEscalationService::class)->runAutoResolve();

        $this->assertSame('approved', $rec->fresh()->action);
    }
}
