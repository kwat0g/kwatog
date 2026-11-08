<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClearanceRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_clearance_parent_updates_coalesce_to_one_after_commit_refresh(): void
    {
        $actor = User::factory()->create();
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $departmentViewer = $this->userWithPermissions('hr.separation.view');
        $departmentViewerEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $departmentViewer->update(['employee_id' => $departmentViewerEmployee->id]);
        $otherDepartment = Department::factory()->create();
        $outsider = $this->userWithPermissions('hr.separation.view');
        $outsiderEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $outsider->update(['employee_id' => $outsiderEmployee->id]);
        $globalViewer = $this->userWithPermissions('hr.separation.view', 'hr.separation.view_all');

        DB::transaction(function () use ($actor, $employee): void {
            $clearance = Clearance::query()->create([
                'clearance_no' => 'CLR-RT-'.uniqid(),
                'employee_id' => $employee->id,
                'separation_date' => now()->toDateString(),
                'separation_reason' => 'resigned',
                'clearance_items' => [],
                'status' => 'pending',
                'initiated_by' => $actor->id,
            ]);
            $clearance->update(['status' => 'in_progress']);
        });

        $messages = OutboxMessage::query()
            ->where('event_type', 'App\\Modules\\HR\\Events\\ClearanceChanged')
            ->get();

        $this->assertCount(1, $messages);
        $this->assertSame('pending', $messages->first()->status);
        $this->assertSame('updated', $messages->first()->payload['action']);
        $recipients = $messages->first()->payload['viewerUserHashIds'];
        $this->assertContains($departmentViewer->hash_id, $recipients);
        $this->assertContains($globalViewer->hash_id, $recipients);
        $this->assertNotContains($outsider->hash_id, $recipients);
    }

    public function test_clearance_resource_returns_server_computed_available_actions(): void
    {
        $actor = User::factory()->create();
        $viewer = $this->userWithPermissions(
            'hr.separation.view',
            'hr.separation.view_all',
            'hr.separation.finalize',
            'hr.separation.cancel',
        );
        $employee = Employee::factory()->create();
        $clearance = Clearance::query()->create([
            'clearance_no' => 'CLR-ACTIONS-'.uniqid(),
            'employee_id' => $employee->id,
            'separation_date' => now()->toDateString(),
            'separation_reason' => 'resigned',
            'clearance_items' => [],
            'status' => 'pending',
            'initiated_by' => $actor->id,
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['cancel'])
            ->assertJsonPath('data.permissions.view_final_pay', true);

        $clearance->update(['status' => 'completed']);
        $this->actingAs($viewer)
            ->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['compute_final_pay']);

        $clearance->update(['final_pay_computed' => true]);
        $this->actingAs($viewer)
            ->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['finalize']);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::create([
            'name' => 'Clearance realtime '.substr(uniqid(), -5),
            'slug' => 'clearance_rt_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }
}
