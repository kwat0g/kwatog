<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use Illuminate\Support\Carbon;

/**
 * OGAMI-011 — turns a flat list of raw biometric punch events into paired
 * day records (one time_in / time_out span per employee per work day).
 *
 * Rules:
 *   - Multiple punches in a day collapse to FIRST in / LAST out.
 *   - Exact-duplicate punches (same employee + same timestamp) are deduped.
 *   - Cross-midnight: a span whose last punch is on the next calendar day is
 *     anchored to the FIRST punch's date (so a 22:00→06:00 night shift books
 *     against the day it started — the DTR engine handles the math).
 *   - A lone punch with no pair is flagged (missing_out) and still booked with
 *     a null time_out so reviewers can see it.
 *
 * Pure / side-effect free: callers persist the returned day records.
 */
class PunchSessionizer
{
    /**
     * @param  array<int, array{employee_no:string, timestamp:string, direction?:?string, row:int}>  $punches
     * @return array{
     *   days: array<int, array{employee_no:string, date:string, time_in:?string, time_out:?string, flag:?string}>,
     *   deduped: int
     * }
     */
    public function sessionize(array $punches): array
    {
        // ── Dedupe exact (employee_no + timestamp) duplicates ──
        $seen = [];
        $clean = [];
        $deduped = 0;
        foreach ($punches as $p) {
            $ts = Carbon::parse($p['timestamp'])->toDateTimeString();
            $key = $p['employee_no'].'|'.$ts;
            if (isset($seen[$key])) {
                $deduped++;
                continue;
            }
            $seen[$key] = true;
            $clean[] = ['employee_no' => $p['employee_no'], 'ts' => $ts, 'direction' => $p['direction'] ?? null];
        }

        // ── Group by employee, sort chronologically ──
        $byEmp = [];
        foreach ($clean as $c) {
            $byEmp[$c['employee_no']][] = $c;
        }

        $days = [];
        foreach ($byEmp as $empNo => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['ts'], $b['ts']));
            $days = array_merge($days, $this->pairForEmployee($empNo, $rows));
        }

        return ['days' => $days, 'deduped' => $deduped];
    }

    /**
     * Pair one employee's chronologically-sorted punches into day records.
     *
     * @param  array<int, array{employee_no:string, ts:string, direction:?string}>  $rows
     * @return array<int, array{employee_no:string, date:string, time_in:?string, time_out:?string, flag:?string}>
     */
    private function pairForEmployee(string $empNo, array $rows): array
    {
        // Bucket punches by their START date. A punch is considered part of the
        // previous day's session when it lands within ~12h after that day's
        // first punch and the previous day has an open (unmatched) IN.
        $days = [];
        $current = null; // ['date'=>, 'in'=>, 'last'=>]

        $flush = function () use (&$days, &$current) {
            if ($current === null) {
                return;
            }
            $hasOut = $current['last'] !== $current['in'];
            $days[] = [
                'employee_no' => $current['emp'],
                'date'        => $current['date'],
                'time_in'     => $current['in'],
                'time_out'    => $hasOut ? $current['last'] : null,
                'flag'        => $hasOut ? null : 'missing_out',
            ];
            $current = null;
        };

        foreach ($rows as $r) {
            $ts = Carbon::parse($r['ts']);

            if ($current === null) {
                $current = ['emp' => $empNo, 'date' => $ts->toDateString(), 'in' => $r['ts'], 'last' => $r['ts']];
                continue;
            }

            $firstIn = Carbon::parse($current['in']);
            // Same session if within 18h of the first IN (covers long shifts +
            // cross-midnight) AND not more than 1 calendar day apart.
            $withinSession = $ts->diffInHours($firstIn) < 18 && $ts->copy()->startOfDay()->diffInDays($firstIn->copy()->startOfDay()) <= 1;

            if ($withinSession) {
                $current['last'] = $r['ts'];
            } else {
                $flush();
                $current = ['emp' => $empNo, 'date' => $ts->toDateString(), 'in' => $r['ts'], 'last' => $r['ts']];
            }
        }
        $flush();

        return $days;
    }
}
