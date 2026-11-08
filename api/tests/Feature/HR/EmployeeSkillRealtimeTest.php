<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeSkillChanged;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Services\EmployeeSkillService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeSkillRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.hr', true, 'modules');
    }

    public function test_assignment_emits_a_coalesced_content_free_after_commit_refresh(): void
    {
        $employee = Employee::factory()->create();
        $skill = Skill::query()->create(['name' => 'Injection press setup', 'category' => 'Production']);
        $actor = $this->userWithPermissions('hr.employees.trainings.manage');
        $actor->update(['employee_id' => $employee->id]);
        $this->actingAs($actor);

        $record = app(EmployeeSkillService::class)->assign($employee, [
            'skill_id' => $skill->hash_id,
            'proficiency_level' => 'proficient',
            'acquired_date' => '2026-10-01',
            'notes' => 'Private qualification notes',
        ], actor: $actor);

        $message = DB::table('event_outbox')
            ->where('event_type', EmployeeSkillChanged::class)
            ->sole();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(EmployeeSkillChanged::class, $event);
        self::assertSame($employee->hash_id, $event->employeeHashId);
        self::assertSame('created', $event->action);
        self::assertSame($record->updated_at?->format('Y-m-d\\TH:i:s.uP'), $event->version);
        self::assertSame([
            'private-entity.employee_skill.visible',
            'private-employee.skills.'.$employee->hash_id,
        ], array_map(static fn ($channel) => $channel->name, $event->broadcastOn()));
        self::assertArrayNotHasKey('entity_id', $event->broadcastWith());
        self::assertStringNotContainsString('Private qualification notes', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));
    }

    public function test_revoke_and_restore_stage_refreshes_for_the_employee_collection(): void
    {
        $employee = Employee::factory()->create();
        $skill = Skill::query()->create(['name' => 'Mold change safety']);
        $actor = $this->userWithPermissions('hr.employees.trainings.manage');
        $actor->update(['employee_id' => $employee->id]);
        $record = EmployeeSkill::withoutEvents(fn () => EmployeeSkill::query()->create([
            'employee_id' => $employee->id,
            'skill_id' => $skill->id,
            'proficiency_level' => 'competent',
            'acquired_date' => '2026-10-01',
        ]));
        $this->actingAs($actor);

        app(EmployeeSkillService::class)->revoke($record, $actor);
        $revoked = DB::table('event_outbox')
            ->where('event_type', EmployeeSkillChanged::class)
            ->sole();
        self::assertSame('deleted', json_decode((string) $revoked->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->where('event_type', EmployeeSkillChanged::class)->delete();
        app(EmployeeSkillService::class)->restore(EmployeeSkill::withTrashed()->findOrFail($record->getKey()), $actor);
        $restored = DB::table('event_outbox')
            ->where('event_type', EmployeeSkillChanged::class)
            ->sole();
        self::assertSame('restored', json_decode((string) $restored->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }

    public function test_channels_match_employee_skill_read_scope_without_exposing_row_ids_to_list_viewers(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $sameDepartmentEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $otherDepartmentEmployee = Employee::factory()->create();
        $viewer = $this->userWithPermissions('hr.employees.trainings.view');
        $viewer->update(['employee_id' => $sameDepartmentEmployee->id]);
        $matrixViewer = $this->userWithPermissions('hr.trainings.view');
        $outsideViewer = $this->userWithPermissions('hr.employees.trainings.view');

        $this->authorizeChannel($viewer, 'employee.skills.'.$employee->hash_id)->assertOk();
        $this->authorizeChannel($viewer, 'employee.skills.'.$otherDepartmentEmployee->hash_id)->assertForbidden();
        $this->authorizeChannel($matrixViewer, 'entity.employee_skill.visible')->assertOk();
        $this->authorizeChannel($matrixViewer, 'employee.skills.'.$employee->hash_id)->assertForbidden();
        $this->authorizeChannel($outsideViewer, 'entity.employee_skill.visible')->assertOk();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $suffix = substr(uniqid(), -6);
        $role = Role::query()->create([
            'name' => 'Employee skill refresh '.$suffix,
            'slug' => 'employee_skill_refresh_'.$suffix,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-'.$channel,
        ]);
    }
}
