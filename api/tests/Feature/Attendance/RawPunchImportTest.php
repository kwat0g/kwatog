<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * OGAMI-011 — raw biometric punch-event import (sessionizer path).
 *
 * Real biometric exports are raw IN/OUT punch events, not pre-paired day rows.
 * importRawPunches() pairs them into day records (first-in / last-out), dedupes
 * duplicate punches, and blocks writing into a finalized payroll period.
 */
class RawPunchImportTest extends TestCase
{
    use RefreshDatabase;

    private DTRImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DTRImportService::class);
    }

    private function employee(string $no = 'OGM-2026-0001'): Employee
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no'          => $no,
            'first_name'           => 'Juan',
            'last_name'            => 'Dela Cruz',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'street_address'       => '123 Main',
            'city'                 => 'Dasmariñas',
            'province'             => 'Cavite',
            'mobile_number'        => '09171234567',
            'email'                => 'jdc_'.substr(uniqid(), -5).'@example.com',
            'emergency_contact_name'  => 'Maria',
            'emergency_contact_phone' => '09181234567',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'daily',
            'date_hired'           => '2025-01-01',
            'daily_rate'           => '600.00',
            'status'               => 'active',
        ]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'punch').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'punches.csv', 'text/csv', null, true);
    }

    public function test_raw_punches_sessionize_into_one_day_record(): void
    {
        $emp = $this->employee();

        // Four punches in one day → first-in 08:00, last-out 17:05.
        $file = $this->csv(
            "employee_no,timestamp,direction\n".
            "{$emp->employee_no},2026-04-02 08:00:00,in\n".
            "{$emp->employee_no},2026-04-02 12:00:00,out\n".
            "{$emp->employee_no},2026-04-02 12:45:00,in\n".
            "{$emp->employee_no},2026-04-02 17:05:00,out\n"
        );

        $result = $this->svc->importRawPunches($file);

        $this->assertSame(1, $result['imported'], 'four punches collapse to one day record');
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $emp->id,
            'date'        => '2026-04-02',
        ]);
    }

    public function test_duplicate_punches_are_deduped(): void
    {
        $emp = $this->employee();

        $file = $this->csv(
            "employee_no,timestamp,direction\n".
            "{$emp->employee_no},2026-04-03 08:00:00,in\n".
            "{$emp->employee_no},2026-04-03 08:00:00,in\n". // exact duplicate
            "{$emp->employee_no},2026-04-03 17:00:00,out\n"
        );

        $result = $this->svc->importRawPunches($file);

        $this->assertGreaterThanOrEqual(1, $result['deduped'], 'identical punch is deduped');
        $this->assertSame(1, $result['imported']);
    }

    public function test_import_into_finalized_period_is_blocked(): void
    {
        $emp = $this->employee();

        $roleId = \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id')
            ?? \App\Modules\Auth\Models\Role::create(['name' => 'Tester', 'slug' => 'tester'])->id;
        $userId = \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ])->id;

        $period = PayrollPeriod::create([
            'period_start'  => '2026-04-01',
            'period_end'    => '2026-04-15',
            'payroll_date'  => '2026-04-15',
            'is_first_half' => true,
            'is_thirteenth_month' => false,
            'created_by'    => $userId,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $file = $this->csv(
            "employee_no,timestamp,direction\n".
            "{$emp->employee_no},2026-04-10 08:00:00,in\n".
            "{$emp->employee_no},2026-04-10 17:00:00,out\n"
        );

        $result = $this->svc->importRawPunches($file);

        $this->assertSame(0, $result['imported'], 'finalized-period date must not import');
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $emp->id,
            'date'        => '2026-04-10',
        ]);
    }
}
