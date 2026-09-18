<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Enums\HolidayType;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveBusinessDayValidationTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $this->leaveType = LeaveType::query()->where('code', 'VL')->firstOrFail();
    }

    public function test_sunday_only_full_day_request_is_rejected_without_creating_a_record(): void
    {
        $employee = Employee::factory()->create([
            'department_id' => Department::query()->firstOrFail()->id,
        ]);
        $this->seedBalance($employee);
        $sunday = $this->nextSunday();

        $this->actingAs($this->employeeUser($employee))
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveType->hash_id,
                'start_date' => $sunday,
                'end_date' => $sunday,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A leave range must include at least one working day (excluding Sundays and public holidays).');

        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $employee->id,
            'start_date' => $sunday,
            'end_date' => $sunday,
        ]);
    }

    public function test_business_day_request_still_creates_one_day_pending_request(): void
    {
        $employee = Employee::factory()->create([
            'department_id' => Department::query()->firstOrFail()->id,
        ]);
        $this->seedBalance($employee);
        $businessDay = Carbon::parse($this->nextSunday())->addDay()->toDateString();

        $this->actingAs($this->employeeUser($employee))
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveType->hash_id,
                'start_date' => $businessDay,
                'end_date' => $businessDay,
            ])
            ->assertCreated()
            ->assertJsonPath('data.days', '1.0')
            ->assertJsonPath('data.status', 'pending_dept');

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'start_date' => $businessDay,
            'end_date' => $businessDay,
            'days' => '1.0',
        ]);
    }

    public function test_public_holiday_is_not_charged_as_a_leave_day(): void
    {
        $employee = Employee::factory()->create([
            'department_id' => Department::query()->firstOrFail()->id,
        ]);
        $this->seedBalance($employee);
        $start = Carbon::parse($this->nextSunday())->addDay();
        $holiday = $start->copy()->addDay();
        $end = $holiday->copy()->addDay();
        Holiday::create([
            'name' => 'Regular holiday fixture',
            'date' => $holiday->toDateString(),
            'type' => HolidayType::Regular->value,
            'is_recurring' => false,
        ]);

        $this->actingAs($this->employeeUser($employee))
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveType->hash_id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.days', '2.0');
    }

    public function test_holiday_only_leave_request_is_rejected_without_consuming_balance(): void
    {
        $employee = Employee::factory()->create([
            'department_id' => Department::query()->firstOrFail()->id,
        ]);
        $this->seedBalance($employee);
        $holiday = Carbon::parse($this->nextSunday())->addDay();
        Holiday::create([
            'name' => 'Special non-working fixture',
            'date' => $holiday->toDateString(),
            'type' => HolidayType::SpecialNonWorking->value,
            'is_recurring' => false,
        ]);

        $this->actingAs($this->employeeUser($employee))
            ->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id,
                'leave_type_id' => $this->leaveType->hash_id,
                'start_date' => $holiday->toDateString(),
                'end_date' => $holiday->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A leave range must include at least one working day (excluding Sundays and public holidays).');

        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $employee->id,
            'start_date' => $holiday->toDateString(),
            'end_date' => $holiday->toDateString(),
        ]);
        $this->assertSame('0.0', (string) EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->value('used'));
    }

    private function employeeUser(Employee $employee): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => $employee->id,
            'email' => 'leave-business-day-'.uniqid().'@test.invalid',
            'is_active' => true,
        ]);
    }

    private function seedBalance(Employee $employee): void
    {
        EmployeeLeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => (int) now()->year,
            'total_credits' => 10.0,
            'used' => 0,
            'remaining' => 10.0,
        ]);
    }

    private function nextSunday(): string
    {
        $date = Carbon::now()->startOfDay();
        while (! $date->isSunday()) {
            $date->addDay();
        }

        return $date->toDateString();
    }
}
