<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * OGAMI audit DEFECT-1 — overtime self-approval SoD guard regression.
 *
 * The user<->employee link is one-directional (users.employee_id -> employees.id),
 * exposed via Employee::user(); there is no employees.user_id column. The guard
 * in OvertimeService::approve() must resolve the submitter through that
 * relationship (`$ot->employee?->user?->id`), NOT a non-existent `user_id`
 * attribute — otherwise the self-approval check is dead code that never fires.
 */
class OvertimeSelfApprovalSodTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);
    }

    private function makeOt(Employee $emp): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id'     => $emp->id,
            'date'            => now()->subDay()->toDateString(),
            'hours_requested' => 2,
            'reason'          => 'SoD regression',
            'status'          => OvertimeStatus::Pending->value,
        ]);
    }

    public function test_employee_cannot_approve_their_own_overtime(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        // The submitter: a User linked to that employee via users.employee_id.
        $self = User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'department_head')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You cannot approve your own overtime request.');

        app(OvertimeService::class)->approve($ot, $self);
    }

    public function test_a_different_user_can_approve_the_overtime(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'employee')->value('id'),
        ]);

        $approver = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        $approved = app(OvertimeService::class)->approve($ot, $approver);

        $this->assertSame(OvertimeStatus::Approved, $approved->fresh()->status);
    }

    public function test_self_approval_blocked_over_http_returns_422_not_500(): void
    {
        $this->seedRoles();

        $emp  = Employee::factory()->create();
        $self = User::factory()->create([
            'employee_id' => $emp->id,
            'role_id'     => Role::where('slug', 'department_head')->value('id'),
        ]);

        $ot = $this->makeOt($emp);

        // Controller must surface the SoD RuntimeException as 422, not a raw 500.
        $this->actingAs($self)
            ->patchJson("/api/v1/attendance/overtime-requests/{$ot->hash_id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'You cannot approve your own overtime request.']);

        $this->assertSame(OvertimeStatus::Pending, $ot->fresh()->status);
    }
}
