<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services\Statements;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BalanceSheetService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IncomeStatementService $incomeStatement,
    ) {}

    /**
     * As-of (inclusive) balance sheet. Equation must hold:
     *   total_assets = total_liabilities + total_equity (incl. period net income)
     *
     * @return array{
     *   as_of: string,
     *   assets: array{accounts: array<int, array>, total: string},
     *   liabilities: array{accounts: array<int, array>, total: string},
     *   equity: array{accounts: array<int, array>, total: string},
     *   total_assets: string,
     *   total_liabilities_equity: string,
     *   balanced: bool,
     * }
     */
    public function generate(Carbon $asOf): array
    {
        $cacheKey = sprintf('stmt:balance_sheet:%s', $asOf->toDateString());

        return Cache::tags(['financial_statements'])->remember($cacheKey, now()->addSeconds(60), function () use ($asOf) {
            $rows = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->whereDate('je.date', '<=', $asOf->toDateString())
                ->whereIn('a.type', ['asset', 'liability', 'equity'])
                ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
                ->orderBy('a.code')
                ->selectRaw('
                    a.code, a.name, a.type, a.normal_balance,
                    COALESCE(SUM(jel.debit), 0)  as debit_total,
                    COALESCE(SUM(jel.credit), 0) as credit_total
                ')
                ->get();

            $assets = []; $liabilities = []; $equity = [];
            $aTotal = '0.00'; $lTotal = '0.00'; $eTotal = '0.00';

            foreach ($rows as $r) {
                $bal = $r->normal_balance === 'debit'
                    ? Money::sub((string) $r->debit_total, (string) $r->credit_total)
                    : Money::sub((string) $r->credit_total, (string) $r->debit_total);
                $entry = ['code' => $r->code, 'name' => $r->name, 'amount' => $bal];

                if ($r->type === 'asset') {
                    $assets[] = $entry;
                    $aTotal = Money::add($aTotal, $bal);
                } elseif ($r->type === 'liability') {
                    $liabilities[] = $entry;
                    $lTotal = Money::add($lTotal, $bal);
                } else {
                    $equity[] = $entry;
                    $eTotal = Money::add($eTotal, $bal);
                }
            }

            // Synthetic line: current-period net income (so equity reflects revenues - expenses YTD).
            $fiscalStartMonth = (int) ($this->settings->get('fiscal.year_start_month', 1));
            $fyStart = Carbon::create($asOf->year, $fiscalStartMonth, 1)->startOfDay();
            if ($fyStart->gt($asOf)) $fyStart->subYear();
            $is = $this->incomeStatement->generate($fyStart, $asOf);
            if (! Money::isZero($is['net_income'])) {
                $equity[] = [
                    'code'   => '3099',
                    'name'   => 'Current Period Net Income',
                    'amount' => $is['net_income'],
                ];
                $eTotal = Money::add($eTotal, $is['net_income']);
            }

            $totalLE  = Money::add($lTotal, $eTotal);
            $balanced = Money::cmp($aTotal, $totalLE) === 0;

            return [
                'as_of'                    => $asOf->toDateString(),
                'assets'                   => ['accounts' => $assets,      'total' => $aTotal],
                'liabilities'              => ['accounts' => $liabilities, 'total' => $lTotal],
                'equity'                   => ['accounts' => $equity,      'total' => $eTotal],
                'total_assets'             => $aTotal,
                'total_liabilities_equity' => $totalLE,
                'balanced'                 => $balanced,
            ];
        });
    }
}
