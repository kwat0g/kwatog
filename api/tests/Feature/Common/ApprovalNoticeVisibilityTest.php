<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Services\ApprovalService;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        }
    }

    public function test_action_required_notice_skips_role_member_who_cannot_view_record(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $roleId = Role::query()->where('slug', 'department_head')->value('id');
        $eligible = User::factory()->create(['role_id' => $roleId]);
        $revoked = User::factory()->create(['role_id' => $roleId]);
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

        $submitter = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
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
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $revoked->id,
            'type' => 'approval.action_required',
        ]);
    }
}
