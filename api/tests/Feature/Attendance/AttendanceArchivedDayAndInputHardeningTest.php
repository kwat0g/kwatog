<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Requests\StoreAttendanceRequest;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * M018-F18/F23/F14 — the three ways a well-formed operator action used to reach
 * the client as an unhandled fault instead of an actionable message.
 *
 * The common root is `attendances_employee_id_date_unique`: it is a plain UNIQUE
 * constraint on (employee_id, date), NOT partial on `deleted_at IS NULL`, so an
 * ARCHIVED attendance row still owns that employee-day. Because the default
 * Eloquent scope hides trashed rows, every write path looked up the day, saw
 * nothing, built a fresh row, and died on INSERT with SQLSTATE 23505 — which the
 * import's per-row `catch (Throwable) → $e->getMessage()` then published to the
 * API client as the complete INSERT statement, table and bound values included.
 *
 * Archiving is an action the SPA exposes on the attendance list, so this is a
 * routine sequence, not a corner case.
 */
class AttendanceArchivedDayAndInputHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $dept = Department::create(['name' => 'Arch '.substr(uniqid(), -4), 'code' => 'AR-'.substr(uniqid(), -4)]);
        $pos = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no' => 'OGM-T-'.substr(uniqid(), -5),
            'first_name' => 'Archie',
            'last_name' => 'Ved',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'mobile_number' => '09171234567',
            'email' => 'arch_'.substr(uniqid(), -5).'@example.com',
            'emergency_contact_name' => 'Maria',
            'emergency_contact_phone' => '09181234567',
            'department_id' => $dept->id,
            'position_id' => $pos->id,
            'employment_type' => 'regular',
            'pay_type' => 'semi_monthly',
            'date_hired' => '2025-01-01',
            'semi_monthly_rate' => '6600.00',
            'status' => 'active',
        ]);
    }

    /** Migration 0322 already seeds one default shift, and a partial index forbids a second. */
    private function defaultShift(): Shift
    {
        return Shift::query()->where('is_default', true)->firstOrFail();
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'arch').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'dtr.csv', 'text/csv', null, true);
    }

    public function test_reimporting_an_archived_day_reports_the_real_remedy_and_no_sql(): void
    {
        $emp = $this->employee();
        $this->defaultShift();
        $svc = app(DTRImportService::class);

        $first = $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-04-15,06:00,14:00\n"
        ));
        $this->assertSame(1, $first['imported'], json_encode($first['errors']));

        Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->delete();

        $second = $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-04-15,06:05,14:10\n"
        ));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $message = $second['errors'][0]['message'];
        $this->assertStringContainsString('archived attendance record', $message);
        $this->assertStringContainsString('2026-04-15', $message);
        // The disclosure regression: none of the SQL may survive into the payload.
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('insert into', $message);
        $this->assertStringNotContainsString('attendances_employee_id_date_unique', $message);
    }

    public function test_raw_punch_import_of_an_archived_day_reports_the_same_way(): void
    {
        $emp = $this->employee();
        $this->defaultShift();
        $svc = app(DTRImportService::class);

        $first = $svc->importRawPunches($this->csv(
            "employee_no,timestamp\n{$emp->employee_no},2026-04-15 06:00:00\n{$emp->employee_no},2026-04-15 14:00:00\n"
        ));
        $this->assertSame(1, $first['imported'], json_encode($first['errors']));

        Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->delete();

        $second = $svc->importRawPunches($this->csv(
            "employee_no,timestamp\n{$emp->employee_no},2026-04-15 06:05:00\n{$emp->employee_no},2026-04-15 14:10:00\n"
        ));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertStringContainsString('archived attendance record', $second['errors'][0]['message']);
        $this->assertStringNotContainsString('SQLSTATE', $second['errors'][0]['message']);
    }

    public function test_manual_create_over_an_archived_day_is_a_business_rule_not_a_500(): void
    {
        $emp = $this->employee();
        $shift = $this->defaultShift();
        $svc = app(AttendanceService::class);

        $a = $svc->create([
            'employee_id' => $emp->id, 'date' => '2026-04-15', 'shift_id' => $shift->id,
            'time_in' => '2026-04-15 06:00:00', 'time_out' => '2026-04-15 14:00:00',
        ]);
        $svc->delete($a);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/archived attendance record already exists for 2026-04-15/');

        $svc->create([
            'employee_id' => $emp->id, 'date' => '2026-04-15', 'shift_id' => $shift->id,
            'time_in' => '2026-04-15 06:05:00', 'time_out' => '2026-04-15 14:10:00',
        ]);
    }

    public function test_an_unarchived_existing_day_is_still_updated_in_place_by_import(): void
    {
        $emp = $this->employee();
        $this->defaultShift();
        $svc = app(DTRImportService::class);

        $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-04-15,06:00,14:00\n"
        ));
        $second = $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-04-15,06:00,15:00\n"
        ));

        $this->assertSame(1, $second['imported'], json_encode($second['errors']));
        $this->assertSame(1, Attendance::query()->where('employee_id', $emp->id)->count());
        $this->assertSame('15:00', Attendance::query()
            ->where('employee_id', $emp->id)->firstOrFail()->time_out->format('H:i'));
    }

    public function test_attendance_list_survives_an_arbitrary_sort_direction(): void
    {
        // Query\Builder::orderBy() throws InvalidArgumentException on anything
        // other than asc/desc, so an unvalidated `direction` was a 500.
        $page = app(AttendanceService::class)->list(['sort' => 'date', 'direction' => 'nonsense; --']);

        $this->assertSame(0, $page->total());
    }

    public function test_a_datetime_shaped_date_is_a_validation_error_not_a_parse_fault(): void
    {
        $rules = (new StoreAttendanceRequest)->rules();

        $this->assertTrue(
            validator(['date' => '2026-04-15 12:00:00'], ['date' => $rules['date']])->fails(),
            'a datetime string must not satisfy the attendance date rule',
        );
        $this->assertFalse(
            validator(['date' => '2026-04-15'], ['date' => $rules['date']])->fails(),
            'a plain calendar date must still be accepted',
        );
    }
}
