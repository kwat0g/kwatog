<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Requests\StatementAsOfRequest;
use App\Modules\Accounting\Requests\StatementDateRangeRequest;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialStatementController
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
        private readonly BalanceSheetService $balanceSheet,
        private readonly InvoiceService $invoices,
        private readonly BillService $bills,
        private readonly SettingsService $settings,
    ) {}

    public function trialBalance(StatementDateRangeRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizeExport($request);
        [$from, $to] = $request->range();
        $data = $this->trialBalance->generate($from, $to);
        $currency = $this->currency();
        $data['currency'] = $currency;

        if ($request->query('format') === 'csv') {
            $rows = array_map(fn ($a) => [
                'Account', $currency, $a['code'], $a['name'], $a['type'],
                $a['debit_total'], $a['credit_total'], $a['balance'], $a['balance_side'],
            ], $data['accounts']);
            $rows[] = ['Total', $currency, '', '', '', $data['totals']['debit'], $data['totals']['credit'], '', ''];
            $rows[] = [
                'Status', $currency, '', 'Reconciled', '', '', '',
                // Money::cmp, not ===. Both totals are scale-2 accumulations today,
                // so string identity happens to agree, but comparing decimal
                // strings byte-wise reports "unreconciled" the moment a scale
                // differs ('1000.0' vs '1000.00'). Reconciliation is a numeric
                // question and BalanceSheetService already answers it this way.
                Money::cmp($data['totals']['debit'], $data['totals']['credit']) === 0 ? 'true' : 'false', '',
            ];
            return $this->csv("trial-balance-{$from->toDateString()}-{$to->toDateString()}.csv",
                ['Row Type', 'Currency', 'Code', 'Name', 'Type', 'Debit Total', 'Credit Total', 'Balance', 'Side'],
                $rows,
            );
        }
        return response()->json(['data' => $data]);
    }

    public function incomeStatement(StatementDateRangeRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizeExport($request);
        [$from, $to] = $request->range();
        $data = $this->incomeStatement->generate($from, $to);
        $currency = $this->currency();
        $data['currency'] = $currency;

        if ($request->query('format') === 'csv') {
            $rows = [];
            foreach ($data['revenue']['accounts'] as $r) $rows[] = ['Account', $currency, 'Revenue', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'Revenue', '', 'Total Revenue', $data['revenue']['total']];
            foreach ($data['cogs']['accounts'] as $r) $rows[] = ['Account', $currency, 'COGS', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'COGS', '', 'Total COGS', $data['cogs']['total']];
            $rows[] = ['Total', $currency, 'Gross Profit', '', 'Gross Profit', $data['gross_profit']];
            foreach ($data['operating_expenses']['accounts'] as $r) $rows[] = ['Account', $currency, 'Operating Expense', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'Operating Expense', '', 'Total Operating Expenses', $data['operating_expenses']['total']];
            $rows[] = ['Total', $currency, 'Net Income', '', 'Net Income', $data['net_income']];
            return $this->csv("income-statement-{$from->toDateString()}-{$to->toDateString()}.csv",
                ['Row Type', 'Currency', 'Section', 'Code', 'Name', 'Amount'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    public function balanceSheet(StatementAsOfRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizeExport($request);
        $asOf = $request->asOfDate();
        $data = $this->balanceSheet->generate($asOf);
        $currency = $this->currency();
        $data['currency'] = $currency;

        if ($request->query('format') === 'csv') {
            $rows = [];
            foreach ($data['assets']['accounts'] as $r) $rows[] = ['Account', $currency, 'Asset', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'Asset', '', 'Total Assets', $data['assets']['total']];
            foreach ($data['liabilities']['accounts'] as $r) $rows[] = ['Account', $currency, 'Liability', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'Liability', '', 'Total Liabilities', $data['liabilities']['total']];
            foreach ($data['equity']['accounts'] as $r) $rows[] = ['Account', $currency, 'Equity', $r['code'], $r['name'], $r['amount']];
            $rows[] = ['Total', $currency, 'Equity', '', 'Total Equity', $data['equity']['total']];
            $rows[] = ['Reconciliation', $currency, 'Balance Sheet', '', 'Total Assets', $data['total_assets']];
            $rows[] = ['Reconciliation', $currency, 'Balance Sheet', '', 'Total Liabilities + Equity', $data['total_liabilities_equity']];
            $rows[] = ['Status', $currency, 'Balance Sheet', '', 'Balanced', $data['balanced'] ? 'true' : 'false'];
            return $this->csv("balance-sheet-{$asOf->toDateString()}.csv",
                ['Row Type', 'Currency', 'Section', 'Code', 'Name', 'Amount'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    /**
     * REC-15 — AR aging (receivables). Buckets + per-customer breakdown as of
     * a date. The InvoiceService computes this on every finance-dashboard load;
     * this simply exposes it as a first-class, exportable report.
     */
    public function arAging(StatementAsOfRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizeExport($request);
        $asOf = $request->asOfDate();
        $data = $this->invoices->aging($asOf);
        $currency = $this->currency();
        $data['currency'] = $currency;

        if ($request->query('format') === 'csv') {
            $rows = array_map(fn ($r) => [
                'Account', $currency, $r['customer_name'], $r['current'], $r['d1_30'], $r['d31_60'],
                $r['d61_90'], $r['d91_plus'], $r['total'],
            ], $data['by_customer']);
            $b = $data['buckets'];
            $rows[] = ['Total', $currency, 'TOTAL', $b['current'], $b['d1_30'], $b['d31_60'], $b['d61_90'], $b['d91_plus'], $b['total']];
            return $this->csv("ar-aging-{$asOf->toDateString()}.csv",
                ['Row Type', 'Currency', 'Customer', 'Current', '1-30', '31-60', '61-90', '91+', 'Total'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    /**
     * REC-15 — AP aging (payables). Buckets + per-vendor breakdown as of a date.
     */
    public function apAging(StatementAsOfRequest $request): JsonResponse|StreamedResponse
    {
        $this->authorizeExport($request);
        $asOf = $request->asOfDate();
        $data = $this->bills->aging($asOf);
        $currency = $this->currency();
        $data['currency'] = $currency;

        if ($request->query('format') === 'csv') {
            $rows = array_map(fn ($r) => [
                'Account', $currency, $r['vendor_name'], $r['current'], $r['d1_30'], $r['d31_60'],
                $r['d61_90'], $r['d91_plus'], $r['total'],
            ], $data['by_vendor']);
            $b = $data['buckets'];
            $rows[] = ['Total', $currency, 'TOTAL', $b['current'], $b['d1_30'], $b['d31_60'], $b['d61_90'], $b['d91_plus'], $b['total']];
            return $this->csv("ap-aging-{$asOf->toDateString()}.csv",
                ['Row Type', 'Currency', 'Vendor', 'Current', '1-30', '31-60', '61-90', '91+', 'Total'], $rows);
        }
        return response()->json(['data' => $data]);
    }

    /**
     * Dedicated export boundary. The legacy ?format=csv query remains
     * supported, but callers can no longer rely on the view route as the
     * intended export contract.
     */
    public function apAgingExport(StatementAsOfRequest $request): JsonResponse|StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('accounting.statements.export'), 403, 'You do not have permission to export statements.');
        $request->query->set('format', 'csv');

        return $this->apAging($request);
    }

    private function currency(): string
    {
        return strtoupper($this->settings->requiredString('accounting.functional_currency_code'));
    }

    private function authorizeExport(Request $request): void
    {
        if ($request->query('format') === 'csv') {
            abort_unless($request->user()?->hasPermission('accounting.statements.export'), 403, 'You do not have permission to export statements.');
        }
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
