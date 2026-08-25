<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalendarAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Department $alpha;

    private Department $beta;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $departments = Department::query()->orderBy('id')->take(2)->get();
        $this->alpha = $departments->firstOrFail();
        $this->beta = $departments->lastOrFail();
        $this->leaveType = LeaveType::query()->firstOrFail();
    }

    private function userFor(string $role, ?Employee $employee = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->firstOrFail()->id,
            'employee_id' => $employee?->id,
            'email' => 'calendar+'.substr(uniqid(), -8).'@t.test',
            'is_active' => true,
        ]);
    }

    private function approvedLeave(Employee $employee, string $date): LeaveRequest
    {
        $request = LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $date,
            'end_date' => $date,
            'days' => 1,
        ]);

        $request->forceFill(['status' => 'approved'])->save();

        return $request->fresh();
    }

    private function eventsUrl(string $from, string $to, array $layers = ['leave']): string
    {
        return '/api/v1/calendar/events?'.http_build_query([
            'from' => $from,
            'to' => $to,
            'layers' => $layers,
        ]);
    }

    public function test_plain_employee_sees_only_own_approved_leave(): void
    {
        $own = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $other = Employee::factory()->create(['department_id' => $this->beta->id]);
        $from = now()->startOfMonth()->toDateString();
        $ownLeave = $this->approvedLeave($own, now()->startOfMonth()->addDays(4)->toDateString());
        $this->approvedLeave($other, now()->startOfMonth()->addDays(5)->toDateString());

        $response = $this->actingAs($this->userFor('employee', $own))
            ->getJson($this->eventsUrl($from, now()->endOfMonth()->toDateString()));

        $response->assertOk()
            ->assertJsonPath('meta.layers', ['leave'])
            ->assertJsonPath('meta.layer_counts.leave.truncated', false);

        $events = collect($response->json('data'));
        $this->assertCount(1, $events);
        $this->assertSame('/self-service/leave', $events->first()['link']);
        $this->assertStringContainsString($own->first_name, $events->first()['title']);
        $this->assertStringNotContainsString($other->first_name, $events->first()['title']);
        $this->assertStringContainsString((string) $ownLeave->hash_id, $events->first()['id']);
    }

    public function test_department_head_is_scoped_and_cannot_filter_another_department(): void
    {
        $head = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $member = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $other = Employee::factory()->create(['department_id' => $this->beta->id]);
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $this->approvedLeave($head, now()->startOfMonth()->addDays(1)->toDateString());
        $this->approvedLeave($member, now()->startOfMonth()->addDays(2)->toDateString());
        $this->approvedLeave($other, now()->startOfMonth()->addDays(3)->toDateString());
        $user = $this->userFor('department_head', $head);

        $response = $this->actingAs($user)->getJson($this->eventsUrl($from, $to));

        $response->assertOk();
        $events = collect($response->json('data'));
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (array $event): bool => $event['link'] !== null));
        $this->assertTrue($events->every(fn (array $event): bool => str_starts_with($event['link'], '/hr/leaves/')));

        $this->actingAs($user)
            ->getJson($this->eventsUrl($from, $to). '&department_id='.$this->beta->hash_id)
            ->assertForbidden();
    }

    public function test_own_payroll_permission_does_not_expose_payroll_periods(): void
    {
        $employee = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $user = $this->userFor('employee', $employee);
        $from = now()->startOfMonth()->toDateString();
        $date = now()->startOfMonth()->addDays(6)->toDateString();

        PayrollPeriod::factory()->create([
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->addDays(14)->toDateString(),
            'payroll_date' => $date,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson($this->eventsUrl(
            $from,
            now()->endOfMonth()->toDateString(),
            ['payroll'],
        ));

        $response->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.layers', [])
            ->assertJsonPath('meta.requested_layers', ['payroll']);
    }

    public function test_hr_payroll_layer_returns_authorized_record_link(): void
    {
        $user = $this->userFor('hr_officer');
        $from = now()->startOfMonth()->toDateString();
        $period = PayrollPeriod::factory()->create([
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->addDays(14)->toDateString(),
            'payroll_date' => now()->startOfMonth()->addDays(6)->toDateString(),
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson($this->eventsUrl(
            $from,
            now()->endOfMonth()->toDateString(),
            ['payroll'],
        ));

        $response->assertOk();
        $event = collect($response->json('data'))->sole();
        $this->assertSame('/payroll/periods/'.$period->hash_id, $event['link']);
    }

    public function test_soft_deleted_leave_and_spanning_maintenance_work_order_are_handled(): void
    {
        $user = $this->userFor('system_admin');
        $employee = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $leave = $this->approvedLeave($employee, now()->startOfMonth()->addDays(4)->toDateString());
        $leave->delete();

        DB::table('maintenance_work_orders')->insert([
            'mwo_number' => 'MWO-CALENDAR-1',
            'maintainable_type' => 'machine',
            'maintainable_id' => 999999,
            'type' => 'corrective',
            'priority' => 'medium',
            'description' => 'Long running maintenance',
            'status' => 'in_progress',
            'started_at' => now()->startOfMonth()->subDays(5),
            'completed_at' => now()->endOfMonth()->addDays(5),
            'downtime_minutes' => 0,
            'cost' => 0,
            'created_by' => $user->id,
            'created_at' => now()->startOfMonth()->subDays(6),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson($this->eventsUrl(
            now()->startOfMonth()->addDays(2)->toDateString(),
            now()->startOfMonth()->addDays(8)->toDateString(),
            ['holiday', 'leave', 'maintenance'],
        ));

        $response->assertOk();
        $events = collect($response->json('data'));
        $this->assertFalse($events->contains(fn (array $event): bool => $event['type'] === 'leave'));
        $this->assertTrue($events->contains(fn (array $event): bool => $event['type'] === 'maintenance'));
    }

    public function test_options_expose_narrow_delivery_read_and_normalize_duplicate_layers(): void
    {
        $warehouse = $this->userFor('warehouse_staff');
        $options = $this->actingAs($warehouse)->getJson('/api/v1/calendar/options');

        $options->assertOk();
        $layers = collect($options->json('data.layers'))->pluck('value')->all();
        $this->assertContains('delivery', $layers);
        $this->assertIsArray($options->json('data.departments'));

        $from = now()->startOfMonth()->toDateString();
        $this->actingAs($warehouse)->getJson('/api/v1/calendar/events?'.http_build_query([
            'from' => $from,
            'to' => now()->endOfMonth()->toDateString(),
            'layers' => ['holiday', 'holiday'],
        ]))->assertUnprocessable();
    }

    public function test_invalid_department_filter_is_rejected_without_falling_back_to_unscoped_results(): void
    {
        $this->actingAs($this->userFor('hr_officer'))
            ->getJson('/api/v1/calendar/events?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
                'layers' => ['leave'],
                'department_id' => 'not-a-valid-hash',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.department_id.0', 'Select a valid department.');
    }
}
