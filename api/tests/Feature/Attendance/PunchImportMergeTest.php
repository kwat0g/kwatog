<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\Attendance\Services\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchImportMergeTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    private function csv(string $employeeNo, string $in, string $out): UploadedFile
    {
        $body = "employee_no,date,time_in,time_out\n{$employeeNo},2026-10-07,{$in},{$out}\n";

        return UploadedFile::fake()->createWithContent('dtr.csv', $body);
    }

    private function rawPunchesCsv(string $employeeNo, string $inTimestamp, string $outTimestamp): UploadedFile
    {
        $body = "employee_no,timestamp,direction\n"
            ."{$employeeNo},{$inTimestamp},in\n"
            ."{$employeeNo},{$outTimestamp},out\n";

        return UploadedFile::fake()->createWithContent('punches.csv', $body);
    }

    private function appPunchDay($emp): void
    {
        $svc = app(PunchService::class);
        $this->at('2026-10-07 06:10:00');
        $svc->punch($emp, 14.2860, 120.9345, 20.0, null, null);
        $this->at('2026-10-07 14:20:00');
        $svc->punch($emp, 14.2860, 120.9345, 20.0, null, null);
    }

    public function test_paired_import_keeps_earliest_in_and_latest_out_across_sources(): void
    {
        [$emp] = $this->worker();
        $this->appPunchDay($emp);

        $result = app(DTRImportService::class)->import($this->csv($emp->employee_no, '06:00', '14:00'));

        $this->assertSame(1, $result['imported']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));  // CSV earlier
        $this->assertSame('14:20', $row->time_out->format('H:i')); // app later
    }

    public function test_replaying_the_same_file_is_idempotent(): void
    {
        [$emp] = $this->worker();
        $this->appPunchDay($emp);
        $svc = app(DTRImportService::class);

        $svc->import($this->csv($emp->employee_no, '06:00', '14:00'));
        $again = $svc->import($this->csv($emp->employee_no, '06:00', '14:00'));

        $this->assertSame(0, $again['skipped_manual']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));
        $this->assertSame('14:20', $row->time_out->format('H:i'));
    }

    public function test_import_without_app_punches_behaves_as_before(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 15:00:00');

        $result = app(DTRImportService::class)->import($this->csv($emp->employee_no, '06:03', '14:01'));

        $this->assertSame(1, $result['imported']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:03', $row->time_in->format('H:i'));
        $this->assertSame('14:01', $row->time_out->format('H:i'));
    }

    public function test_raw_punch_import_merges_app_punches(): void
    {
        [$emp] = $this->worker();
        $this->appPunchDay($emp);

        $result = app(DTRImportService::class)->importRawPunches(
            $this->rawPunchesCsv($emp->employee_no, '2026-10-07 06:30:00', '2026-10-07 14:00:00')
        );

        $this->assertSame(1, $result['imported']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:10', $row->time_in->format('H:i'));   // app earlier
        $this->assertSame('14:20', $row->time_out->format('H:i'));  // app later
    }
}
