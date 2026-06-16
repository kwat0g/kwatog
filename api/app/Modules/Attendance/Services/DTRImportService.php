<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DTRImportService
{
    public function __construct(
        private readonly DTRComputationService $dtr,
        private readonly OvertimeService $overtime,
        private readonly PunchSessionizer $sessionizer = new PunchSessionizer(),
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
                    throw new \RuntimeException('Employee number and date are required.');
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
                    $this->overtime->autoDetectFromAttendance($a);
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

    /**
     * OGAMI-011 — additive raw-punch import path.
     *
     * Accepts a CSV of raw biometric punch EVENTS (one row per scan) rather
     * than pre-paired day rows. Columns: employee_no, timestamp, [direction].
     * Events are deduped + sessionized into day records (first in / last out,
     * cross-midnight aware) and written exactly like import() does — same
     * DTR compute + OT auto-detect — so downstream behaviour is identical.
     *
     * Days that fall inside a FINALIZED payroll period are blocked (skipped
     * with an error) so we never mutate attendance the payroll already paid.
     *
     * The legacy paired-CSV import() is untouched.
     *
     * @return array{total:int, imported:int, skipped:int, deduped:int, flagged:int, errors:array<int, array{row:int, message:string}>}
     */
    public function importRawPunches(UploadedFile $file): array
    {
        $empty = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'deduped' => 0, 'flagged' => 0, 'errors' => []];

        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            return array_merge($empty, ['errors' => [['row' => 0, 'message' => 'Could not open uploaded file.']]]);
        }

        $header = fgetcsv($stream);
        if (! $header) {
            fclose($stream);
            return array_merge($empty, ['errors' => [['row' => 0, 'message' => 'Empty CSV.']]]);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $required = ['employee_no', 'timestamp'];
        $missing = array_diff($required, $header);
        if ($missing) {
            fclose($stream);
            return array_merge($empty, ['errors' => [['row' => 1, 'message' => 'Missing column(s): '.implode(', ', $missing)]]]);
        }
        $idx = array_flip($header);

        // ── Collect + validate raw punch rows ──
        $punches = [];
        $errors = [];
        $rowNum = 1;
        $total = 0;
        while (($row = fgetcsv($stream)) !== false) {
            $rowNum++;
            $total++;
            try {
                $empNo = trim((string) ($row[$idx['employee_no']] ?? ''));
                $tsStr = trim((string) ($row[$idx['timestamp']] ?? ''));
                $dir   = isset($idx['direction']) ? strtolower(trim((string) ($row[$idx['direction']] ?? ''))) : null;
                if ($empNo === '' || $tsStr === '') {
                    throw new \RuntimeException('Employee number and timestamp are required.');
                }
                $punches[] = [
                    'employee_no' => $empNo,
                    'timestamp'   => Carbon::parse($tsStr)->toDateTimeString(),
                    'direction'   => $dir !== '' ? $dir : null,
                    'row'         => $rowNum,
                ];
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }
        fclose($stream);

        // ── Sessionize into day records ──
        $result  = $this->sessionizer->sessionize($punches);
        $days    = $result['days'];
        $deduped = $result['deduped'];

        // ── Persist each day record ──
        $cache = []; // employee_no => employee_id
        $imported = 0;
        $skipped  = 0;
        $flagged  = 0;
        foreach ($days as $day) {
            try {
                $empNo = $day['employee_no'];
                if (! isset($cache[$empNo])) {
                    $emp = Employee::where('employee_no', $empNo)->first();
                    if (! $emp) {
                        throw new \RuntimeException("Unknown employee_no '{$empNo}'.");
                    }
                    $cache[$empNo] = $emp->id;
                }
                $employeeId = $cache[$empNo];
                $date = $day['date'];

                if ($this->isDateInFinalizedPeriod($date)) {
                    throw new \RuntimeException("Date {$date} falls in a finalized payroll period — import blocked.");
                }

                if ($day['flag'] !== null) {
                    $flagged++;
                }

                DB::transaction(function () use ($employeeId, $date, $day) {
                    $a = Attendance::firstOrNew([
                        'employee_id' => $employeeId,
                        'date'        => $date,
                    ]);
                    $a->time_in  = $day['time_in'];
                    $a->time_out = $day['time_out'];
                    $a->is_manual_entry = false;
                    if ($day['flag'] !== null) {
                        $a->remarks = trim((string) ($a->remarks ?? '').' punch:'.$day['flag']);
                    }
                    $a = $this->dtr->computeForRecord($a);
                    $a->save();
                    $this->overtime->autoDetectFromAttendance($a);
                });
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = ['row' => 0, 'message' => $e->getMessage()];
            }
        }

        return [
            'total'    => $total,
            'imported' => $imported,
            'skipped'  => $skipped,
            'deduped'  => $deduped,
            'flagged'  => $flagged,
            'errors'   => $errors,
        ];
    }

    /**
     * True when the given date sits inside a FINALIZED (or disbursed) payroll
     * period. Used to block raw-punch edits to already-paid attendance.
     */
    private function isDateInFinalizedPeriod(string $date): bool
    {
        return PayrollPeriod::query()
            ->whereIn('status', [PayrollPeriodStatus::Finalized->value, PayrollPeriodStatus::Disbursed->value])
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }
}
