<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\ProfileUpdateRequestChanged;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileUpdateRequestRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.hr', true, 'modules');
    }

    public function test_submission_stages_a_scoped_content_free_after_commit_signal(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $requester = User::factory()->create(['employee_id' => $employee->id]);
        $this->actingAs($requester);

        $request = app(ProfileUpdateRequestService::class)->submit(
            $employee,
            $requester,
            ['mobile_number' => '+639171234567'],
            'Please update my mobile number',
        );

        $message = DB::table('event_outbox')->where('event_type', ProfileUpdateRequestChanged::class)->sole();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(ProfileUpdateRequestChanged::class, $event);
        self::assertSame($request->hash_id, $event->requestHashId);
        self::assertSame($employee->hash_id, $event->employeeHashId);
        self::assertSame($department->hash_id, $event->departmentHashId);
        self::assertSame($requester->hash_id, $event->requesterHashId);
        self::assertSame('created', $event->action);
        self::assertSame([
            'private-profile-update-request.'.$request->hash_id,
            'private-entity.profile_update_request.hr.all',
            'private-entity.profile_update_request.hr.department.'.$department->hash_id,
            'private-profile-update-request.user.'.$requester->hash_id,
        ], array_map(static fn ($channel) => $channel->name, $event->broadcastOn()));

        $payload = $event->broadcastWith();
        self::assertSame('profile_update_request', $payload['entity_type']);
        self::assertSame($request->hash_id, $payload['entity_id']);
        self::assertSame([['entity_type' => 'employee', 'hash_id' => $employee->hash_id]], $payload['related']);
        self::assertArrayNotHasKey('changes', $payload);
        self::assertArrayNotHasKey('note', $payload);
        self::assertStringNotContainsString('+639171234567', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Please update my mobile number', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_hr_department_finance_and_owner_channels_enforce_their_separate_scopes(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $sameDepartmentEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $otherDepartmentEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $departmentViewer = $this->userWithPermissions('hr.employees.view');
        $departmentViewer->update(['employee_id' => $sameDepartmentEmployee->id]);
        $otherDepartmentViewer = $this->userWithPermissions('hr.employees.view');
        $otherDepartmentViewer->update(['employee_id' => $otherDepartmentEmployee->id]);
        $allViewer = $this->userWithPermissions('hr.employees.view', 'hr.employees.view_sensitive');
        $financeViewer = $this->userWithPermissions('hr.profile_updates.finance_review');
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $otherOwner = User::factory()->create(['employee_id' => $sameDepartmentEmployee->id]);
        $profileRequest = ProfileUpdateRequest::withoutEvents(fn () => ProfileUpdateRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
            'status' => 'pending',
            'requires_finance' => false,
            'changes' => ['mobile_number' => '+639171234567'],
        ]));
        $bankRequest = ProfileUpdateRequest::withoutEvents(fn () => ProfileUpdateRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
            'status' => 'pending_finance',
            'requires_finance' => true,
            'changes' => ['bank_account_no' => '1234567890'],
        ]));
        $departmentChannel = 'entity.profile_update_request.hr.department.'.$department->hash_id;

        $this->authorizeChannel($departmentViewer, $departmentChannel)->assertOk();
        $this->authorizeChannel($otherDepartmentViewer, $departmentChannel)->assertForbidden();
        $this->authorizeChannel($allViewer, 'entity.profile_update_request.hr.all')->assertOk();
        $this->authorizeChannel($financeViewer, 'entity.profile_update_request.finance')->assertOk();
        $this->authorizeChannel($owner, 'profile-update-request.user.'.$owner->hash_id)->assertOk();
        $this->authorizeChannel($otherOwner, 'profile-update-request.user.'.$owner->hash_id)->assertForbidden();
        $this->authorizeChannel($departmentViewer, 'profile-update-request.'.$profileRequest->hash_id)->assertOk();
        $this->authorizeChannel($otherDepartmentViewer, 'profile-update-request.'.$profileRequest->hash_id)->assertForbidden();
        $this->authorizeChannel($financeViewer, 'profile-update-request.'.$profileRequest->hash_id)->assertForbidden();
        $this->authorizeChannel($financeViewer, 'profile-update-request.'.$bankRequest->hash_id)->assertOk();
    }

    public function test_finance_channel_is_not_targeted_before_the_bank_request_reaches_finance(): void
    {
        $beforeHrApproval = new ProfileUpdateRequestChanged(
            requestHashId: 'request-hash',
            employeeHashId: 'employee-hash',
            departmentHashId: 'department-hash',
            requesterHashId: 'requester-hash',
            requiresFinance: true,
            status: 'pending',
            action: 'created',
            version: '2026-11-01T00:00:00.000000Z',
        );
        $awaitingFinance = new ProfileUpdateRequestChanged(
            requestHashId: 'request-hash',
            employeeHashId: 'employee-hash',
            departmentHashId: 'department-hash',
            requesterHashId: 'requester-hash',
            requiresFinance: true,
            status: 'pending_finance',
            action: 'status_changed',
            version: '2026-11-01T00:00:01.000000Z',
        );

        $channels = static fn (ProfileUpdateRequestChanged $event): array => array_map(
            static fn ($channel) => $channel->name,
            $event->broadcastOn(),
        );
        self::assertNotContains('private-entity.profile_update_request.finance', $channels($beforeHrApproval));
        self::assertContains('private-entity.profile_update_request.finance', $channels($awaitingFinance));
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $suffix = substr(uniqid(), -6);
        $role = Role::query()->create([
            'name' => 'Profile request refresh '.$suffix,
            'slug' => 'profile_request_refresh_'.$suffix,
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
