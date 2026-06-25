<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);

        $hrRole = Role::query()->where('slug', 'hr_officer')->firstOrFail();
        $this->user = User::factory()->create([
            'role_id'   => $hrRole->id,
            'is_active' => true,
        ]);
        $this->dept = Department::query()->first();
    }

    public function test_calendar_returns_daily_coverage(): void
    {
        // Create active employees in the department
        $employees = Employee::factory()->count(5)->create([
            'department_id' => $this->dept->id,
            'status'        => 'active',
        ]);

        $leaveType = LeaveType::firstOrCreate(
            ['code' => 'VL'],
            ['name' => 'Vacation Leave', 'default_balance' => 5.0, 'is_paid' => true, 'is_active' => true],
        );

        // Create an approved leave spanning 3 days in current month
        $start = now()->startOfMonth()->addDays(4);
        $end   = $start->copy()->addDays(2);

        $lr = LeaveRequest::factory()->create([
            'employee_id'   => $employees[0]->id,
            'leave_type_id' => $leaveType->id,
            'start_date'    => $start->toDateString(),
            'end_date'      => $end->toDateString(),
            'days'          => 3.0,
        ]);
        $lr->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/leaves/calendar?' . http_build_query([
                'year'          => now()->year,
                'month'         => now()->month,
                'department_id' => $this->dept->hash_id,
            ]));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'year',
                    'month',
                    'headcount',
                    'days' => [
                        '*' => [
                            'date',
                            'day_of_week',
                            'approved_count',
                            'pending_count',
                            'present_count',
                            'headcount',
                            'coverage_pct',
                            'employees_on_leave',
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(5, $data['headcount']);

        // Find a day with the approved leave
        $leaveDay = collect($data['days'])->firstWhere('date', $start->toDateString());
        $this->assertNotNull($leaveDay);
        $this->assertEquals(1, $leaveDay['approved_count']);
        $this->assertEquals(4, $leaveDay['present_count']);
        $this->assertEquals(80.0, $leaveDay['coverage_pct']);
    }

    public function test_calendar_filters_by_department(): void
    {
        $deptA = Department::factory()->create(['name' => 'Dept A']);
        $deptB = Department::factory()->create(['name' => 'Dept B']);

        Employee::factory()->count(3)->create(['department_id' => $deptA->id, 'status' => 'active']);
        Employee::factory()->count(2)->create(['department_id' => $deptB->id, 'status' => 'active']);

        $leaveType = LeaveType::firstOrCreate(
            ['code' => 'VL'],
            ['name' => 'Vacation Leave', 'default_balance' => 5.0, 'is_paid' => true, 'is_active' => true],
        );

        // Leave for employee in dept A
        $empA = Employee::query()->where('department_id', $deptA->id)->first();
        $start = now()->startOfMonth()->addDays(2);
        $lr = LeaveRequest::factory()->create([
            'employee_id'   => $empA->id,
            'leave_type_id' => $leaveType->id,
            'start_date'    => $start->toDateString(),
            'end_date'      => $start->toDateString(),
            'days'          => 1.0,
        ]);
        $lr->forceFill(['status' => 'approved'])->save();

        // Filter by dept A — should see the leave
        $responseA = $this->actingAs($this->user)
            ->getJson('/api/v1/leaves/calendar?' . http_build_query([
                'year'          => now()->year,
                'month'         => now()->month,
                'department_id' => $deptA->hash_id,
            ]));

        $responseA->assertOk();
        $dataA = $responseA->json('data');
        $this->assertEquals(3, $dataA['headcount']);
        $dayA = collect($dataA['days'])->firstWhere('date', $start->toDateString());
        $this->assertEquals(1, $dayA['approved_count']);

        // Filter by dept B — should NOT see the leave
        $responseB = $this->actingAs($this->user)
            ->getJson('/api/v1/leaves/calendar?' . http_build_query([
                'year'          => now()->year,
                'month'         => now()->month,
                'department_id' => $deptB->hash_id,
            ]));

        $responseB->assertOk();
        $dataB = $responseB->json('data');
        $this->assertEquals(2, $dataB['headcount']);
        $dayB = collect($dataB['days'])->firstWhere('date', $start->toDateString());
        $this->assertEquals(0, $dayB['approved_count']);
    }

    public function test_calendar_counts_approved_and_pending_separately(): void
    {
        Employee::factory()->count(4)->create([
            'department_id' => $this->dept->id,
            'status'        => 'active',
        ]);

        $leaveType = LeaveType::firstOrCreate(
            ['code' => 'VL'],
            ['name' => 'Vacation Leave', 'default_balance' => 5.0, 'is_paid' => true, 'is_active' => true],
        );

        $start = now()->startOfMonth()->addDays(6);
        $emps = Employee::query()->where('department_id', $this->dept->id)->get();

        // One approved
        $lr1 = LeaveRequest::factory()->create([
            'employee_id'   => $emps[0]->id,
            'leave_type_id' => $leaveType->id,
            'start_date'    => $start->toDateString(),
            'end_date'      => $start->toDateString(),
            'days'          => 1.0,
        ]);
        $lr1->forceFill(['status' => 'approved'])->save();

        // One pending dept
        $lr2 = LeaveRequest::factory()->create([
            'employee_id'   => $emps[1]->id,
            'leave_type_id' => $leaveType->id,
            'start_date'    => $start->toDateString(),
            'end_date'      => $start->toDateString(),
            'days'          => 1.0,
        ]);
        $lr2->forceFill(['status' => 'pending_dept'])->save();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/leaves/calendar?' . http_build_query([
                'year'          => now()->year,
                'month'         => now()->month,
                'department_id' => $this->dept->hash_id,
            ]));

        $response->assertOk();
        $day = collect($response->json('data.days'))->firstWhere('date', $start->toDateString());
        $this->assertEquals(1, $day['approved_count']);
        $this->assertEquals(1, $day['pending_count']);
        // Coverage is based on approved only (present = headcount - approved)
        $this->assertEquals(4, $day['headcount']);
        $this->assertEquals(3, $day['present_count']);
    }

    public function test_calendar_handles_multi_day_leaves(): void
    {
        Employee::factory()->count(2)->create([
            'department_id' => $this->dept->id,
            'status'        => 'active',
        ]);

        $leaveType = LeaveType::firstOrCreate(
            ['code' => 'VL'],
            ['name' => 'Vacation Leave', 'default_balance' => 5.0, 'is_paid' => true, 'is_active' => true],
        );

        $emp = Employee::query()->where('department_id', $this->dept->id)->first();
        $start = now()->startOfMonth()->addDays(9);
        $end   = $start->copy()->addDays(4); // 5 days

        $lr = LeaveRequest::factory()->create([
            'employee_id'   => $emp->id,
            'leave_type_id' => $leaveType->id,
            'start_date'    => $start->toDateString(),
            'end_date'      => $end->toDateString(),
            'days'          => 5.0,
        ]);
        $lr->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/leaves/calendar?' . http_build_query([
                'year'          => now()->year,
                'month'         => now()->month,
                'department_id' => $this->dept->hash_id,
            ]));

        $response->assertOk();
        $days = collect($response->json('data.days'));

        // All 5 days should show 1 approved
        for ($i = 0; $i <= 4; $i++) {
            $dateStr = $start->copy()->addDays($i)->toDateString();
            $day = $days->firstWhere('date', $dateStr);
            $this->assertNotNull($day, "Day {$dateStr} should exist");
            $this->assertEquals(1, $day['approved_count'], "Day {$dateStr} should have 1 approved");
            $this->assertEquals(50.0, $day['coverage_pct'], "Day {$dateStr} should have 50% coverage");
        }

        // Day before the leave should have 0 approved
        $beforeDate = $start->copy()->subDay()->toDateString();
        $beforeDay = $days->firstWhere('date', $beforeDate);
        if ($beforeDay) {
            $this->assertEquals(0, $beforeDay['approved_count']);
        }
    }

    public function test_calendar_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/leaves/calendar?' . http_build_query([
            'year'  => now()->year,
            'month' => now()->month,
        ]));

        $response->assertUnauthorized();
    }
}
