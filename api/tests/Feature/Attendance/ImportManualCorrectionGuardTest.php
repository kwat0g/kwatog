<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AT-02 — biometric re-import must not clobber manual corrections.
 *
 * Both import paths resolved the existing employee-day row via
 * openDayRecord() and then overwrote time_in/time_out and reset
 * is_manual_entry to false. Normal-use breakage: HR corrects Monday's
 * missing punch on Tuesday, the same biometric file is re-dropped
 * Wednesday, and the correction is gone — the audit trail survives via
 * HasAuditLog, the pay does not.
 *
 * The guard: a stored manual row is left untouched unless the incoming
 * punches already equal the stored ones (idempotent replay). Every other
 * touch — overwrite, removal, or only filling an empty field — is skipped
 * and reported per row.
 */
class ImportManualCorrectionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $dept = Department::create(['name' => 'Guard '.substr(uniqid(), -4), 'code' => 'GU-'.substr(uniqid(), -4)]);
        $pos = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no' => 'OGM-T-'.substr(uniqid(), -5),
            'first_name' => 'Manu',
            'last_name' => 'Ally Corrected',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'mobile_number' => '09171234567',
            'email' => 'mc_'.substr(uniqid(), -5).'@example.com',
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

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'guard').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'dtr.csv', 'text/csv', null, true);
    }

    private function day(Employee $emp, string $date): Attendance
    {
        return Attendance::query()
            ->where('employee_id', $emp->id)
            ->where('date', $date)
            ->firstOrFail();
    }

    private function correctManually(Employee $emp, string $date, string $timeIn, ?string $timeOut): void
    {
        $row = $this->day($emp, $date);
        $row->fill(['time_in' => $timeIn, 'time_out' => $timeOut]);
        $row->is_manual_entry = true;
        $row->save();
    }

    public function test_reimporting_the_same_file_leaves_a_manual_correction_untouched(): void
    {
        $emp = $this->employee();
        $svc = app(DTRImportService::class);
        $file = $this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-06-01,08:00,17:00\n"
        );

        $this->assertSame(1, $svc->import($file)['imported'], 'the Monday drop must import');

        $this->correctManually($emp, '2026-06-01', '2026-06-01 09:30:00', '2026-06-01 18:30:00');

        $second = $svc->import($file);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $second['skipped_manual'], 'the summary must count the guarded day');

        $entry = $second['errors'][0];
        $this->assertSame(2, $entry['row']);
        $this->assertSame('skipped_manual', $entry['outcome']);
        $this->assertSame($emp->employee_no, $entry['employee_no']);
        $this->assertSame('2026-06-01', $entry['date']);
        $this->assertStringContainsString('Manually corrected attendance', $entry['message']);
        $this->assertStringContainsString('2026-06-01', $entry['message']);

        $row = $this->day($emp, '2026-06-01')->fresh();
        $this->assertSame('09:30', $row->time_in->format('H:i'), 'the correction must survive the re-import');
        $this->assertSame('18:30', $row->time_out->format('H:i'));
        $this->assertTrue((bool) $row->is_manual_entry, 'the provenance flag must not be reset');
    }

    public function test_a_manual_row_the_import_would_only_fill_is_skipped_too(): void
    {
        $emp = $this->employee();

        // HR records the missing day with only the in-punch; the out-punch
        // never registered on the biometric.
        app(AttendanceService::class)->create([
            'employee_id' => $emp->id,
            'date' => '2026-06-02',
            'time_in' => '2026-06-02 08:00:00',
        ]);
        $this->assertTrue((bool) $this->day($emp, '2026-06-02')->is_manual_entry);

        $result = app(DTRImportService::class)->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-06-02,08:00,17:00\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped_manual']);
        $this->assertSame('skipped_manual', $result['errors'][0]['outcome']);

        $row = $this->day($emp, '2026-06-02')->fresh();
        $this->assertNull($row->time_out, 'filling an empty field is still a touch of a manual row');
        $this->assertTrue((bool) $row->is_manual_entry);
    }

    public function test_an_exact_replay_of_a_manual_row_is_a_noop_success(): void
    {
        $emp = $this->employee();
        $svc = app(DTRImportService::class);
        $file = $this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-06-03,08:00,17:00\n"
        );

        $this->assertSame(1, $svc->import($file)['imported']);

        // HR confirms the day; the correction agrees with the biometric file.
        $this->correctManually($emp, '2026-06-03', '2026-06-03 08:00:00', '2026-06-03 17:00:00');

        $second = $svc->import($file);

        $this->assertSame(1, $second['imported'], 'an exact replay is a success, not a skip');
        $this->assertSame(0, $second['skipped']);
        $this->assertSame(0, $second['skipped_manual']);
        $this->assertSame([], $second['errors']);

        $row = $this->day($emp, '2026-06-03')->fresh();
        $this->assertSame('08:00', $row->time_in->format('H:i'));
        $this->assertSame('17:00', $row->time_out->format('H:i'));
        $this->assertTrue((bool) $row->is_manual_entry, 'nothing to clobber, so nothing is written — flag included');
    }

    public function test_an_exact_replay_of_an_untouched_imported_row_still_succeeds(): void
    {
        $emp = $this->employee();
        $svc = app(DTRImportService::class);
        $file = $this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-06-04,08:00,17:00\n"
        );

        $this->assertSame(1, $svc->import($file)['imported']);
        $second = $svc->import($file);

        $this->assertSame(1, $second['imported']);
        $this->assertSame(0, $second['skipped']);
        $this->assertSame([], $second['errors']);
        $this->assertSame(1, Attendance::query()->where('employee_id', $emp->id)->count());

        $row = $this->day($emp, '2026-06-04');
        $this->assertSame('08:00', $row->time_in->format('H:i'));
        $this->assertSame('17:00', $row->time_out->format('H:i'));
        $this->assertFalse((bool) $row->is_manual_entry);
    }

    public function test_a_guarded_day_does_not_fail_the_rest_of_the_file(): void
    {
        $emp = $this->employee();
        $svc = app(DTRImportService::class);

        $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n{$emp->employee_no},2026-06-05,08:00,17:00\n"
        ));
        $this->correctManually($emp, '2026-06-05', '2026-06-05 09:30:00', '2026-06-05 18:30:00');

        $result = $svc->import($this->csv(
            "employee_no,date,time_in,time_out\n".
            "{$emp->employee_no},2026-06-05,08:00,17:00\n".
            "{$emp->employee_no},2026-06-06,06:00,15:00\n"
        ));

        $this->assertSame(1, $result['imported'], 'the fresh day must still import: '.json_encode($result['errors']));
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['skipped_manual']);

        $this->assertSame('06:00', $this->day($emp, '2026-06-06')->time_in->format('H:i'));
        $this->assertSame('09:30', $this->day($emp, '2026-06-05')->fresh()->time_in->format('H:i'));
    }

    public function test_raw_punch_reimport_leaves_a_manual_correction_untouched(): void
    {
        $emp = $this->employee();
        $svc = app(DTRImportService::class);
        $file = $this->csv(
            "employee_no,timestamp,direction\n".
            "{$emp->employee_no},2026-06-07 08:00:00,in\n".
            "{$emp->employee_no},2026-06-07 17:00:00,out\n"
        );

        $this->assertSame(1, $svc->importRawPunches($file)['imported'], 'the first drop must import');

        $this->correctManually($emp, '2026-06-07', '2026-06-07 09:30:00', '2026-06-07 18:30:00');

        $second = $svc->importRawPunches($file);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $second['skipped_manual']);

        $entry = $second['errors'][0];
        $this->assertSame('skipped_manual', $entry['outcome']);
        $this->assertSame($emp->employee_no, $entry['employee_no']);
        $this->assertSame('2026-06-07', $entry['date']);

        $row = $this->day($emp, '2026-06-07')->fresh();
        $this->assertSame('09:30', $row->time_in->format('H:i'));
        $this->assertSame('18:30', $row->time_out->format('H:i'));
        $this->assertTrue((bool) $row->is_manual_entry);
    }
}
