<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Loan request lifecycle notification events.
 *
 * Tests confirm that each service method fires the expected domain event.
 * Event::fake() intercepts dispatches without running actual listeners.
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

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
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

    /**
     * approve() fires LoanDecided with approved = true.
     */
    public function test_loan_approved_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);

        $employee = Employee::factory()->create();
        $loan     = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal'   => '5000.00',
            'pay_periods' => 5,
            'purpose'     => 'Emergency expense',
        ]);

        $approver = $this->userWithRole('department_head');

        app(LoanService::class)->approve($loan, $approver);

        Event::assertDispatched(
            LoanDecided::class,
            fn ($e) => $e->approved === true && $e->loan->getKey() === $loan->getKey(),
        );
    }

    /**
     * reject() fires LoanDecided with approved = false.
     */
    public function test_loan_rejected_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);

        $employee = Employee::factory()->create();
        $loan     = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal'   => '5000.00',
            'pay_periods' => 5,
            'purpose'     => 'Emergency expense',
        ]);

        $approver = $this->userWithRole('department_head');

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
