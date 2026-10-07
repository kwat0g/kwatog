<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait PunchFixtures
{
    protected function plant(): void
    {
        $s = app(SettingsService::class);
        $s->set('company.latitude', 14.2860);
        $s->set('company.longitude', 120.9345);
        $s->set('company.punch_radius_m', 200);
        $s->set('company.punch_max_accuracy_m', 100);
        $s->set('attendance.punch_early_minutes', 120);
        $s->set('attendance.punch_late_minutes', 240);
    }

    protected function shift(string $start = '06:00:00', string $end = '14:00:00', bool $night = false): Shift
    {
        Shift::query()->update(['is_default' => false]);

        return Shift::create([
            'name' => 'XX-T-'.substr(uniqid(), -5),
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => 60,
            'grace_minutes' => 0,
            'is_night_shift' => $night,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    protected function worker(): array
    {
        $employee = Employee::factory()->create();

        return [$employee, User::factory()->create(['employee_id' => $employee->id])];
    }

    protected function at(string $datetime): CarbonImmutable
    {
        $now = CarbonImmutable::parse($datetime);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    /** Assign $shift to $employee; $workingDays needs the working_days column (uncommitted user work), else the test skips. */
    protected function assignShift(Employee $employee, Shift $shift, ?array $workingDays = null): void
    {
        $hasColumn = Schema::hasColumn('employee_shift_assignments', 'working_days');
        if ($workingDays !== null && ! $hasColumn) {
            $this->markTestSkipped('working_days column is not committed yet; commit the working_days migration first.');
        }

        $row = [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => '2026-01-01',
            'end_date' => null,
            'created_at' => now(),
        ];
        if ($hasColumn) {
            $row['working_days'] = $workingDays === null ? null : json_encode($workingDays);
        }
        DB::table('employee_shift_assignments')->insert($row);
    }

    protected function approveOvertime(Employee $employee, string $date): OvertimeRequest
    {
        $ot = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'hours_requested' => 2,
            'reason' => 'XX-T punch rules',
        ]);
        $ot->forceFill(['status' => OvertimeStatus::Approved->value])->save();

        return $ot;
    }

    protected function holiday(string $date, string $type = 'regular'): Holiday
    {
        $h = Holiday::create(['name' => 'XX-T-'.substr(uniqid(), -5), 'date' => $date, 'type' => $type, 'is_recurring' => false]);
        Cache::put('holidays:cache_version', (int) Cache::get('holidays:cache_version', 0) + 1);

        return $h;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
