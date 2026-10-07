<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchRulesTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function punch(Employee $employee)
    {
        return app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20.0, '127.0.0.1', 'phpunit', null);
    }

    private function assertRejected(string $code, callable $fn): PunchRejectedException
    {
        try {
            $fn();
            $this->fail("Expected PunchRejectedException [{$code}]");
        } catch (PunchRejectedException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift(); // 06:00–14:00 default shift
    }

    public function test_early_out_is_refused_until_shift_end_minus_grace(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);

        $this->at('2026-10-07 13:44:00');
        $e = $this->assertRejected('too_early_out', fn () => $this->punch($emp));
        $this->assertStringContainsString('1:45 PM', $e->getMessage());

        $this->at('2026-10-07 13:45:00');
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_a_sixty_one_second_in_out_is_refused(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 06:01:01');

        $this->assertRejected('too_early_out', fn () => $this->punch($emp));
        $this->assertNull(Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->time_out);
    }

    public function test_a_post_shift_cycle_still_needs_the_minimum_work_time(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:10:00');
        $this->punch($emp); // second IN is exempt from the late-in cutoff

        $this->at('2026-10-07 14:20:00');
        $e = $this->assertRejected('min_work', fn () => $this->punch($emp));
        $this->assertStringContainsString('2:40 PM', $e->getMessage());
        $this->assertStringContainsString('30 minutes', $e->getMessage());

        $this->at('2026-10-07 14:40:00');
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_the_first_in_after_the_cutoff_is_refused(): void
    {
        [$late] = $this->worker();
        [$ok] = $this->worker();

        $this->at('2026-10-07 10:01:00'); // 06:00 + 240 min = 10:00
        $this->assertRejected('too_late_in', fn () => $this->punch($late));
        $this->assertSame(0, Attendance::query()->where('employee_id', $late->id)->count());

        $this->at('2026-10-07 10:00:00');
        $this->assertSame(PunchDirection::In, $this->punch($ok)->direction);
    }

    public function test_sunday_work_needs_an_approved_overtime_request_and_a_retry_then_succeeds(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-11 06:05:00'); // Sunday
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $ot = $this->approveOvertime($emp, '2026-10-11');
        $ot->forceFill(['status' => 'pending'])->save(); // pending is not authorization
        $this->at('2026-10-11 06:07:00');
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $ot->forceFill(['status' => 'approved'])->save();
        $this->at('2026-10-11 06:09:00');
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_a_holiday_on_a_working_day_needs_authorization(): void
    {
        [$emp] = $this->worker();
        $this->holiday('2026-10-07', 'special_non_working'); // Wednesday
        $this->at('2026-10-07 06:05:00');
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $this->approveOvertime($emp, '2026-10-07');
        $this->at('2026-10-07 06:07:00');
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_authorized_rest_day_work_only_needs_the_minimum_work_time(): void
    {
        [$emp] = $this->worker();
        $this->approveOvertime($emp, '2026-10-11');
        $this->at('2026-10-11 06:05:00');
        $this->punch($emp);

        $this->at('2026-10-11 06:20:00');
        $this->assertRejected('min_work', fn () => $this->punch($emp));

        $this->at('2026-10-11 06:40:00'); // long before shift end − grace, but past 30 min
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_a_night_shift_starting_saturday_is_judged_by_its_start_day(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$emp] = $this->worker();

        $this->at('2026-10-10 22:05:00'); // Saturday (working day by default)
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);

        $this->at('2026-10-11 06:02:00'); // Sunday morning: row date is Saturday
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
        $this->assertSame('2026-10-10', Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->date->toDateString());
    }

    public function test_a_monday_to_friday_worker_cannot_punch_on_saturday_without_authorization(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [1, 2, 3, 4, 5]);
        $this->at('2026-10-10 06:05:00');

        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));
    }

    public function test_a_sunday_to_thursday_worker_can_punch_on_sunday_as_a_normal_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [7, 1, 2, 3, 4]);
        $this->at('2026-10-11 06:05:00');

        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_status_reports_the_earliest_out_the_check_will_enforce(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertNull(app(PunchService::class)->status($emp)['earliest_out']);

        $this->punch($emp);
        $status = app(PunchService::class)->status($emp);

        $this->assertSame('13:45', CarbonImmutable::parse($status['earliest_out'])->format('H:i'));
    }
}
