<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalNotificationRecipientResolver;
use App\Common\Services\ApprovalService;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ApprovalNoticeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_types_declare_their_record_view_permission(): void
    {
        foreach (ApprovalTypeRegistry::all() as $type => $meta) {
            $this->assertContains(
                $meta['view_permission'],
                $meta['permissions'],
                "{$type} must declare its record view permission in the approval registry.",
            );

            $actionPermissions = array_values(array_filter([
                $meta['approval_permission'] ?? null,
                ...array_values($meta['approval_permissions_by_role'] ?? []),
            ]));

            $this->assertNotEmpty($actionPermissions, "{$type} must declare its approval action permission.");
            foreach ($actionPermissions as $permission) {
                $this->assertContains(
                    $permission,
                    $meta['permissions'],
                    "{$type} must declare {$permission} in its approval registry permissions.",
                );
            }
        }
    }

    public function test_action_required_notice_skips_role_member_who_cannot_view_record(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $roleId = Role::query()->where('slug', 'department_head')->value('id');
        $eligible = User::factory()->create(['role_id' => $roleId]);
        $revoked = User::factory()->create(['role_id' => $roleId]);
        $inactive = User::factory()->create(['role_id' => $roleId, 'is_active' => false]);
        $unrelatedRole = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
        $delegate = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $inactiveDelegate = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => false,
        ]);
        $expiredDelegate = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $unactionableDelegate = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
        ]);
        ApprovalDelegation::create([
            'delegator_user_id' => $eligible->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        ApprovalDelegation::create([
            'delegator_user_id' => $eligible->id,
            'delegate_user_id' => $inactiveDelegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        ApprovalDelegation::create([
            'delegator_user_id' => $eligible->id,
            'delegate_user_id' => $expiredDelegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDays(2)->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        ApprovalDelegation::create([
            'delegator_user_id' => $eligible->id,
            'delegate_user_id' => $unactionableDelegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $eligible->id,
            'reason' => 'Approval notification visibility regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revoked->flushPermissionsCache();
        $this->assertTrue($revoked->hasPermission('return_management.approve'));

        $approvalRevoked = User::factory()->create(['role_id' => $roleId]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $approvalRevoked->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.approve')->value('id'),
            'type' => 'revoke',
            'granted_by' => $eligible->id,
            'reason' => 'Approval notification action-access regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approvalRevoked->flushPermissionsCache();
        $this->assertTrue($approvalRevoked->hasPermission('return_management.view'));
        $this->assertFalse($approvalRevoked->hasPermission('return_management.approve'));
        $this->assertTrue($unactionableDelegate->hasPermission('return_management.view'));
        $this->assertFalse($unactionableDelegate->hasPermission('return_management.approve'));

        $submitter = User::factory()->create(['role_id' => $roleId]);
        $rma = ReturnRequest::query()->create([
            'rma_number' => 'RMA-EMAIL-ACCESS-1',
            'type' => 'customer_return',
            'status' => 'draft',
            'created_by' => $submitter->id,
        ]);

        app(ApprovalService::class)->submit($rma, 'return_request');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $eligible->id,
            'type' => 'approval.action_required',
        ]);
        $directNotice = DB::table('notifications')->where('notifiable_id', $eligible->id)->where('type', 'approval.action_required')->first();
        $delegateNotice = DB::table('notifications')->where('notifiable_id', $delegate->id)->where('type', 'approval.action_required')->first();
        $this->assertSame('current_approval_step', json_decode((string) $directNotice->data, true)['recipient_reason']);
        $this->assertSame('active_approval_delegate', json_decode((string) $delegateNotice->data, true)['recipient_reason']);
        foreach ([$revoked, $approvalRevoked, $unactionableDelegate, $inactiveDelegate, $expiredDelegate, $inactive, $unrelatedRole, $submitter] as $excluded) {
            $this->assertDatabaseMissing('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $excluded->id,
                'type' => 'approval.action_required',
            ]);
        }
    }

    public function test_po_approver_permission_also_grants_detail_visibility_for_notifications(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $finance->id,
            'permission_id' => Permission::query()->where('slug', 'purchasing.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $finance->id,
            'reason' => 'Purchase order detail access follows the approve route permission.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $finance->flushPermissionsCache();

        $this->assertFalse($finance->hasPermission('purchasing.view'));
        $this->assertTrue($finance->hasPermission('purchasing.po.approve'));
        $po = PurchaseOrder::factory()->create();
        $step = ApprovalRecord::query()->create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->getKey(),
            'step_order' => 1,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now(),
        ]);

        $recipients = app(ApprovalNotificationRecipientResolver::class)->resolveStep($po, $step);

        $this->assertSame([$finance->id], collect($recipients)->flatten(1)->pluck('id')->all());
    }

    public function test_empty_approval_audience_is_logged_without_admin_fallback(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $approver->id,
            'reason' => 'Approval notification no-recipient test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver->flushPermissionsCache();
        $submitter = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $rma = ReturnRequest::query()->create([
            'rma_number' => 'RMA-NO-ELIGIBLE-1',
            'type' => 'customer_return',
            'status' => 'draft',
            'created_by' => $submitter->id,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Approval notification skipped: no eligible recipient.', Mockery::type('array'));

        app(ApprovalService::class)->submit($rma, 'return_request');

        $this->assertDatabaseMissing('notifications', [
            'type' => 'approval.action_required',
            'notifiable_id' => $approver->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'approval.action_required',
            'notifiable_id' => $submitter->id,
        ]);
    }
}
