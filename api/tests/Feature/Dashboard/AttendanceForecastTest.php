<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Analytics\AttendanceWidgetAnalytics;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use App\Modules\Dashboard\Services\HrDashboardService;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 8 — attendance forecast on the hr dashboard, read-only.
 *
 * Plant-level aggregate engine: expected attendance rate (flat run-rate of
 * the monthly present share) against the approved leave pipeline (leave-days
 * overlapping each future month as a share of headcount × days in month).
 * Any month at or over the high threshold flags the whole horizon. No
 * attendance rows degrade to the empty shape, never a divide-by-zero. The
 * payload gates the panel on `attendance.view` (PanelGate strips it
 * server-side, never CSS-hidden) — forecasting.view would hide it from
 * hr_officer, who holds no forecasting grant.
 */
class AttendanceForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'atfc+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    public function test_no_attendance_rows_return_the_empty_shape_without_dividing_by_zero(): void
    {
        $result = app(ForecastingDashboardService::class)->attendanceForecast();

        $this->assertSame([], $result['months']);
        $this->assertSame([], $result['forecast']);
        $this->assertNull($result['confidence']);
        $this->assertSame('stable', $result['trend']);
        $this->assertSame('low', $result['risk']);
    }

    public function test_rising_leave_load_fixture_flags_high_on_month_three(): void
    {
        $horizon = app(SettingsService::class)->requiredInt('forecasting.default_horizon_months', 1, 12);
        $this->assertGreaterThanOrEqual(3, $horizon);

        $employees = Employee::factory()->count(5)->create(['status' => 'active']);

        // Two flat months of perfect attendance: the expected rate is 100 and
        // the series has enough points for a real confidence figure.
        foreach ([now()->subMonthNoOverflow(), now()] as $month) {
            foreach ($employees as $employee) {
                DB::table('attendances')->insert([
                    'employee_id' => $employee->id,
                    'date' => $month->copy()->startOfMonth()->addDays(5)->toDateString(),
                    'status' => 'present',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Rising approved pipeline, fully inside each future month: nothing in
        // month 1 (low), 15 leave-days in month 2 (medium at any month length),
        // 25 leave-days in month 3 (high at any month length, 5 employees).
        $this->seedLeaveDays($employees->take(3)->all(), now()->addMonthsNoOverflow(2), 15);
        $this->seedLeaveDays($employees->all(), now()->addMonthsNoOverflow(3), 25);

        $result = app(ForecastingDashboardService::class)->attendanceForecast();

        $this->assertCount($horizon, $result['months']);
        foreach ($result['months'] as $month) {
            $this->assertArrayHasKey('expected_rate', $month);
            $this->assertArrayHasKey('leave_load', $month);
            $this->assertArrayHasKey('risk', $month);
            $this->assertArrayHasKey('confidence', $month);
            $this->assertSame(100.0, $month['expected_rate']);
        }
        $this->assertSame(0.0, $result['months'][0]['leave_load']);
        $this->assertSame('low', $result['months'][0]['risk']);
        $this->assertSame('medium', $result['months'][1]['risk']);
        $this->assertSame('high', $result['months'][2]['risk']);
        $this->assertSame('high', $result['risk']);
        $this->assertSame('stable', $result['trend']);
        $this->assertSame(100.0, $result['confidence']);
    }

    /** @param array<int, Employee> $employees */
    private function seedLeaveDays(array $employees, \Carbon\Carbon $month, int $totalDays): void
    {
        $perEmployee = intdiv($totalDays, count($employees));
        $start = $month->copy()->startOfMonth()->addDays(5);
        foreach ($employees as $employee) {
            LeaveRequest::factory()->approved()->create([
                'employee_id' => $employee->id,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays($perEmployee - 1)->toDateString(),
                'days' => (float) $perEmployee,
            ]);
        }
    }

    public function test_hr_payload_strips_attendance_without_the_grant(): void
    {
        $role = Role::create([
            'name' => 'AT Role '.substr(uniqid(), -6),
            'slug' => 'atfc-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            \App\Modules\Auth\Models\Permission::query()
                ->whereIn('slug', ['dashboard.hr.view'])->pluck('id')->all(),
        );
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'atfc+'.substr(uniqid(), -8).'@t.test',
        ]);

        $panels = app(HrDashboardService::class)->hr($user)['panels'];

        $this->assertArrayNotHasKey('attendance_forecast', $panels);
        // Ungated panels are untouched.
        $this->assertArrayHasKey('probation_horizon_days', $panels);
    }

    public function test_hr_payload_carries_attendance_with_the_grant_and_widget_paths_agree(): void
    {
        $panels = app(HrDashboardService::class)->hr($this->userWithRole('hr_officer'))['panels'];
        $this->assertArrayHasKey('attendance_forecast', $panels);
        $this->assertArrayHasKey('months', $panels['attendance_forecast']);
        $this->assertArrayHasKey('risk', $panels['attendance_forecast']);

        $scalar = app(DashboardWidgetDataService::class)
            ->summaries(['forecast.attendance'], $this->userWithRole('hr_officer'));

        $this->assertSame('percent', $scalar['forecast.attendance']['kind']);

        $rich = app(AttendanceWidgetAnalytics::class)
            ->payload('forecast.attendance', $this->userWithRole('hr_officer'));

        // No attendance rows: the rich path degrades to the scalar, never an
        // axis with no points behind it.
        $this->assertSame([], $rich);
    }
}
