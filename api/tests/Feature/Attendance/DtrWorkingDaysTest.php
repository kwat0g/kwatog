<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\DTRComputationService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class DtrWorkingDaysTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function compute(Employee $emp, string $date, array $attrs = []): Attendance
    {
        $a = new Attendance($attrs + [
            'employee_id' => $emp->id,
            'date' => $date,
            'time_in' => "{$date} 06:00:00",
            'time_out' => "{$date} 14:00:00",
        ]);

        return app(DTRComputationService::class)->computeForRecord($a);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift();
    }

    public function test_without_working_days_sunday_is_rest_and_saturday_is_not(): void
    {
        [$emp] = $this->worker();

        $this->assertFalse($this->compute($emp, '2026-10-10')->is_rest_day); // Saturday
        $this->assertTrue($this->compute($emp, '2026-10-11')->is_rest_day);  // Sunday
    }

    public function test_a_monday_to_friday_worker_has_saturday_as_a_rest_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [1, 2, 3, 4, 5]);

        $row = $this->compute($emp, '2026-10-10');

        $this->assertTrue($row->is_rest_day);
        $this->assertGreaterThan(1.0, (float) $row->day_type_rate);
    }

    public function test_a_sunday_to_thursday_worker_has_sunday_as_a_normal_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [7, 1, 2, 3, 4]);

        $this->assertFalse($this->compute($emp, '2026-10-11')->is_rest_day);
    }

    public function test_hr_manual_entry_keeps_its_explicit_rest_day_flag(): void
    {
        [$emp] = $this->worker();

        $row = $this->compute($emp, '2026-10-07', ['is_manual_entry' => true, 'is_rest_day' => true]);

        $this->assertTrue($row->is_rest_day);
    }

    public function test_a_non_manual_row_does_not_inherit_a_stale_rest_day_flag(): void
    {
        [$emp] = $this->worker();

        $row = $this->compute($emp, '2026-10-07', ['is_manual_entry' => false, 'is_rest_day' => true]);

        $this->assertFalse($row->is_rest_day);
    }
}
