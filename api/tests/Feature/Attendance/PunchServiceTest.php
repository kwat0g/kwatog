<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchServiceTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private const LAT = 14.2860;
    private const LNG = 120.9345;

    private function punch($employee, float $lat = self::LAT, float $lng = self::LNG, float $acc = 20.0)
    {
        return app(PunchService::class)->punch($employee, $lat, $lng, $acc, '127.0.0.1', 'phpunit', null);
    }

    private function assertRejected(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected PunchRejectedException [{$code}]");
        } catch (PunchRejectedException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    public function test_in_then_out_writes_one_attendance_row(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $in = $this->punch($emp);
        $this->assertSame(PunchDirection::In, $in->direction);

        $this->at('2026-10-07 14:05:00');
        $out = $this->punch($emp);
        $this->assertSame(PunchDirection::Out, $out->direction);

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('2026-10-07', $row->date->toDateString());
        $this->assertSame('06:02', $row->time_in->format('H:i'));
        $this->assertSame('14:05', $row->time_out->format('H:i'));
        $this->assertSame($row->id, $out->attendance_id);
        $this->assertNotNull($row->status);
    }

    public function test_out_of_range_is_rejected_and_nothing_is_written(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('out_of_range', fn () => $this->punch($emp, 14.30, 120.95));
        $this->assertSame(0, AttendancePunch::query()->count());
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_poor_accuracy_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('low_accuracy', fn () => $this->punch($emp, self::LAT, self::LNG, 250.0));
    }

    public function test_second_tap_within_cooldown_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->punch($emp);
        $this->at('2026-10-07 06:02:30');
        $this->assertRejected('too_soon', fn () => $this->punch($emp));
        $this->assertSame(1, AttendancePunch::query()->count());
    }

    public function test_outside_window_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 03:00:00'); // 06:00 shift, window opens 04:00
        $this->assertRejected('outside_window', fn () => $this->punch($emp));
    }

    public function test_extra_in_after_out_keeps_first_in_and_last_out(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 12:00:00');
        $this->punch($emp);               // out (lunch)
        $this->at('2026-10-07 13:00:00');
        $in2 = $this->punch($emp);        // back in
        $this->assertSame(PunchDirection::In, $in2->direction);
        $this->at('2026-10-07 14:10:00');
        $this->punch($emp);               // final out

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));
        $this->assertSame('14:10', $row->time_out->format('H:i'));
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(4, AttendancePunch::query()->count());
    }

    public function test_stale_in_older_than_16_hours_starts_a_new_day_with_in(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-06 06:00:00');
        $this->punch($emp);               // forgot to punch out
        $this->at('2026-10-07 06:00:00');
        $next = $this->punch($emp);
        $this->assertSame(PunchDirection::In, $next->direction);
        $this->assertSame(2, Attendance::query()->where('employee_id', $emp->id)->count());
    }

    public function test_night_shift_out_after_midnight_attaches_to_start_day(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$emp] = $this->worker();
        $this->at('2026-10-07 22:05:00');
        $this->punch($emp);
        $this->at('2026-10-08 06:02:00');
        $out = $this->punch($emp);

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('2026-10-07', $row->date->toDateString());
        $this->assertSame($row->id, $out->attendance_id);
        $this->assertSame('06:02', $row->time_out->format('H:i'));
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_hr_manual_entry_and_leave_days_are_not_overwritten(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-10-07',
            'time_in' => '2026-10-07 06:30:00', 'time_out' => '2026-10-07 14:00:00',
            'status' => AttendanceStatus::Present->value, 'is_manual_entry' => true,
        ]);
        $this->assertRejected('manual_entry', fn () => $this->punch($emp));
    }

    public function test_archived_row_rejects_the_punch(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $row = Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-10-07', 'status' => AttendanceStatus::Absent->value,
        ]);
        $row->delete();
        $this->assertRejected('row_removed', fn () => $this->punch($emp));
    }

    public function test_no_shift_and_no_default_is_rejected(): void
    {
        \App\Modules\Attendance\Models\Shift::query()->delete();
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('no_shift', fn () => $this->punch($emp));
    }

    public function test_status_reports_next_action_and_tardiness(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:15:00');
        $before = app(PunchService::class)->status($emp);
        $this->assertSame('in', $before['next_action']);

        $this->punch($emp);
        $after = app(PunchService::class)->status($emp);
        $this->assertSame('out', $after['next_action']);
        $this->assertGreaterThanOrEqual(15, $after['tardiness_minutes']);
        $this->assertCount(1, $after['punches']);
    }

    public function test_on_leave_day_rejects_the_punch(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-10-07',
            'status' => AttendanceStatus::OnLeave->value, 'is_manual_entry' => false,
        ]);
        $this->assertRejected('on_leave', fn () => $this->punch($emp));
    }

    public function test_status_punch_list_covers_night_shift_lookback(): void
    {
        $this->shift('22:00:00', '06:00:00', true); // Night shift 22:00–06:00
        [$emp] = $this->worker();

        // Night shift punch in at 22:05 on 10-07
        $this->at('2026-10-07 22:05:00');
        $this->punch($emp);

        // Status check at 06:02 on 10-08 (next morning, still within 16-hour lookback)
        $this->at('2026-10-08 06:02:00');
        $status = app(PunchService::class)->status($emp);

        // Should include the punch from last night (within lookback window)
        $this->assertCount(1, $status['punches']);
        $this->assertSame('in', $status['punches'][0]['direction']);
        $this->assertStringStartsWith('2026-10-07T22:05:00', $status['punches'][0]['punched_at']);
    }
}
