<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mission Phase 2 — end-to-end HIRE-TO-RETIRE chain over the real HTTP API,
 * every step performed by its real seeded role (never system_admin).
 *
 * Chain: hr_officer hires an employee over HTTP → department head approves the
 * employee's leave (self-service filing by the employee's own linked user) →
 * hr_officer approves at the HR step (balance consumed, attendance marked) →
 * hr_officer initiates separation (the hr_separation permission module lives
 * on the hr_officer role) → checklist items signed — the department head
 * through the per-department gate, HR as the fallback signer — clearance
 * completed.
 *
 * Adversarial probes ride along: wrong actor (a rank-and-file employee
 * creating employees or filing for someone else), wrong time (HR approval
 * before the department step, double department approval, second separation
 * while one is open, separation date before the hire date), and integrity
 * (leave balance actually deducted, approval state machine transitions).
 */
class HireToRetireChainTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $deptHead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(WorkflowSeeder::class); // leave_request chain: dept_head → hr
        Notification::fake();

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->hr = $make('hr_officer');
        $this->deptHead = $make('department_head');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @template T @param class-string<T> $class @return T */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    private function department(): Department
    {
        return Department::create([
            'name' => 'H2R Prod '.substr(uniqid(), -5),
            'code' => 'H2R-'.substr(uniqid(), -5),
        ]);
    }

    private function position(int $departmentId): Position
    {
        return Position::create([
            'title' => 'Molding Operator '.substr(uniqid(), -5),
            'department_id' => $departmentId,
            'salary_grade' => 'SG-1',
        ]);
    }

    /**
     * Full payload for POST /hr/employees. department_id / position_id are
     * sent as HashIDs — the FormRequest decodes them itself.
     */
    private function employeePayload(Department $dept, Position $pos, array $overrides = []): array
    {
        $uniq = substr(uniqid(), -8);

        return array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '1995-05-10',
            'gender' => 'female',
            'civil_status' => 'single',
            'street_address' => '12 Rizal St',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'mobile_number' => '09171234567',
            'email' => "h2r.{$uniq}@ogami.test",
            'emergency_contact_name' => 'Juan Santos',
            'emergency_contact_phone' => '09181234567',
            'department_id' => $dept->hash_id,
            'position_id' => $pos->hash_id,
            'employment_type' => 'regular',
            'pay_type' => 'monthly',
            'basic_monthly_salary' => '22000.00',
            'date_hired' => now()->subMonths(2)->toDateString(),
        ], $overrides);
    }

    /**
     * The hire flow initializes this year's leave balances synchronously
     * (EmployeeService::create → LeaveBalanceService::seedProratedFor),
     * prorated against the hire date. Fetch the vacation-leave row the chain
     * created — a missing row is a chain defect, not something to paper over
     * by inserting one here.
     */
    private function vacationBalance(Employee $employee): EmployeeLeaveBalance
    {
        $leaveTypeId = (int) \Illuminate\Support\Facades\DB::table('leave_types')
            ->where('code', 'VL')
            ->value('id');

        /** @var EmployeeLeaveBalance $balance */
        $balance = EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', (int) now()->year)
            ->firstOrFail();

        return $balance;
    }

    /** API payloads carry the HashID; the FormRequest decodes it. */
    private function leaveTypeHash(): string
    {
        $id = (int) \Illuminate\Support\Facades\DB::table('leave_types')
            ->where('code', 'VL')
            ->value('id');
        $this->assertNotSame(0, $id, 'VL leave type must be seeded');

        return app('hashids')->encode($id);
    }

    // ------------------------------------------------------------------
    // The chain
    // ------------------------------------------------------------------

    public function test_hire_to_retire_chain_end_to_end(): void
    {
        // ------------------------------------------------------------------
        // ACT 1 — HIRE: hr_officer creates the employee over the real API.
        // ------------------------------------------------------------------
        $dept = $this->department();
        $pos = $this->position($dept->id);

        $response = $this->actingAs($this->hr)
            ->postJson('/api/v1/hr/employees', $this->employeePayload($dept, $pos));

        $response->assertStatus(201);
        $response->assertJsonPath('data.first_name', 'Maria');

        /** @var Employee $employee */
        $employee = $this->fromApiId(Employee::class, $response->json('data.id'));
        $this->assertSame(EmployeeStatus::Active, $employee->status);
        $this->assertMatchesRegularExpression('/^OGM-\d{4}-\d{4}$/', (string) $employee->employee_no);

        // ------------------------------------------------------------------
        // Wrong actor: a rank-and-file employee user cannot create employees
        // (permission middleware refuses, hr.employees.create not held).
        // ------------------------------------------------------------------
        $employeeUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
        $this->actingAs($employeeUser)
            ->postJson('/api/v1/hr/employees', $this->employeePayload($dept, $pos))
            ->assertStatus(403);

        // ------------------------------------------------------------------
        // ACT 2 — the hire auto-provisioned the employee's own login
        // (AutoProvisionUserOnEmployeeHire, employee role) and mailed a
        // temporary password. The force-change gate must refuse API work
        // until the temp password is changed.
        // ------------------------------------------------------------------
        $selfUser = $employee->user()->firstOrFail();
        $this->assertSame('employee', $selfUser->role?->slug);
        $this->assertTrue((bool) $selfUser->must_change_password);

        // The temp password rode the queued welcome notification.
        $welcome = Notification::sent($selfUser, EmployeeWelcomeNotification::class)->first();
        $this->assertNotNull($welcome, 'Welcome notification with temp password was never sent');
        $tempPassword = (fn () => $this->tempPassword)->call($welcome);

        // Force-change gate: any business API call is refused until changed.
        $this->actingAs($selfUser)
            ->getJson('/api/v1/auth/user')
            ->assertStatus(200); // bootstrap route is exempt
        $this->actingAs($selfUser)
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveTypeHash(),
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_expired');

        // First-login password change over the real endpoint.
        $this->actingAs($selfUser)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => $tempPassword,
                'new_password' => 'Ch4nge!Now',
                'new_password_confirmation' => 'Ch4nge!Now',
            ])
            ->assertStatus(200);
        $this->assertFalse((bool) $selfUser->refresh()->must_change_password);

        // The hire must have initialized the current year's VL balance.
        $balance = $this->vacationBalance($employee);
        $creditsBefore = (float) $balance->remaining;
        // Deterministic window: next Monday + Tuesday = exactly 2 business
        // days (holidays table is empty in tests), so balance math below is
        // exact regardless of which weekday "today" is.
        $monday = \Carbon\CarbonImmutable::parse('next monday');
        $start = $monday->toDateString();
        $end = $monday->addDays(1)->toDateString();

        // Wrong actor first: a self-service user filing for somebody else.
        $otherEmployee = Employee::factory()->create(); // a decoy the filer must NOT reach
        $this->actingAs($selfUser)
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $otherEmployee->hash_id,
                'leave_type_id' => $this->leaveTypeHash(),
                'start_date' => $start,
                'end_date' => $end,
                'reason' => 'Not mine.',
            ])
            ->assertStatus(403);

        $leaveResponse = $this->actingAs($selfUser)
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveTypeHash(),
                'start_date' => $start,
                'end_date' => $end,
                'reason' => 'Family event in the province.',
            ]);

        $leaveResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_dept');

        /** @var LeaveRequest $leave */
        $leave = $this->fromApiId(LeaveRequest::class, $leaveResponse->json('data.id'));

        // NOW the scope probe has a real pending request to aim at.
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/leaves/requests/{$leave->hash_id}/approve-dept", [
                'remarks' => 'Not my department.',
            ])
            ->assertStatus(403); // ForbiddenActionException: wrong department

        $this->assertSame($employee->id, $leave->employee_id);
        $this->assertStringStartsWith('LR-', (string) $leave->leave_request_no);

        // Balance NOT yet consumed at submission.
        $this->assertDatabaseHas('employee_leave_balances', [
            'id' => $balance->id,
            'used' => 0,
        ]);

        // ------------------------------------------------------------------
        // Wrong time: HR approval cannot happen before the department step.
        // ------------------------------------------------------------------
        $this->actingAs($this->hr)
            ->patchJson("/api/v1/leaves/requests/{$leave->hash_id}/approve-hr", [
                'remarks' => 'Skipping the department step.',
            ])
            ->assertStatus(422);

        // ACT 3 — department head approves. For the scope check to pass the
        // approver needs an Employee row in the SAME department as the filer.
        $headEmployee = Employee::factory()->create(['department_id' => $dept->id]);
        $this->deptHead->forceFill(['employee_id' => $headEmployee->id])->save();

        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/leaves/requests/{$leave->hash_id}/approve-dept", [
                'remarks' => 'Noted by department.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_hr');

        // Wrong time again: the department step cannot run twice.
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/leaves/requests/{$leave->hash_id}/approve-dept", [
                'remarks' => 'Second bite.',
            ])
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT 4 — hr_officer approves at the HR step: status Approved, the
        // balance is consumed, attendance days are marked on_leave.
        // ------------------------------------------------------------------
        $this->actingAs($this->hr)
            ->patchJson("/api/v1/leaves/requests/{$leave->hash_id}/approve-hr", [
                'remarks' => 'Approved by HR.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $leave->refresh();
        $this->assertSame('approved', $leave->status->value);
        $balance->refresh();
        $this->assertEquals(2.0, (float) $balance->used);
        $this->assertEquals($creditsBefore - 2.0, (float) $balance->remaining);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'status' => 'on_leave',
        ]);

        // ------------------------------------------------------------------
        // ACT 5 — SEPARATION: hr_officer initiates the exit over the API.
        // ------------------------------------------------------------------
        // ------------------------------------------------------------------
        // Wrong date first: separation before the hire date is refused.
        // ------------------------------------------------------------------
        $this->actingAs($this->hr)
            ->postJson("/api/v1/hr/employees/{$employee->hash_id}/separation", [
                'separation_date' => '2020-01-01',
                'separation_reason' => 'resigned',
                'remarks' => 'Mistyped year probe.',
            ])
            ->assertStatus(422);

        // The separation initiator needs the checklist configured. Item
        // department labels resolve against the departments table — the first
        // item belongs to the employee's own department so its head can sign
        // it through the per-department gate; the second resolves to nothing
        // and is therefore HR-fallback-only.
        app(SettingsService::class)->set('hr.separation.clearance_checklist', [
            ['department' => $dept->name, 'item_key' => 'exit_interview', 'label' => 'Exit interview'],
            ['department' => 'FIN', 'item_key' => 'accountability', 'label' => 'Accountability clearance'],
        ], 'hr');

        $separationDate = now()->addWeek()->toDateString();
        $response = $this->actingAs($this->hr)
            ->postJson("/api/v1/hr/employees/{$employee->hash_id}/separation", [
                'separation_date' => $separationDate,
                'separation_reason' => 'resigned',
                'remarks' => 'Relocating abroad.',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'in_progress');

        /** @var Clearance $clearance */
        $clearance = $this->fromApiId(Clearance::class, $response->json('data.id'));
        $this->assertSame($employee->id, $clearance->employee_id);
        $this->assertSame('resigned', $clearance->separation_reason->value);
        $this->assertCount(2, $clearance->clearance_items);

        // Employee is no longer Active once separation is initiated.
        $employee->refresh();
        $this->assertSame(EmployeeStatus::OnLeave, $employee->status);

        // Wrong time: a second open clearance for the same employee is refused.
        $this->actingAs($this->hr)
            ->postJson("/api/v1/hr/employees/{$employee->hash_id}/separation", [
                'separation_date' => $separationDate,
                'separation_reason' => 'terminated',
            ])
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT 6 — CLEARANCE: the owning department's member signs their
        // checklist item through the per-department gate (HR-03), HR signs
        // the rest as the fallback signer → clearance completed.
        // ------------------------------------------------------------------
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", [
                'item_key' => 'exit_interview',
                'remarks' => 'Conducted.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $clearance->refresh();
        $signed = collect($clearance->clearance_items)->firstWhere('item_key', 'exit_interview');
        $this->assertSame('cleared', $signed['status']);
        $this->assertNotNull($signed['signed_at']);

        // The department head may NOT sign the FIN item: its label resolves
        // to no department of theirs, so the per-department gate refuses
        // (BusinessRuleException → 422, addressed to the actor's scope).
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", [
                'item_key' => 'accountability',
            ])
            ->assertStatus(422);

        $this->actingAs($this->hr)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", [
                'item_key' => 'accountability',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $clearance->refresh();
        $this->assertSame(ClearanceStatus::Completed, $clearance->status);
        $this->assertDatabaseHas('clearances', [
            'id' => $clearance->id,
            'status' => 'completed',
        ]);
    }

}
