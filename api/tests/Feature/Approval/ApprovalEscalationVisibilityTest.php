<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalEscalationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalEscalationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_skips_role_user_without_view_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('approvals.reminder_hours', 1, 'approval');
        Cache::forget('settings:approvals.reminder_hours');

        $roleId = Role::query()->where('slug', 'department_head')->value('id');
        $hidden = User::factory()->create(['role_id' => $roleId]);
        $visible = User::factory()->create(['role_id' => $roleId]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $hidden->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $visible->id,
            'reason' => 'Approval reminder visibility regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hidden->flushPermissionsCache();

        $rma = ReturnRequest::query()->create([
            'rma_number' => 'RMA-ESC-'.substr(uniqid(), -8),
            'type' => 'customer_return',
            'status' => 'pending_approval',
        ]);
        ApprovalRecord::query()->create([
            'approvable_type' => ReturnRequest::class,
            'approvable_id' => $rma->id,
            'step_order' => 1,
            'role_slug' => 'department_head',
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now()->subHours(3),
        ]);

        $this->assertSame(1, app(ApprovalEscalationService::class)->runReminders());
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $visible->id,
            'type' => 'approval_reminder',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $hidden->id,
            'type' => 'approval_reminder',
        ]);
    }
}
