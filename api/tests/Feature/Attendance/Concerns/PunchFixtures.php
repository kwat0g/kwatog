<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
