<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\ActivityEvent;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeOnboarding;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\OnboardingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * U4 — OnboardingService coverage.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function basePayload(): array
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos  = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return [
            'first_name'  => 'Juan', 'last_name' => 'Cruz',
            'birth_date'  => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
            'status' => 'active',
        ];
    }

    private function userForRole(string $roleSlug, ?int $employeeId = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'employee_id' => $employeeId,
            'is_active' => true,
        ]);
    }

    public function test_creating_employee_initializes_onboarding(): void
    {
        /** @var EmployeeService $svc */
        $svc = app(EmployeeService::class);
        $emp = $svc->create($this->basePayload());

        $onboarding = EmployeeOnboarding::query()->where('employee_id', $emp->id)->first();
        $this->assertNotNull($onboarding);
        $this->assertNotNull($onboarding->profile_completed_at);
        $this->assertNotNull($onboarding->leave_balances_initialized_at);
    }

    public function test_recompute_marks_account_provisioned_after_user_created(): void
    {
        /** @var EmployeeService $svc */
        $svc = app(EmployeeService::class);
        $emp = $svc->create($this->basePayload());

        // T1.3 — auto-provision listener fires on EmployeeCreated, so the
        // account_provisioned step should already be complete.
        /** @var OnboardingService $ob */
        $ob = app(OnboardingService::class);
        $status = $ob->status($emp->fresh());
        $accountStep = collect($status['steps'])->firstWhere('key', 'account_provisioned');
        $this->assertNotNull($accountStep['completed_at']);
    }

    public function test_complete_onboarding_sets_completed_at(): void
    {
        /** @var EmployeeService $emps */
        $emps = app(EmployeeService::class);
        $hr = $this->userForRole('hr_officer');
        $emp = $emps->create(array_merge($this->basePayload(), [
            'sss_no' => '12-3456789-0',
            'philhealth_no' => 'PH-1234',
            'pagibig_no' => 'PI-1234',
            'tin' => 'TIN-1234',
            'bank_name' => 'BPI',
            'bank_account_no' => '1234567890',
        ]));

        /** @var OnboardingService $ob */
        $ob = app(OnboardingService::class);
        $shiftId = DB::table('shifts')->value('id');
        if ($shiftId === null) {
            $shiftId = DB::table('shifts')->insertGetId([
                'name' => 'Onboarding Test Shift',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'is_night_shift' => false,
                'is_extended' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($shiftId !== null && ! DB::table('employee_shift_assignments')->where('employee_id', $emp->id)->exists()) {
            DB::table('employee_shift_assignments')->insert([
                'employee_id' => $emp->id,
                'shift_id' => $shiftId,
                'effective_date' => '2025-01-01',
                'created_at' => now(),
            ]);
        }
        $ob->recompute($emp->fresh());
        $ob->markDepartmentTeamNotified($emp->fresh(), $hr);
        // T1.3 — manual provisionForEmployee removed; auto-provision listener
        // already created the user during EmployeeService::create above.
        $ob->recompute($emp->fresh());

        $status = $ob->status($emp->fresh());
        $this->assertTrue($status['is_complete']);
        $this->assertNotNull($status['completed_at']);
        $this->assertDatabaseHas('activity_events', [
            'action' => 'onboarding_department_team_notified',
            'actor_user_id' => $hr->id,
            'subject_id' => $emp->id,
        ]);
    }

    public function test_reminder_inserts_catalogued_notification_and_is_idempotent(): void
    {
        /** @var EmployeeService $svc */
        $svc = app(EmployeeService::class);
        $hr = $this->userForRole('hr_officer');
        $emp = $svc->create($this->basePayload());

        // Fresh onboarding — no reminder yet.
        /** @var OnboardingService $ob */
        $ob = app(OnboardingService::class);
        $this->assertSame(0, $ob->sendRemindersForStaleOnboardings());

        // Backdate the row so it qualifies.
        EmployeeOnboarding::query()
            ->where('employee_id', $emp->id)
            ->update(['created_at' => now()->subDays(4)]);

        $count = $ob->sendRemindersForStaleOnboardings();
        $this->assertSame(1, $count);
        $this->assertNotNull(EmployeeOnboarding::query()->where('employee_id', $emp->id)->first()->reminder_sent_at);

        $notification = DB::table('notifications')
            ->where('type', 'hr.onboarding.stale')
            ->where('notifiable_id', $hr->id)
            ->first();
        $this->assertNotNull($notification);
        $this->assertSame("/hr/employees/{$emp->hash_id}", json_decode($notification->data, true)['link_to']);

        $this->assertSame(0, $ob->sendRemindersForStaleOnboardings());
        $this->assertSame(1, DB::table('notifications')->where('type', 'hr.onboarding.stale')->count());
    }

    public function test_status_reconciles_canonical_data_in_a_transaction(): void
    {
        $employee = Employee::factory()->create();
        $employee->forceFill([
            'sss_no' => '12-3456789-0',
            'philhealth_no' => 'PH-1234',
            'pagibig_no' => 'PI-1234',
            'tin' => 'TIN-1234',
        ])->save();

        /** @var OnboardingService $ob */
        $ob = app(OnboardingService::class);

        $status = $ob->status($employee);

        $this->assertFalse($status['is_complete']);
        $this->assertCount(7, $status['steps']);
        $this->assertNotNull(collect($status['steps'])->firstWhere('key', 'gov_ids_recorded')['completed_at']);
        $this->assertDatabaseHas('employee_onboardings', ['employee_id' => $employee->id]);
    }

    public function test_hr_can_attest_department_notification_and_repeated_call_is_idempotent(): void
    {
        $emp = app(EmployeeService::class)->create($this->basePayload());
        $hr = $this->userForRole('hr_officer');
        $admin = $this->userForRole('system_admin');

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/onboarding/department-team-notified")
            ->assertOk()
            ->assertJsonPath('data.steps.4.key', 'dept_team_notified');

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/onboarding/department-team-notified")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/onboarding/department-team-notified")
            ->assertOk();

        $this->assertNotNull(
            EmployeeOnboarding::query()->where('employee_id', $emp->id)->value('dept_team_notified_at'),
        );
        $this->assertSame(1, ActivityEvent::query()
            ->where('action', 'onboarding_department_team_notified')
            ->where('subject_id', $emp->id)
            ->count());
    }

    public function test_non_hr_editor_cannot_attest_even_in_the_same_department(): void
    {
        $emp = app(EmployeeService::class)->create($this->basePayload());
        $headEmployee = Employee::factory()->create(['department_id' => $emp->department_id]);
        $head = $this->userForRole('department_head', $headEmployee->id);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/onboarding/department-team-notified")
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_events', [
            'action' => 'onboarding_department_team_notified',
            'subject_id' => $emp->id,
        ]);
    }

    public function test_missing_and_archived_employees_are_not_attestable(): void
    {
        $hr = $this->userForRole('hr_officer');
        $archived = Employee::factory()->create();
        $archivedId = $archived->hash_id;
        $archived->delete();

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/v1/hr/employees/{$archivedId}/onboarding/department-team-notified")
            ->assertNotFound();

        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/v1/hr/employees/does-not-exist/onboarding/department-team-notified')
            ->assertNotFound();
    }

    public function test_empty_reminder_audience_leaves_row_retryable(): void
    {
        $emp = app(EmployeeService::class)->create($this->basePayload());
        EmployeeOnboarding::query()->where('employee_id', $emp->id)->update(['created_at' => now()->subDays(4)]);

        $count = app(OnboardingService::class)->sendRemindersForStaleOnboardings();

        $this->assertSame(0, $count);
        $this->assertNull(EmployeeOnboarding::query()->where('employee_id', $emp->id)->value('reminder_sent_at'));
    }

    public function test_notification_delivery_failure_leaves_row_retryable(): void
    {
        $emp = app(EmployeeService::class)->create($this->basePayload());
        $hr = $this->userForRole('hr_officer');
        EmployeeOnboarding::query()->where('employee_id', $emp->id)->update(['created_at' => now()->subDays(4)]);

        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldReceive('sendInApp')->once()->andThrow(new \RuntimeException('notification insert failed'));
        });

        $count = app(OnboardingService::class)->sendRemindersForStaleOnboardings();

        $this->assertSame(0, $count);
        $this->assertNull(EmployeeOnboarding::query()->where('employee_id', $emp->id)->value('reminder_sent_at'));
        $this->assertDatabaseMissing('notifications', ['type' => 'hr.onboarding.stale', 'notifiable_id' => $hr->id]);
    }

    public function test_in_app_preference_is_respected_without_losing_reminder_state(): void
    {
        $emp = app(EmployeeService::class)->create($this->basePayload());
        $hr = $this->userForRole('hr_officer');
        EmployeeOnboarding::query()->where('employee_id', $emp->id)->update(['created_at' => now()->subDays(4)]);
        DB::table('notification_preferences')->insert([
            'user_id' => $hr->id,
            'notification_type' => 'hr.onboarding.stale',
            'channel' => 'in_app',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, app(OnboardingService::class)->sendRemindersForStaleOnboardings());
        $this->assertNotNull(EmployeeOnboarding::query()->where('employee_id', $emp->id)->value('reminder_sent_at'));
        $this->assertDatabaseMissing('notifications', [
            'type' => 'hr.onboarding.stale',
            'notifiable_id' => $hr->id,
        ]);
    }
}
