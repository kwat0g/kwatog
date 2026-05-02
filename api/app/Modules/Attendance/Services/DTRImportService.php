<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DTRImportService
{
    public function __construct(
        private readonly DTRComputationService $dtr,
    ) {}

    /** @return array{total:int, imported:int, skipped:int, errors:array<int, array{row:int, message:string}>} */
    public function import(UploadedFile $file): array
    {
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            return ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [['row' => 0, 'message' => 'Could not open uploaded file.']]];
        }

        $header = fgetcsv($stream);
        if (! $header) {
            fclose($stream);
            return ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [['row' => 0, 'message' => 'Empty CSV.']]];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $required = ['employee_no', 'date', 'time_in', 'time_out'];
        $missing = array_diff($required, $header);
        if ($missing) {
            fclose($stream);
            return ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [['row' => 1, 'message' => 'Missing column(s): '.implode(', ', $missing)]]];
        }
        $idx = array_flip($header);

        $cache = []; // employee_no => employee_id
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;
        $total = 0;

        while (($row = fgetcsv($stream)) !== false) {
            $rowNum++;
            $total++;

            try {
                $empNo = trim((string) ($row[$idx['employee_no']] ?? ''));
                $dateStr = trim((string) ($row[$idx['date']] ?? ''));
                $timeIn  = trim((string) ($row[$idx['time_in']] ?? ''));
                $timeOut = trim((string) ($row[$idx['time_out']] ?? ''));

                if ($empNo === '' || $dateStr === '') {
                    throw new \RuntimeException('employee_no and date are required.');
                }

                if (! isset($cache[$empNo])) {
                    $emp = Employee::where('employee_no', $empNo)->first();
                    if (! $emp) throw new \RuntimeException("Unknown employee_no '{$empNo}'.");
                    $cache[$empNo] = $emp->id;
                }
                $employeeId = $cache[$empNo];
                $date = Carbon::parse($dateStr)->toDateString();

                $tIn  = $timeIn !== '' ? Carbon::parse($date.' '.$timeIn)->toDateTimeString() : null;
                $tOut = $timeOut !== '' ? Carbon::parse($timeOut, $date)->toDateTimeString() : null;
                // If time_out is HH:mm only, treat as same date (DTR engine handles cross-midnight).
                if ($timeOut !== '' && strlen($timeOut) <= 5) {
                    $tOut = Carbon::parse($date.' '.$timeOut)->toDateTimeString();
                }

                DB::transaction(function () use ($employeeId, $date, $tIn, $tOut) {
                    $a = Attendance::firstOrNew([
                        'employee_id' => $employeeId,
                        'date'        => $date,
                    ]);
                    $a->time_in = $tIn;
                    $a->time_out = $tOut;
                    $a->is_manual_entry = false;
                    $a = $this->dtr->computeForRecord($a);
                    $a->save();
                });
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }
        fclose($stream);

        return ['total' => $total, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
