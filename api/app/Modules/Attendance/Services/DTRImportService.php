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
        private readonly OvertimeService $overtime,
        private readonly AttendanceDateMutabilityGuard $mutability,
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

        // The \RuntimeExceptions below are row-level control flow, not HTTP
        // errors: each is caught by this loop's own `catch (Throwable)` and
        // collected into $errors as {row, message}, which is the right UX for a
        // CSV import — one bad line must not fail the file. Converting them to
        // BusinessRuleException would change nothing and only obscure that.
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
                // `HH:mm` carries no date, so anchor it to the row's own date the
                // way time_in above is anchored; anything longer is already a full
                // datetime. This used to read `Carbon::parse($timeOut, $date)`,
                // where Carbon's second parameter is the TIMEZONE — so every
                // non-empty time_out raised InvalidTimeZoneException, the per-row
                // catch below turned it into a silent `skipped`, and this endpoint
                // discarded every row that recorded when someone left. The
                // `strlen($timeOut) <= 5` reparse that used to sit below was
                // already correct and already unreachable, because the throwing
                // call ran first. Cross-midnight is not resolved here: the DTR
                // engine advances a night shift's time_out by a day, and refuses
                // an inverted day shift outright.
                $tOut = null;
                if ($timeOut !== '') {
                    $tOut = strlen($timeOut) <= 5
                        ? Carbon::parse($date.' '.$timeOut)->toDateTimeString()
                        : Carbon::parse($timeOut)->toDateTimeString();
                }

                DB::transaction(function () use ($employeeId, $date, $tIn, $tOut) {
                    $this->mutability->assertMutable($employeeId, $date);
                    $a = Attendance::query()
                        ->where('employee_id', $employeeId)
                        ->where('date', $date)
                        ->lockForUpdate()
                        ->first()
                        ?? new Attendance(['employee_id' => $employeeId, 'date' => $date]);
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
     * Days that fall inside a locked payroll period (finalized, disbursed, or
     * voided) are blocked (skipped with an error) so we never mutate attendance
     * after the payroll run is closed.
     *
     * The paired-CSV path uses the same mutability guard and transaction fence.
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
        // Same rule as import() above: the \RuntimeException in this loop is
        // row-level control flow, caught by the loop's own catch (Throwable) and
        // collected into $errors as {row, message}. Not an HTTP error, and not a
        // candidate for BusinessRuleException.
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
        // The two \RuntimeExceptions in this loop are row-level control flow as
        // well — caught below and reported per day in $errors. Do NOT convert
        // them. The payroll-lock one in particular IS a business
        // rule, and that is exactly why it must stay a per-row entry: as a
        // BusinessRuleException it would abort the whole upload on the first
        // affected day instead of importing the other days and naming that one.
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

                if ($day['flag'] !== null) {
                    $flagged++;
                }

                DB::transaction(function () use ($employeeId, $date, $day) {
                    $this->mutability->assertMutable($employeeId, $date);
                    $a = Attendance::query()
                        ->where('employee_id', $employeeId)
                        ->where('date', $date)
                        ->lockForUpdate()
                        ->first()
                        ?? new Attendance(['employee_id' => $employeeId, 'date' => $date]);
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

}
