<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRComputationService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class HrRestDayFlagTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift();
    }

    public function test_hr_explicit_rest_day_flag_survives_recompute(): void
    {
        [$employee] = $this->worker();

        // Create a NON-manual Attendance for Wednesday 2026-10-07
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-07',
            'time_in' => '2026-10-07 06:00:00',
            'time_out' => '2026-10-07 14:00:00',
            'status' => 'present',
            'is_manual_entry' => false,
        ]);

        // Compute it (which will compute is_rest_day based on working days)
        $computed = app(DTRComputationService::class)->computeForRecord($row);
        $computed->save();

        // Verify it starts as not a rest day (Wednesday is a working day)
        $this->assertFalse($row->fresh()->is_rest_day);
        $this->assertFalse($row->fresh()->is_manual_entry);

        // HR marks it as a rest day
        app(AttendanceService::class)->update($row, ['is_rest_day' => true]);

        // Assert both flags are set
        $updated = $row->fresh();
        $this->assertTrue($updated->is_rest_day);
        $this->assertTrue($updated->is_manual_entry);
    }

    public function test_hr_update_with_remarks_only_does_not_set_is_manual_entry(): void
    {
        [$employee] = $this->worker();

        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-07',
            'time_in' => '2026-10-07 06:00:00',
            'time_out' => '2026-10-07 14:00:00',
            'status' => 'present',
            'is_manual_entry' => false,
        ]);

        // Compute it
        $computed = app(DTRComputationService::class)->computeForRecord($row);
        $computed->save();

        // HR updates just the remarks
        app(AttendanceService::class)->update($row, ['remarks' => 'test remark']);

        // is_manual_entry should remain false
        $updated = $row->fresh();
        $this->assertSame('test remark', $updated->remarks);
        $this->assertFalse($updated->is_manual_entry);
    }
}
