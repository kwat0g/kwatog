<?php

declare(strict_types=1);

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile periods whose is_first_half flag contradicts their own dates.
 *
 * Until now the half was a free checkbox describing a window that already
 * implied the answer, so an operator could enter Aug 16–31 and tick "1st half".
 * PayrollPeriod::cycleKey() now derives the half from period_start instead (see
 * that method for why: mislabelled pairs produced inverted keys, and the
 * double-pay guard read them as two different cycles).
 *
 * That derivation silently changes the key of any already-mislabelled period,
 * stranding its rows in payroll_cycle_claims under the OLD key. The effect is
 * doubly wrong: those employees are unprotected for the cycle they were really
 * paid in, and falsely blocked from one they were never paid in. This migration
 * realigns both halves of that state:
 *
 *   1. is_first_half is corrected to match period_start (day 1–15 = first half)
 *   2. every cycle claim is re-keyed to its period's derived key
 *
 * Money is NOT touched. A finalized period keeps its payroll rows, its GL
 * posting and its amounts exactly as computed — only the label and the claim
 * bookkeeping are brought in line with the dates that were always authoritative.
 * Government deductions are not retroactively moved either: they were withheld
 * per the flag in force at compute time, and rewriting settled payroll would
 * falsify the audit trail. Finalized periods corrected here are logged so HR can
 * decide whether an adjustment is warranted.
 *
 * Re-keying is TWO-PHASE. A mislabelled pair covering one month holds each
 * other's keys (Nov 16–30 keyed H1, Nov 1–15 keyed H2), so the correction is a
 * swap: updating either one first collides with the other on
 * UNIQUE (employee_id, cycle_key). Phase 1 parks every moving claim on a
 * temporary unique key, vacating all contested slots; phase 2 lands the final
 * keys. Claims already correct are never touched, so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $relabelled = $this->correctPeriodFlags();
            $conflicts  = $this->reKeyClaims();
            $backfilled = $this->backfillMissingClaims();

            if ($relabelled !== [] || $conflicts !== [] || $backfilled > 0) {
                Log::warning('Payroll period halves reconciled against their dates', [
                    'relabelled'          => $relabelled,
                    'duplicate_payments'  => $conflicts,
                    'claims_backfilled'   => $backfilled,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible by design: the pre-correction flags were wrong, and the
        // claims they produced pointed at cycles those employees were never paid
        // in. Restoring them would reopen the double-pay hole.
    }

    /**
     * Set is_first_half from period_start wherever the two disagree.
     *
     * @return array<int, string> human-readable notes, finalized runs flagged
     */
    private function correctPeriodFlags(): array
    {
        $notes = [];

        $periods = DB::table('payroll_periods')
            ->where('is_thirteenth_month', false)
            ->orderBy('id')
            ->get(['id', 'period_start', 'period_end', 'is_first_half', 'status']);

        foreach ($periods as $period) {
            $derived = PayrollPeriod::deriveIsFirstHalf($period->period_start);

            if ((bool) $period->is_first_half === $derived) {
                continue;
            }

            DB::table('payroll_periods')
                ->where('id', $period->id)
                ->update(['is_first_half' => $derived, 'updated_at' => now()]);

            $notes[] = sprintf(
                'period #%d (%s–%s, %s) was marked %s but its dates are the %s%s',
                $period->id,
                $period->period_start,
                $period->period_end,
                $period->status,
                $period->is_first_half ? '1st half' : '2nd half',
                $derived ? '1st half' : '2nd half',
                in_array($period->status, ['finalized', 'disbursed'], true)
                    ? ' — MONEY ALREADY MOVED, review whether an adjustment is needed'
                    : '',
            );
        }

        return $notes;
    }

    /**
     * Move every claim onto its period's derived cycle key.
     *
     * @return array<int, string> notes for claims that could not be moved
     *                            because the target slot was genuinely taken
     */
    private function reKeyClaims(): array
    {
        // Desired key per period, for both normal cutoffs and 13th-month runs.
        $desiredByPeriod = [];
        foreach (DB::table('payroll_periods')->get(['id', 'period_start', 'is_thirteenth_month']) as $period) {
            $start = Carbon::parse($period->period_start);

            $desiredByPeriod[(int) $period->id] = $period->is_thirteenth_month
                ? $start->format('Y').'-13TH'
                : $start->format('Y-m').(PayrollPeriod::deriveIsFirstHalf($period->period_start) ? '-H1' : '-H2');
        }

        // Only claims whose key actually changes need moving.
        $moving = DB::table('payroll_cycle_claims')
            ->orderBy('payroll_id')
            ->get(['id', 'employee_id', 'payroll_id', 'payroll_period_id', 'cycle_key'])
            ->filter(fn ($claim) => ($desiredByPeriod[(int) $claim->payroll_period_id] ?? $claim->cycle_key) !== $claim->cycle_key)
            ->values();

        if ($moving->isEmpty()) {
            return [];
        }

        // ─── Phase 1: park on temporary unique keys ──────────────
        // Vacates every contested slot so a swap cannot collide. The temp key is
        // '~' + claim id: unique by construction and well inside varchar(20).
        foreach ($moving as $claim) {
            DB::table('payroll_cycle_claims')
                ->where('id', $claim->id)
                ->update(['cycle_key' => '~'.$claim->id, 'updated_at' => now()]);
        }

        // ─── Phase 2: land the derived keys ─────────────────────
        $conflicts = [];

        foreach ($moving as $claim) {
            $desired = $desiredByPeriod[(int) $claim->payroll_period_id];

            // Did another claim already take this slot? That means two periods
            // genuinely paid this employee for the SAME real cutoff — a true
            // double payment the old inverted key was hiding. Keep the existing
            // claim (earliest payroll wins, matching the 0439 backfill) and drop
            // the redundant one: the cycle stays protected either way, and the
            // duplicate PAYROLL rows are what needs human attention.
            $taken = DB::table('payroll_cycle_claims')
                ->where('employee_id', $claim->employee_id)
                ->where('cycle_key', $desired)
                ->exists();

            if ($taken) {
                DB::table('payroll_cycle_claims')->where('id', $claim->id)->delete();

                $conflicts[] = sprintf(
                    'employee #%d was paid twice for cycle %s (payroll #%d via period #%d is a duplicate) — void one of the periods',
                    $claim->employee_id,
                    $desired,
                    $claim->payroll_id,
                    $claim->payroll_period_id,
                );

                continue;
            }

            DB::table('payroll_cycle_claims')
                ->where('id', $claim->id)
                ->update(['cycle_key' => $desired, 'updated_at' => now()]);
        }

        return $conflicts;
    }
    /**
     * Create claims for real payroll rows that never got one.
     *
     * Migration 0439's backfill used DISTINCT ON (employee, cycle_key), keeping
     * the earliest payroll per slot. Under the OLD inverted keys a mislabelled
     * period could occupy a slot belonging to a different real cutoff — e.g. a
     * Jul 1–15 period keyed H2 beat the genuine Jul 16–31 run, so that run's
     * employees were left unclaimed. Re-keying above frees those slots but does
     * not fill them, which would leave those employees payable a second time for
     * a cutoff they have already been paid for.
     *
     * Skips voided periods (their claims are deliberately released) and error
     * rows (₱0 diagnostic markers that take no claim by design). A slot that is
     * still occupied after re-keying is a genuine duplicate payment and is left
     * for the conflict report rather than forced.
     *
     * @return int claims created
     */
    private function backfillMissingClaims(): int
    {
        $orphans = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->leftJoin('payroll_cycle_claims as c', 'c.payroll_id', '=', 'p.id')
            ->whereNull('c.id')
            ->where('pp.status', '!=', 'voided')
            ->whereNull('p.error_message')
            ->orderBy('p.id')
            ->get([
                'p.id as payroll_id',
                'p.employee_id',
                'p.payroll_period_id',
                'pp.period_start',
                'pp.is_thirteenth_month',
            ]);

        $created = 0;

        foreach ($orphans as $row) {
            $start = Carbon::parse($row->period_start);
            $cycleKey = $row->is_thirteenth_month
                ? $start->format('Y').'-13TH'
                : $start->format('Y-m').(PayrollPeriod::deriveIsFirstHalf($row->period_start) ? '-H1' : '-H2');

            $taken = DB::table('payroll_cycle_claims')
                ->where('employee_id', $row->employee_id)
                ->where('cycle_key', $cycleKey)
                ->exists();

            if ($taken) {
                continue; // genuine duplicate — reported by reKeyClaims()
            }

            DB::table('payroll_cycle_claims')->insert([
                'employee_id'       => $row->employee_id,
                'payroll_id'        => $row->payroll_id,
                'payroll_period_id' => $row->payroll_period_id,
                'cycle_key'         => $cycleKey,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $created++;
        }

        return $created;
    }

    private function cycleKeyFor(string $periodStart, bool $isFirstHalf): string
    {
        return Carbon::parse($periodStart)->format('Y-m').($isFirstHalf ? '-H1' : '-H2');
    }
};
