<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialStatementController
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
        private readonly BalanceSheetService $balanceSheet,
    ) {}

    public function trialBalance(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $data = $this->trialBalance->generate($from, $to);

        if ($request->query('format') === 'csv') {
            return $this->csv("trial-balance-{$from->toDateString()}-{$to->toDateString()}.csv",
                ['Code', 'Name', 'Type', 'Debit Total', 'Credit Total', 'Balance', 'Side'],
                array_map(fn ($a) => [$a['code'], $a['name'], $a['type'], $a['debit_total'], $a['credit_total'], $a['balance'], $a['balance_side']], $data['accounts']),
            );
        }
        return response()->json(['data' => $data]);
    }

    public function incomeStatement(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $data = $this->incomeStatement->generate($from, $to);

        if ($request->query('format') === 'csv') {
            $rows = [];
            foreach ($data['revenue']['accounts']            as $r) $rows[] = ['Revenue', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Revenue Total', '', '', $data['revenue']['total']];
            foreach ($data['cogs']['accounts']               as $r) $rows[] = ['COGS', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['COGS Total', '', '', $data['cogs']['total']];
            $rows[] = ['Gross Profit', '', '', $data['gross_profit']];
            foreach ($data['operating_expenses']['accounts'] as $r) $rows[] = ['Operating Expense', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Operating Expense Total', '', '', $data['operating_expenses']['total']];
            $rows[] = ['Net Income', '', '', $data['net_income']];
            return $this->csv("income-statement-{$from->toDateString()}-{$to->toDateString()}.csv",
                ['Section', 'Code', 'Name', 'Amount'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    public function balanceSheet(Request $request): JsonResponse|StreamedResponse
    {
        $asOf = $request->filled('as_of')
            ? Carbon::parse((string) $request->query('as_of'))
            : now();
        $data = $this->balanceSheet->generate($asOf);

        if ($request->query('format') === 'csv') {
            $rows = [];
            foreach ($data['assets']['accounts']      as $r) $rows[] = ['Asset',     $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total Assets', '', '', $data['assets']['total']];
            foreach ($data['liabilities']['accounts'] as $r) $rows[] = ['Liability', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total Liabilities', '', '', $data['liabilities']['total']];
            foreach ($data['equity']['accounts']      as $r) $rows[] = ['Equity',    $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total Equity', '', '', $data['equity']['total']];
            return $this->csv("balance-sheet-{$asOf->toDateString()}.csv",
                ['Section', 'Code', 'Name', 'Amount'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : now()->startOfMonth();
        $to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : now()->endOfMonth();
        return [$from, $to];
    }

    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                fputcsv($h, $row);
            }
            fclose($h);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
