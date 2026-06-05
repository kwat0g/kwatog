<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
 * relationships and userWithRole() looks up role slugs.
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

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
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
        $approver = $this->userWithRole('department_head');

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
        $approver = $this->userWithRole('department_head');

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
