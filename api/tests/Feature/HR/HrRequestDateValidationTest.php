<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HrRequestDateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_day_leave_requires_advance_dates_and_overtime_and_half_day_allow_today_but_never_past_dates(): void
    {
        $this->seed([RolePermissionSeeder::class, DepartmentSeeder::class, PositionSeeder::class, LeaveTypeSeeder::class, WorkflowSeeder::class]);
        $this->travelTo(Carbon::parse('2026-10-09 02:00:00', 'Asia/Manila'));
        app(SettingsService::class)->set('leave.request.past_window_days', 30);
        app(SettingsService::class)->set('attendance.ot.request_past_days', 30);
        $employee = Employee::factory()->create();
        $user = User::factory()->withRole('employee')->create(['employee_id' => $employee->id]);
        $hr = User::factory()->withRole('hr_officer')->create();
        $type = LeaveType::where('code', 'VL')->firstOrFail();
        EmployeeLeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026,
            'total_credits' => 15, 'used' => 0, 'remaining' => 15,
        ]);
        $this->actingAs($user);

        foreach (['2026-10-08' => 422, '2026-10-09' => 422, '2026-10-09 23:00:00' => 422, '2026-10-10' => 201, '2026-10-13' => 201] as $date => $status) {
            $this->actingAs($user);
            $response = $this->postJson('/api/v1/leaves/requests', [
                'employee_id' => $employee->hash_id, 'leave_type_id' => $type->hash_id,
                'start_date' => $date, 'end_date' => $date,
            ])->assertStatus($status);
            if ($status === 422) {
                $response->assertJsonValidationErrors('start_date');
                $this->assertDatabaseMissing('leave_requests', ['employee_id' => $employee->id]);
            }
            $overtimeStatus = $date === '2026-10-08' ? 422 : 201;
            foreach (['/api/v1/attendance/overtime-requests', '/api/v1/hr/self-service/overtime'] as $endpoint) {
                $this->actingAs($endpoint === '/api/v1/attendance/overtime-requests' ? $hr : $user);
                $response = $this->postJson($endpoint, [
                    'employee_id' => $employee->hash_id, 'date' => $date,
                    'hours_requested' => 1, 'reason' => 'Manual request date regression',
                ])->assertStatus($overtimeStatus);
                if ($overtimeStatus === 422) {
                    $response->assertJsonValidationErrors('date');
                    $this->assertDatabaseMissing('overtime_requests', ['employee_id' => $employee->id]);
                }
            }
        }
        $this->actingAs($user);
        foreach (['am', 'pm'] as $period) {
            foreach (['2026-10-08' => 422, '2026-10-09' => 201] as $date => $status) {
                $count = LeaveRequest::count();
                $response = $this->postJson('/api/v1/leaves/requests', [
                    'employee_id' => $employee->hash_id, 'leave_type_id' => $type->hash_id,
                    'start_date' => $date, 'end_date' => $date, 'half_day_period' => $period,
                ])->assertStatus($status);
                if ($status === 422) {
                    $response->assertJsonValidationErrors('start_date');
                    $this->assertSame($count, LeaveRequest::count());
                } else {
                    $response->assertJsonPath('data.days', '0.5');
                }
            }
        }
    }
}
