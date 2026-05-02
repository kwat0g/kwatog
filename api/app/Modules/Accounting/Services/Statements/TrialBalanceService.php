<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services\Statements;

use App\Common\Support\Money;
use App\Modules\Accounting\Exceptions\LedgerImbalanceException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    /**
     * Returns the trial balance between two dates inclusive (period activity, not as-of cumulative).
     *
     * @return array{
     *   from: string, to: string,
     *   accounts: array<int, array{code:string, name:string, type:string, normal_balance:string, debit_total:string, credit_total:string, balance:string, balance_side:string}>,
     *   totals: array{debit:string, credit:string}
     * }
     */
    public function generate(Carbon $from, Carbon $to): array
    {
        $cacheKey = sprintf('stmt:trial_balance:%s:%s', $from->toDateString(), $to->toDateString());

        return Cache::tags(['financial_statements'])->remember($cacheKey, now()->addSeconds(60), function () use ($from, $to) {
            $rows = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->whereBetween('je.date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
                ->orderBy('a.code')
                ->selectRaw('
                    a.code, a.name, a.type, a.normal_balance,
                    COALESCE(SUM(jel.debit), 0)  as debit_total,
                    COALESCE(SUM(jel.credit), 0) as credit_total
                ')
                ->get();

            $accounts = []; $td = '0.00'; $tc = '0.00';
            foreach ($rows as $r) {
                // SQLite returns numeric SUMs without decimals; force scale-2 strings.
                $debit  = Money::round2((string) $r->debit_total);
                $credit = Money::round2((string) $r->credit_total);
                $td = Money::add($td, $debit);
                $tc = Money::add($tc, $credit);

                $balance = $r->normal_balance === 'debit'
                    ? Money::sub($debit, $credit)
                    : Money::sub($credit, $debit);
                $side = Money::isZero($balance)
                    ? 'zero'
                    : (Money::gt($balance, '0') ? $r->normal_balance : ($r->normal_balance === 'debit' ? 'credit' : 'debit'));

                $accounts[] = [
                    'code'           => $r->code,
                    'name'           => $r->name,
                    'type'           => $r->type,
                    'normal_balance' => $r->normal_balance,
                    'debit_total'    => $debit,
                    'credit_total'   => $credit,
                    'balance'        => $balance,
                    'balance_side'   => $side,
                ];
            }

            // Sanity: trial balance must reconcile.
            if (Money::cmp($td, $tc) !== 0) {
                throw new LedgerImbalanceException($td, $tc);
            }

            return [
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'accounts' => $accounts,
                'totals'   => ['debit' => $td, 'credit' => $tc],
            ];
        });
    }
}
