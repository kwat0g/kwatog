<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileUpdateActionBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_profile_action_badge_matches_hr_finance_and_combined_filtered_queues(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $hr = User::factory()->withRole('hr_officer')->create([
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $finance = User::factory()->withRole('finance_officer')->create();
        $combined = User::factory()->withRole('hr_officer')->create([
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $scopedRole = Role::create(['name' => 'Scoped HR Reviewer', 'slug' => 'test_scoped_hr_reviewer']);
        $scopedRole->permissions()->sync(Permission::query()
            ->whereIn('slug', ['hr.employees.view', 'hr.employees.edit'])
            ->pluck('id'));
        $scopedHr = User::factory()->create([
            'role_id' => $scopedRole->id,
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $viewOnly = User::factory()->withRole('department_head')->create([
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $requester = User::factory()->create();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $combined->id,
            'permission_id' => Permission::query()->where('slug', 'hr.profile_updates.finance_review')->value('id'),
            'type' => 'grant',
            'granted_by' => $finance->id,
            'reason' => 'Combined queue parity test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $localEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $remoteEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $this->request($localEmployee, $requester, 'pending');
        $this->request($remoteEmployee, $requester, 'pending');
        $this->request($localEmployee, $hr, 'pending');
        $this->request($localEmployee, $combined, 'pending');
        $this->request($localEmployee, $scopedHr, 'pending');
        $this->request($remoteEmployee, $requester, 'pending_finance', true, $hr);
        $this->request($remoteEmployee, $finance, 'pending_finance', true, $hr);
        $this->request($remoteEmployee, $requester, 'pending_finance', true, $finance);
        $this->request($remoteEmployee, $requester, 'pending_finance', true);
        $this->request($remoteEmployee, $requester, 'approved', true, $hr);

        $this->assertSame(4, $this->assertBadgeMatchesQueue($hr));
        $this->assertSame(1, $this->assertBadgeMatchesQueue($finance));
        $this->assertSame(7, $this->assertBadgeMatchesQueue($combined));
        $this->assertSame(3, $this->assertBadgeMatchesQueue($scopedHr));
        $this->assertSame(0, $this->assertBadgeMatchesQueue($viewOnly));
    }

    private function assertBadgeMatchesQueue(User $user): int
    {
        $badge = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data.profile_requests.count');
        $queue = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/hr/profile-update-requests?signal=awaiting_my_action')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame($queue, $badge);

        return $queue;
    }

    private function request(
        Employee $employee,
        ?User $requester,
        string $status,
        bool $requiresFinance = false,
        ?User $hrReviewer = null,
    ): ProfileUpdateRequest {
        return ProfileUpdateRequest::create([
            'employee_id' => $employee->id,
            'requested_by' => $requester?->id,
            'status' => $status,
            'requires_finance' => $requiresFinance,
            'changes' => ['bank_name' => 'Test bank'],
            'reviewed_by' => $hrReviewer?->id,
            'reviewed_at' => $hrReviewer ? now() : null,
        ]);
    }
}
