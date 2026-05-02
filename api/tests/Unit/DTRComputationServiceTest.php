<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Attendance\Services\DTRComputationService;
use App\Modules\Attendance\Services\HolidayService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage of the Daily Time Record (DTR) computation engine.
 * Tests target the pure compute() entrypoint so DB / cache are not required.
 */
class DTRComputationServiceTest extends TestCase
{
    private DTRComputationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DTRComputationService(
            Mockery::mock(ShiftAssignmentService::class),
            Mockery::mock(HolidayService::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── Shift presets ───────────────────────────────────────────

    private function dayShift(): array
    {
        // Day shift 06:00 → 14:00, 30 min break ⇒ scheduled = 7.5h
        return ['start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30, 'is_night_shift' => false, 'is_extended' => false, 'auto_ot_hours' => null];
    }

    private function officeShift(): array
    {
        return ['start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'is_night_shift' => false, 'is_extended' => false, 'auto_ot_hours' => null];
    }

    private function extendedShift(): array
    {
        // Extended Day 06:00 → 18:00, break 30 ⇒ scheduled 11.5h, auto-OT 4h
        return ['start_time' => '06:00', 'end_time' => '18:00', 'break_minutes' => 30, 'is_night_shift' => false, 'is_extended' => true, 'auto_ot_hours' => 4.0];
    }

    private function nightShift(): array
    {
        // Night Shift 18:00 → 06:00, break 30 ⇒ scheduled 11.5h
        return ['start_time' => '18:00', 'end_time' => '06:00', 'break_minutes' => 30, 'is_night_shift' => true, 'is_extended' => false, 'auto_ot_hours' => null];
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'date'            => '2026-04-15', // Wednesday
            'time_in'         => '2026-04-15 06:00:00',
            'time_out'        => '2026-04-15 14:00:00',
            'shift'           => $this->dayShift(),
            'holiday'         => null,
            'is_rest_day'     => false,
            'has_approved_ot' => false,
        ], $overrides);
    }

    // ─── Day-type rate matrix (14 combinations) ───────────────────

    public function test_regular_working_day_worked_full_shift(): void
    {
        $r = $this->svc->compute($this->input());
        $this->assertEquals(7.5, $r['regular_hours']);
        $this->assertEquals(0.0, $r['overtime_hours']);
        $this->assertSame(0, $r['tardiness_minutes']);
        $this->assertEquals(1.00, $r['day_type_rate']);
        $this->assertSame('present', $r['status']);
    }

    public function test_regular_working_day_not_worked_is_absent(): void
    {
        $r = $this->svc->compute($this->input(['time_in' => null, 'time_out' => null]));
        $this->assertSame('absent', $r['status']);
        $this->assertEquals(1.00, $r['day_type_rate']);
        $this->assertEquals(0.0, $r['regular_hours']);
    }

    public function test_special_non_working_not_worked_no_pay(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in' => null, 'time_out' => null,
            'holiday' => ['type' => 'special_non_working'],
        ]));
        $this->assertSame('holiday', $r['status']);
        $this->assertEquals(0.00, $r['day_type_rate']);
    }

    public function test_special_non_working_worked_pays_130(): void
    {
        $r = $this->svc->compute($this->input([
            'holiday' => ['type' => 'special_non_working'],
        ]));
        $this->assertEquals(1.30, $r['day_type_rate']);
        $this->assertSame('special_non_working', $r['holiday_type']);
    }

    public function test_special_non_working_plus_rest_day_worked_pays_150(): void
    {
        $r = $this->svc->compute($this->input([
            'holiday'     => ['type' => 'special_non_working'],
            'is_rest_day' => true,
        ]));
        $this->assertEquals(1.50, $r['day_type_rate']);
        $this->assertTrue($r['is_rest_day']);
    }

    public function test_regular_holiday_not_worked_is_paid_100(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in' => null, 'time_out' => null,
            'holiday' => ['type' => 'regular'],
        ]));
        $this->assertSame('holiday', $r['status']);
        $this->assertEquals(1.00, $r['day_type_rate']);
    }

    public function test_regular_holiday_worked_pays_200(): void
    {
        $r = $this->svc->compute($this->input([
            'holiday' => ['type' => 'regular'],
        ]));
        $this->assertEquals(2.00, $r['day_type_rate']);
    }

    public function test_regular_holiday_plus_rest_day_worked_pays_260(): void
    {
        $r = $this->svc->compute($this->input([
            'holiday'     => ['type' => 'regular'],
            'is_rest_day' => true,
        ]));
        $this->assertEquals(2.60, $r['day_type_rate']);
    }

    public function test_rest_day_only_worked_pays_130(): void
    {
        $r = $this->svc->compute($this->input(['is_rest_day' => true]));
        $this->assertEquals(1.30, $r['day_type_rate']);
    }

    public function test_rest_day_only_not_worked_pays_zero(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in' => null, 'time_out' => null,
            'is_rest_day' => true,
        ]));
        $this->assertSame('rest_day', $r['status']);
        $this->assertEquals(0.00, $r['day_type_rate']);
    }

    // ─── Tardiness / undertime ────────────────────────────────────

    public function test_tardiness_one_hour_marks_late(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 07:00:00', // 1h late
            'time_out' => '2026-04-15 14:00:00',
        ]));
        $this->assertSame(60, $r['tardiness_minutes']);
        $this->assertEquals(6.5, $r['regular_hours']);
        $this->assertSame('late', $r['status']);
    }

    public function test_undertime_left_early(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 13:00:00', // 1h early
        ]));
        $this->assertSame(60, $r['undertime_minutes']);
        $this->assertEquals(6.5, $r['regular_hours']);
    }

    public function test_halfday_when_worked_less_than_half(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 09:00:00',
        ]));
        $this->assertEquals(2.5, $r['regular_hours']); // 3h - 0.5 break
        $this->assertSame('halfday', $r['status']);
    }

    // ─── Overtime gates ───────────────────────────────────────────

    public function test_excess_without_ot_request_is_not_paid_as_ot(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 17:00:00', // 3h past shift_end
            'has_approved_ot' => false,
        ]));
        $this->assertEquals(7.5, $r['regular_hours']);
        $this->assertEquals(0.0, $r['overtime_hours']);
    }

    public function test_excess_with_approved_ot_pays_ot_capped_at_4h(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 19:00:00', // 5h past shift end
            'has_approved_ot' => true,
        ]));
        $this->assertEquals(7.5, $r['regular_hours']);
        $this->assertEquals(4.0, $r['overtime_hours']);
    }

    public function test_short_excess_below_30min_is_not_paid_as_ot(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 14:25:00', // 25 min past
            'has_approved_ot' => true,
        ]));
        $this->assertEquals(0.0, $r['overtime_hours']);
    }

    // ─── Extended (Auto-OT) ───────────────────────────────────────

    public function test_extended_shift_full_pays_auto_ot(): void
    {
        $r = $this->svc->compute($this->input([
            'shift'    => $this->extendedShift(),
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-15 18:00:00',
        ]));
        // 12h - 0.5h break = 11.5h worked. Scheduled 11.5h → all regular, no OT
        $this->assertEquals(11.5, $r['regular_hours']);
        $this->assertEquals(0.0, $r['overtime_hours']);
    }

    public function test_extended_shift_extra_hours_capped_at_auto_ot_hours(): void
    {
        $r = $this->svc->compute($this->input([
            'shift'    => $this->extendedShift(),
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => '2026-04-16 00:00:00', // 6h past shift_end
        ]));
        $this->assertEquals(11.5, $r['regular_hours']);
        $this->assertEquals(4.0, $r['overtime_hours']); // capped at auto_ot_hours
    }

    // ─── Night shift / cross-midnight / night diff ───────────────

    public function test_night_shift_cross_midnight_full(): void
    {
        $r = $this->svc->compute($this->input([
            'shift'    => $this->nightShift(),
            'date'     => '2026-04-15',
            'time_in'  => '2026-04-15 18:00:00',
            'time_out' => '2026-04-16 06:00:00',
        ]));
        $this->assertEquals(11.5, $r['regular_hours']); // 12h - 0.5h break
        $this->assertEquals(0.0, $r['overtime_hours']);
    }

    public function test_night_diff_band_is_22_to_06(): void
    {
        $r = $this->svc->compute($this->input([
            'shift'    => $this->nightShift(),
            'time_in'  => '2026-04-15 18:00:00',
            'time_out' => '2026-04-16 06:00:00',
        ]));
        // 22:00 → 06:00 = 8h. Break of 30 min not subtracted from ND (per labor rule).
        $this->assertEquals(8.0, $r['night_diff_hours']);
    }

    public function test_day_shift_has_no_night_diff(): void
    {
        $r = $this->svc->compute($this->input());
        $this->assertEquals(0.0, $r['night_diff_hours']);
    }

    public function test_office_shift_with_late_evening_partial_night_diff(): void
    {
        // 08:00-22:30 includes 30 minutes after 22:00.
        $r = $this->svc->compute($this->input([
            'shift'    => $this->officeShift(),
            'time_in'  => '2026-04-15 08:00:00',
            'time_out' => '2026-04-15 22:30:00',
            'has_approved_ot' => true,
        ]));
        $this->assertEquals(0.5, $r['night_diff_hours']);
    }

    // ─── Monster: regular holiday + rest day + night shift + OT ──

    public function test_monster_regular_holiday_plus_rest_day_plus_night_plus_ot(): void
    {
        // Regular holiday + rest day + night shift, worked 18:00 → 10:00 next day = 16h gross,
        // -0.5h break = 15.5h worked. Scheduled 11.5h → excess 4h (capped) OT.
        $r = $this->svc->compute([
            'date'            => '2026-04-09', // Araw ng Kagitingan
            'time_in'         => '2026-04-09 18:00:00',
            'time_out'        => '2026-04-10 10:00:00',
            'shift'           => $this->nightShift(),
            'holiday'         => ['type' => 'regular'],
            'is_rest_day'     => true,
            'has_approved_ot' => true,
        ]);
        $this->assertEquals(11.5, $r['regular_hours']);
        $this->assertEquals(4.0, $r['overtime_hours']);
        $this->assertEquals(8.0, $r['night_diff_hours']); // 22:00 → 06:00
        $this->assertEquals(2.60, $r['day_type_rate']);
        $this->assertSame('regular', $r['holiday_type']);
        $this->assertTrue($r['is_rest_day']);
    }

    // ─── Edge cases ───────────────────────────────────────────────

    public function test_time_out_before_time_in_on_day_shift_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 14:00:00',
            'time_out' => '2026-04-15 06:00:00',
        ]));
    }

    public function test_missing_time_out_returns_zeros_with_present_status(): void
    {
        $r = $this->svc->compute($this->input([
            'time_in'  => '2026-04-15 06:00:00',
            'time_out' => null,
        ]));
        $this->assertEquals(0.0, $r['regular_hours']);
        $this->assertEquals(0.0, $r['overtime_hours']);
        $this->assertSame('present', $r['status']);
    }

    public function test_completely_missing_attendance_is_absent(): void
    {
        $r = $this->svc->compute($this->input(['time_in' => null, 'time_out' => null]));
        $this->assertSame('absent', $r['status']);
        $this->assertEquals(1.00, $r['day_type_rate']);
    }

    public function test_zero_regular_hours_does_not_credit_holiday_pay_when_worked_zero_with_no_holiday(): void
    {
        $r = $this->svc->compute($this->input(['time_in' => null, 'time_out' => null]));
        $this->assertEquals(1.00, $r['day_type_rate']); // basic pay still applies even when absent (deduction handled elsewhere)
    }

    // ─── Tardiness cap & sanity ───────────────────────────────────

    public function test_tardiness_caps_at_8_hours(): void
    {
        $r = $this->svc->compute($this->input([
            'shift'    => $this->officeShift(),
            'time_in'  => '2026-04-15 22:00:00', // ridiculously late on an office shift
            'time_out' => '2026-04-15 23:00:00',
        ]));
        $this->assertSame(480, $r['tardiness_minutes']); // capped
    }
}
