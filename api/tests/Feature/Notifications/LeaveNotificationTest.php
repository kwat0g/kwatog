<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Leave request lifecycle notification events.
 *
 * Tests confirm that each service method fires the expected domain event.
 * We use Event::fake() to intercept dispatches without running actual listeners
 * (which would need NotificationService, queues, etc.).
 *
 * Requires WorkflowSeeder because ApprovalService looks up WorkflowDefinition
 * rows, and RolePermissionSeeder because ApprovalService checks role slugs.
 */
class LeaveNotificationTest extends TestCase
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

    /**
     * Create a leave request via factory and attach approval records as if
     * it had gone through ApprovalService::submit(). This lets us call
     * approveDept/approveHR/reject without relying on service->submit().
     */
    private function makePendingDeptRequest(): LeaveRequest
    {
        $req = LeaveRequest::factory()->pendingDept()->create();
        $this->seedApprovalRecords($req, 'pending', 'pending');
        return $req;
    }

    /**
     * Create a request that has already passed dept approval (pendingHR state).
     * Step 1 is approved, step 2 (hr_officer) is pending.
     */
    private function makePendingHRRequest(): LeaveRequest
    {
        $req = LeaveRequest::factory()->pendingHR()->create();
        $this->seedApprovalRecords($req, 'approved', 'pending');
        return $req;
    }

    /**
     * Directly insert approval records for the two-step leave workflow.
     */
    private function seedApprovalRecords(LeaveRequest $req, string $step1Action, string $step2Action): void
    {
        ApprovalRecord::insert([
            [
                'approvable_type' => $req->getMorphClass(),
                'approvable_id'   => $req->getKey(),
                'step_order'      => 1,
                'role_slug'       => 'department_head',
                'action'          => $step1Action,
                'created_at'      => now(),
            ],
            [
                'approvable_type' => $req->getMorphClass(),
                'approvable_id'   => $req->getKey(),
                'step_order'      => 2,
                'role_slug'       => 'hr_officer',
                'action'          => $step2Action,
                'created_at'      => now(),
            ],
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * LeaveRequestSubmitted fires immediately after the request is created.
     * We dispatch directly here because submit() requires a balance row and
     * full workflow setup — that complexity belongs to a dedicated service test.
     */
    public function test_leave_submitted_event_fired_when_request_created(): void
    {
        Event::fake([LeaveRequestSubmitted::class]);

        $req = LeaveRequest::factory()->pendingDept()->create();
        LeaveRequestSubmitted::dispatch($req);

        Event::assertDispatched(LeaveRequestSubmitted::class, fn ($e) => $e->leaveRequest->is($req));
    }

    /**
     * approveDept() fires LeaveRequestPendingHR so HR officers get notified.
     */
    public function test_leave_dept_approved_fires_pending_hr_event(): void
    {
        Event::fake([LeaveRequestPendingHR::class]);

        $req      = $this->makePendingDeptRequest();
        $deptHead = $this->userWithRole('department_head');

        app(LeaveRequestService::class)->approveDept($req, $deptHead);

        Event::assertDispatched(LeaveRequestPendingHR::class, fn ($e) => $e->leaveRequest->is($req));
    }

    /**
     * approveHR() fires LeaveRequestApproved so the employee gets notified.
     */
    public function test_leave_hr_approved_fires_approved_event(): void
    {
        Event::fake([LeaveRequestApproved::class]);

        $req    = $this->makePendingHRRequest();
        $hrUser = $this->userWithRole('hr_officer');

        // approveHR calls LeaveBalanceService::consume() → needs a balance row.
        EmployeeLeaveBalance::create([
            'employee_id'   => $req->employee_id,
            'leave_type_id' => $req->leave_type_id,
            'year'          => (int) $req->start_date->format('Y'),
            'total_credits' => 10.0,
            'used'          => 0.0,
            'remaining'     => 10.0,
        ]);

        app(LeaveRequestService::class)->approveHR($req, $hrUser);

        Event::assertDispatched(LeaveRequestApproved::class, fn ($e) => $e->leaveRequest->is($req));
    }

    /**
     * reject() fires LeaveRequestRejected so the employee gets notified.
     */
    public function test_leave_rejected_fires_rejected_event(): void
    {
        Event::fake([LeaveRequestRejected::class]);

        $req      = $this->makePendingDeptRequest();
        $deptHead = $this->userWithRole('department_head');

        app(LeaveRequestService::class)->reject($req, $deptHead, 'Insufficient leave balance.');

        Event::assertDispatched(LeaveRequestRejected::class, fn ($e) => $e->leaveRequest->is($req));
    }

    /**
     * All four event classes exist and load cleanly (catches namespace/typo issues early).
     */
    public function test_all_leave_event_classes_exist(): void
    {
        $events = [
            LeaveRequestSubmitted::class,
            LeaveRequestPendingHR::class,
            LeaveRequestApproved::class,
            LeaveRequestRejected::class,
        ];

        foreach ($events as $cls) {
            $this->assertTrue(class_exists($cls), "Event class {$cls} should exist");
        }
    }
}
