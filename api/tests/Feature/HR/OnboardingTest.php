<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeOnboarding;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\OnboardingService;
use App\Modules\HR\Services\UserProvisioningService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
        Notification::fake();
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

        /** @var OnboardingService $ob */
        $ob = app(OnboardingService::class);
        $status = $ob->status($emp);
        $accountStep = collect($status['steps'])->firstWhere('key', 'account_provisioned');
        $this->assertNull($accountStep['completed_at']);

        // Provision the account
        app(UserProvisioningService::class)->provisionForEmployee($emp->fresh(), ['send_welcome' => false]);

        $status = $ob->status($emp->fresh());
        $accountStep = collect($status['steps'])->firstWhere('key', 'account_provisioned');
        $this->assertNotNull($accountStep['completed_at']);
    }

    public function test_complete_onboarding_sets_completed_at(): void
    {
        /** @var EmployeeService $emps */
        $emps = app(EmployeeService::class);
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
        $ob->markStep($emp->fresh(), 'shift_assigned');
        $ob->markStep($emp->fresh(), 'dept_team_notified');
        app(UserProvisioningService::class)->provisionForEmployee($emp->fresh(), ['send_welcome' => false]);
        $ob->recompute($emp->fresh());

        $status = $ob->status($emp->fresh());
        $this->assertTrue($status['is_complete']);
        $this->assertNotNull($status['completed_at']);
    }

    public function test_reminder_only_for_stale_onboardings(): void
    {
        /** @var EmployeeService $svc */
        $svc = app(EmployeeService::class);
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
    }
}
