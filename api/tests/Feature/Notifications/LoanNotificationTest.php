<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Listeners\NotifyOnLoanDecided;
use App\Modules\Loans\Listeners\NotifyOnLoanSubmitted;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Loan request lifecycle notification events.
 *
 * Tests confirm that submission and terminal decisions fire domain events.
 * Intermediate approvals remain pending and must not masquerade as a final
 * approval notification. Event::fake() intercepts dispatches without running
 * actual listeners.
 */
class LoanNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function userWithRole(string $slug, ?int $employeeId = null): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => $employeeId,
            'is_active' => true,
        ]);
    }

    private function departmentHeadFor(Employee $loanEmployee): User
    {
        $approverEmployee = Employee::factory()->create([
            'department_id' => $loanEmployee->department_id,
        ]);

        return $this->userWithRole('department_head', $approverEmployee->id);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * LoanSubmitted fires when request() persists a new loan.
     */
    public function test_loan_submitted_fires_event(): void
    {
        Event::fake([LoanSubmitted::class]);

        $employee = Employee::factory()->create();

        app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal'   => '5000.00',
            'pay_periods' => 5,
            'purpose'     => 'Emergency expense',
        ]);

        Event::assertDispatched(LoanSubmitted::class);
    }

    public function test_loan_submission_notice_excludes_recipient_without_route_view_permission(): void
    {
        Event::fake([LoanSubmitted::class]);
        $employee = Employee::factory()->create();
        $loan = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal' => '5000.00',
            'pay_periods' => 5,
            'purpose' => 'Emergency expense',
        ]);
        $eligible = $this->userWithRole('finance_officer');
        $revoked = $this->userWithRole('finance_officer');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => Permission::query()->where('slug', 'loans.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $eligible->id,
            'reason' => 'Loan notice audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revoked->flushPermissionsCache();

        app(NotifyOnLoanSubmitted::class)->handle(new LoanSubmitted($loan->fresh(['employee'])));

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $eligible->id,
            'type' => 'loans.submitted',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $revoked->id,
            'type' => 'loans.submitted',
        ]);
    }

    public function test_loan_decision_notice_targets_owner_and_links_to_that_loan(): void
    {
        $employee = Employee::factory()->create();
        $owner = $this->userWithRole('employee', $employee->id);
        $otherEmployee = Employee::factory()->create();
        $other = $this->userWithRole('employee', $otherEmployee->id);
        $loan = EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'status' => LoanStatus::Rejected->value,
        ]);

        app(NotifyOnLoanDecided::class)->handle(new LoanDecided($loan, false));

        $notice = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'loans.rejected')
            ->first();
        $this->assertNotNull($notice);
        $data = json_decode($notice->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame("/self-service/loans/{$loan->hash_id}", $data['link_to']);
        $this->assertSame($loan->hash_id, $data['entity_id']);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $other->id,
            'type' => 'loans.rejected',
        ]);
    }

    /**
     * Intermediate approvals do not fire a final decision event.
     */
    public function test_loan_approved_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);

        // The department approver must exist before submission: without an
        // eligible department head the first step is auto-skipped and the
        // loan lands on the finance step instead.
        $employee = Employee::factory()->create([
            'department_id' => Department::factory()->create()->id,
        ]);
        $approver = $this->departmentHeadFor($employee);
        $loan     = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal'   => '5000.00',
            'pay_periods' => 5,
            'purpose'     => 'Emergency expense',
        ]);

        app(LoanService::class)->approve($loan, $approver);

        Event::assertNotDispatched(LoanDecided::class);
        $this->assertSame('pending', $loan->fresh()->status->value);
    }

    /**
     * reject() fires LoanDecided with approved = false.
     */
    public function test_loan_rejected_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);

        $employee = Employee::factory()->create([
            'department_id' => Department::factory()->create()->id,
        ]);
        $approver = $this->departmentHeadFor($employee);
        $loan     = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal'   => '5000.00',
            'pay_periods' => 5,
            'purpose'     => 'Emergency expense',
        ]);

        app(LoanService::class)->reject($loan, $approver, 'Insufficient budget.');

        Event::assertDispatched(
            LoanDecided::class,
            fn ($e) => $e->approved === false && $e->loan->getKey() === $loan->getKey(),
        );
    }

    /**
     * All event classes exist and load cleanly (catches namespace/typo issues early).
     */
    public function test_all_loan_event_classes_exist(): void
    {
        $events = [
            LoanSubmitted::class,
            LoanDecided::class,
        ];

        foreach ($events as $cls) {
            $this->assertTrue(class_exists($cls), "Event class {$cls} should exist");
        }
    }
}
