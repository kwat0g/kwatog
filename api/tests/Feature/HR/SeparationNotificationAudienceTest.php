<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeparationNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('hr.separation.notification_roles', ['finance_officer']);
    }

    public function test_notice_links_to_clearance_and_excludes_users_without_effective_view_permission(): void
    {
        $financeRole = Role::query()->where('slug', 'finance_officer')->firstOrFail();
        $allowed = User::factory()->create(['role_id' => $financeRole->id, 'is_active' => true]);
        $revoked = User::factory()->create(['role_id' => $financeRole->id, 'is_active' => true]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => Permission::query()->where('slug', 'hr.separation.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $allowed->id,
            'reason' => 'Separation visibility removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clearance = Clearance::factory()->create();

        app(NotifyOnSeparationInitiated::class)->handle(new SeparationInitiated($clearance));

        $notice = $allowed->notifications()->where('type', 'chain.separation_initiated')->firstOrFail();
        $this->assertSame("/hr/separations/{$clearance->hash_id}", $notice->data['link_to']);
        $this->assertSame(0, $revoked->notifications()->where('type', 'chain.separation_initiated')->count());
        $this->actingAs($allowed)->getJson('/api/v1/hr/clearances/'.$clearance->hash_id)->assertOk();
        $this->actingAs($revoked)->getJson('/api/v1/hr/clearances/'.$clearance->hash_id)->assertForbidden();
    }

    public function test_notice_only_reaches_non_actor_recipients_who_can_open_the_clearance(): void
    {
        app(SettingsService::class)->set('hr.separation.notification_roles', ['department_head']);
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $actor = $this->departmentHead($department);
        $allowed = $this->departmentHead($department);
        $outOfScope = $this->departmentHead($otherDepartment);
        $clearance = Clearance::factory()->create([
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
            'initiated_by' => $actor->id,
        ]);

        app(NotifyOnSeparationInitiated::class)->handle(new SeparationInitiated($clearance));

        $this->assertSame(1, $allowed->notifications()->where('type', 'chain.separation_initiated')->count());
        $this->assertSame(0, $actor->notifications()->where('type', 'chain.separation_initiated')->count());
        $this->assertSame(0, $outOfScope->notifications()->where('type', 'chain.separation_initiated')->count());
        $this->actingAs($allowed)->getJson('/api/v1/hr/clearances/'.$clearance->hash_id)->assertOk();
        $this->actingAs($outOfScope)->getJson('/api/v1/hr/clearances/'.$clearance->hash_id)->assertNotFound();
    }

    private function departmentHead(Department $department): User
    {
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
    }
}
