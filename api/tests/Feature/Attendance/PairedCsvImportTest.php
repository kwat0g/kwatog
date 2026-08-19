<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The paired-CSV attendance import — `POST /attendances/import`.
 *
 * This path had NO test. Only the raw-punch sessionizer path was covered, which
 * is why the following survived: `DTRImportService::import()` parsed time_out as
 *
 *     Carbon::parse($timeOut, $date)
 *
 * where Carbon's second parameter is the TIMEZONE, not a base date. Every
 * non-empty time_out therefore raised
 * `Carbon\Exceptions\InvalidTimeZoneException: Unknown or bad timezone (…)`,
 * which the per-row `catch (Throwable)` turned into a silent `skipped`. The
 * endpoint discarded every row that recorded when someone left.
 *
 * The corrective branch immediately below it — reparsing `HH:mm` against the
 * row's date — was already correct and already unreachable, because the throwing
 * call ran first.
 */
class PairedCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $no = 'OGM-2026-0001'): Employee
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD-'.substr(uniqid(), -4)]);
        $pos = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no' => $no,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'mobile_number' => '09171234567',
            'email' => 'pci_'.substr(uniqid(), -5).'@example.com',
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
        $path = tempnam(sys_get_temp_dir(), 'paired').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'dtr.csv', 'text/csv', null, true);
    }

    public function test_a_row_carrying_an_hhmm_time_out_imports(): void
    {
        $emp = $this->employee();

        $result = app(DTRImportService::class)->import($this->csv(
            "employee_no,date,time_in,time_out\n".
            "{$emp->employee_no},2026-06-01,08:00,17:00\n"
        ));

        $this->assertSame(1, $result['imported'], 'a row with a time_out must import: '.json_encode($result['errors']));
        $this->assertSame(0, $result['skipped']);

        $row = Attendance::query()
            ->where('employee_id', $emp->id)
            ->where('date', '2026-06-01')
            ->firstOrFail();

        $this->assertNotNull($row->time_out, 'the departure time must be persisted, not dropped');
        $this->assertSame('17:00', $row->time_out->format('H:i'));
    }

    public function test_a_row_carrying_a_full_datetime_time_out_imports(): void
    {
        $emp = $this->employee();

        $result = app(DTRImportService::class)->import($this->csv(
            "employee_no,date,time_in,time_out\n".
            "{$emp->employee_no},2026-06-02,08:00,2026-06-02 17:30:00\n"
        ));

        $this->assertSame(1, $result['imported'], 'a full-datetime time_out must import: '.json_encode($result['errors']));
        $this->assertSame('17:30', Attendance::query()
            ->where('employee_id', $emp->id)
            ->where('date', '2026-06-02')
            ->firstOrFail()
            ->time_out
            ->format('H:i'));
    }

    public function test_an_inverted_day_shift_row_is_refused_for_the_real_reason(): void
    {
        // Both times land on the row's own date, so an inversion is always under
        // 24h and DTRComputationService's existing guard is sufficient: a night
        // shift gets addDay(), a day shift is refused. This pins that the row is
        // rejected by THAT validation and not by an accidental timezone error —
        // before the fix, every row failed for the wrong reason.
        $emp = $this->employee();

        $result = app(DTRImportService::class)->import($this->csv(
            "employee_no,date,time_in,time_out\n".
            "{$emp->employee_no},2026-06-03,17:00,09:00\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString(
            'must be after time in',
            $result['errors'][0]['message'],
            'the refusal must name the real problem, not a timezone'
        );
    }
}
