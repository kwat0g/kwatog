<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Overtime request lifecycle notification events.
 *
 * Tests confirm that each service method fires the expected domain event.
 * Event::fake() intercepts dispatches without running actual listeners.
 *
 * Requires RolePermissionSeeder because OvertimeService checks role/permission
 * relationships and departmentHeadFor() looks up role slugs.
 */
class OvertimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * An approver who is actually allowed to decide $ot.
     *
     * A user created with only a `role_id` has no `employee_id`, so it has no
     * department either, and `OvertimeDecisionPolicy::assertCanDecide()` correctly
     * refuses it — an approver who cannot see a request must not decide it. That
     * refusal is the guard working, not a bug, so the fixture has to supply the
     * missing link: an employee in the requester's own department.
     */
    private function departmentHeadFor(OvertimeRequest $ot): User
    {
        $requester = $ot->employee ?? Employee::query()->findOrFail($ot->employee_id);

        $approverEmployee = Employee::factory()->create([
            'department_id' => $requester->department_id,
            'position_id'   => $requester->position_id,
        ]);

        return User::factory()->create([
            'role_id'     => Role::where('slug', 'department_head')->firstOrFail()->id,
            'employee_id' => $approverEmployee->id,
            'is_active'   => true,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * OvertimeRequestSubmitted fires when create() persists a new OT request.
     */
    public function test_ot_submitted_fires_event(): void
    {
        Event::fake([OvertimeRequestSubmitted::class]);

        $ot = OvertimeRequest::factory()->pending()->make();

        app(OvertimeService::class)->create([
            'employee_id'     => $ot->employee_id,
            'date'            => $ot->date->toDateString(),
            'hours_requested' => $ot->hours_requested,
            'reason'          => $ot->reason,
        ]);

        Event::assertDispatched(OvertimeRequestSubmitted::class);
    }

    /**
     * approve() fires OvertimeRequestDecided with approved = true.
     */
    public function test_ot_approved_fires_decided_event(): void
    {
        Event::fake([OvertimeRequestDecided::class]);

        $ot       = OvertimeRequest::factory()->pending()->create();
        $approver = $this->departmentHeadFor($ot);

        app(OvertimeService::class)->approve($ot, $approver);

        Event::assertDispatched(
            OvertimeRequestDecided::class,
            fn ($e) => $e->approved === true && $e->overtimeRequest->getKey() === $ot->getKey(),
        );
    }

    /**
     * reject() fires OvertimeRequestDecided with approved = false.
     */
    public function test_ot_rejected_fires_decided_event(): void
    {
        Event::fake([OvertimeRequestDecided::class]);

        $ot       = OvertimeRequest::factory()->pending()->create();
        $approver = $this->departmentHeadFor($ot);

        app(OvertimeService::class)->reject($ot, $approver, 'No budget.');

        Event::assertDispatched(
            OvertimeRequestDecided::class,
            fn ($e) => $e->approved === false && $e->overtimeRequest->getKey() === $ot->getKey(),
        );
    }

    /**
     * All event classes exist and load cleanly (catches namespace/typo issues early).
     */
    public function test_all_overtime_event_classes_exist(): void
    {
        $events = [
            OvertimeRequestSubmitted::class,
            OvertimeRequestDecided::class,
        ];

        foreach ($events as $cls) {
            $this->assertTrue(class_exists($cls), "Event class {$cls} should exist");
        }
    }
}
