<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services\Statements;

use App\Common\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IncomeStatementService
{
    /**
     * @return array{
     *   from:string, to:string,
     *   revenue: array{accounts: array<int, array>, total: string},
     *   cogs: array{accounts: array<int, array>, total: string},
     *   gross_profit: string,
     *   operating_expenses: array{accounts: array<int, array>, total: string},
     *   operating_income: string,
     *   net_income: string,
     * }
     */
    public function generate(Carbon $from, Carbon $to): array
    {
        $cacheKey = sprintf('stmt:income:%s:%s', $from->toDateString(), $to->toDateString());

        return Cache::tags(['financial_statements'])->remember($cacheKey, now()->addSeconds(60), function () use ($from, $to) {
            $rows = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->whereBetween('je.date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('a.type', ['revenue', 'expense'])
                ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.parent_id')
                ->orderBy('a.code')
                ->selectRaw('
                    a.code, a.name, a.type, a.parent_id,
                    COALESCE(SUM(jel.debit), 0)  as debit_total,
                    COALESCE(SUM(jel.credit), 0) as credit_total
                ')
                ->get();

            // Parent-code lookup so we can split COGS (5xxx) vs OpEx (6xxx).
            $parentCodeById = DB::table('accounts')
                ->whereIn('id', $rows->pluck('parent_id')->filter()->unique())
                ->pluck('code', 'id');

            $revenue = []; $cogs = []; $opex = [];
            $rTotal = '0.00'; $cTotal = '0.00'; $oTotal = '0.00';

            foreach ($rows as $r) {
                if ($r->type === 'revenue') {
                    $balance = Money::sub((string) $r->credit_total, (string) $r->debit_total);
                    $revenue[] = [
                        'code' => $r->code, 'name' => $r->name, 'amount' => $balance,
                    ];
                    $rTotal = Money::add($rTotal, $balance);
                } else {
                    $balance = Money::sub((string) $r->debit_total, (string) $r->credit_total);
                    $entry = ['code' => $r->code, 'name' => $r->name, 'amount' => $balance];
                    $parentCode = $r->parent_id ? ($parentCodeById[$r->parent_id] ?? null) : null;
                    // 5000 series = COGS; everything else = OpEx
                    if ($parentCode && str_starts_with($parentCode, '5')) {
                        $cogs[] = $entry;
                        $cTotal = Money::add($cTotal, $balance);
                    } elseif (str_starts_with($r->code, '5')) {
                        $cogs[] = $entry;
                        $cTotal = Money::add($cTotal, $balance);
                    } else {
                        $opex[] = $entry;
                        $oTotal = Money::add($oTotal, $balance);
                    }
                }
            }

            $gross = Money::sub($rTotal, $cTotal);
            $operatingIncome = Money::sub($gross, $oTotal);

            return [
                'from'              => $from->toDateString(),
                'to'                => $to->toDateString(),
                'revenue'           => ['accounts' => $revenue, 'total' => $rTotal],
                'cogs'              => ['accounts' => $cogs,    'total' => $cTotal],
                'gross_profit'      => $gross,
                'operating_expenses'=> ['accounts' => $opex,    'total' => $oTotal],
                'operating_income'  => $operatingIncome,
                'net_income'        => $operatingIncome,
            ];
        });
    }
}
