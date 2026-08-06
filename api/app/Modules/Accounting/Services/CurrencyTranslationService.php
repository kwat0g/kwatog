<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\FxRate;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use Carbon\Carbon;
use RuntimeException;

/**
 * REC-12 (core) — translate the PHP-functional financial statements into a
 * reporting currency (JPY for the parent) using the CURRENT-RATE METHOD:
 *
 *   - Assets & liabilities  → closing rate (the rate on/just before as-of date)
 *   - Revenue & expenses    → average rate over the period
 *   - Equity (contributed)  → closing rate here, because historical
 *     contribution rates are NOT tracked yet (that needs transaction-currency
 *     capture on the equity JEs — deferred). Documented simplification.
 *   - CTA (Cumulative Translation Adjustment) → the balancing plug in equity,
 *     so the translated balance sheet still balances.
 *
 * Rates come from `fx_rates.rate_to_functional` = PHP per 1 unit of the
 * reporting currency. Translating a PHP amount INTO the reporting currency
 * therefore DIVIDES by the rate (php ÷ php-per-jpy = jpy).
 *
 * SCOPE — this is read-side translation only. It does NOT change any posting.
 * Transaction-currency capture on documents (JE lines, invoices, bills, POs)
 * and realized FX gain/loss on settlement + intercompany reconciliation are
 * DEFERRED: they touch every monetary write path and a half-done write path is
 * worse than none. This layer delivers the signature parent-pack deliverable
 * (JPY-translated TB/BS/IS + CTA) that Tanaka hand-does in Excel today, with
 * zero risk to the GL.
 */
class CurrencyTranslationService
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
        private readonly BalanceSheetService $balanceSheet,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Closing rate: the most recent fx_rate on or before $asOf for $currency.
     */
    public function closingRate(string $currency, Carbon $asOf): string
    {
        if (strtoupper($currency) === $this->functionalCurrency()) {
            return '1.00000000';
        }
        $rate = FxRate::query()
            ->where('currency_code', strtoupper($currency))
            ->whereDate('rate_date', '<=', $asOf->toDateString())
            ->orderByDesc('rate_date')
            ->value('rate_to_functional');

        if ($rate === null) {
            throw new RuntimeException("No FX rate for {$currency} on or before {$asOf->toDateString()}.");
        }
        return (string) $rate;
    }

    /**
     * Average rate over [$from, $to] — the simple mean of the available rate
     * rows in the window (falls back to the closing rate if none are inside it).
     * A period-weighted average would need daily balances; the mean of posted
     * rates is the accepted practical proxy for statement translation.
     */
    public function averageRate(string $currency, Carbon $from, Carbon $to): string
    {
        if (strtoupper($currency) === $this->functionalCurrency()) {
            return '1.00000000';
        }
        $rates = FxRate::query()
            ->where('currency_code', strtoupper($currency))
            ->whereBetween('rate_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('rate_to_functional');

        if ($rates->isEmpty()) {
            return $this->closingRate($currency, $to);
        }
        $sum = '0';
        foreach ($rates as $r) {
            $sum = bcadd($sum, (string) $r, 8);
        }
        return bcdiv($sum, (string) $rates->count(), 8);
    }

    private function functionalCurrency(): string
    {
        return strtoupper($this->settings->requiredString('accounting.functional_currency_code'));
    }

    /** Translate a functional (PHP) amount into the reporting currency. */
    private function toReporting(string $phpAmount, string $rate): string
    {
        if (bccomp($rate, '0', 8) === 0) {
            throw new RuntimeException('FX rate must be non-zero.');
        }
        return Money::round2(bcdiv($phpAmount, $rate, 8));
    }

    /**
     * JPY-translated trial balance — every account's debit/credit total at the
     * closing rate. Because a single rate scales both columns uniformly, the
     * translated TB still reconciles (total debit == total credit).
     *
     * @return array{
     *   from: string, to: string, currency: string, closing_rate: string,
     *   accounts: array<int, array>, totals: array{debit: string, credit: string}
     * }
     */
    public function translatedTrialBalance(Carbon $from, Carbon $to, string $currency): array
    {
        $currency = strtoupper($currency);
        $closing  = $this->closingRate($currency, $to);
        $php = $this->trialBalance->generate($from, $to);

        $accounts = array_map(function ($a) use ($closing) {
            return [
                'code'           => $a['code'],
                'name'           => $a['name'],
                'type'           => $a['type'],
                'normal_balance' => $a['normal_balance'],
                'debit_total'    => $this->toReporting((string) $a['debit_total'], $closing),
                'credit_total'   => $this->toReporting((string) $a['credit_total'], $closing),
                'balance'        => $this->toReporting((string) $a['balance'], $closing),
                'balance_side'   => $a['balance_side'],
            ];
        }, $php['accounts']);

        $td = '0.00'; $tc = '0.00';
        foreach ($accounts as $a) {
            $td = Money::add($td, (string) $a['debit_total']);
            $tc = Money::add($tc, (string) $a['credit_total']);
        }

        return [
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'currency'     => $currency,
            'closing_rate' => $closing,
            'accounts'     => $accounts,
            'totals'       => ['debit' => $td, 'credit' => $tc],
        ];
    }

    /**
     * JPY-translated balance sheet (current-rate method) with an explicit CTA
     * line that makes the translated statement balance.
     *
     * @return array{
     *   as_of: string, currency: string, closing_rate: string, average_rate: string,
     *   assets: array{accounts: array<int, array>, total: string},
     *   liabilities: array{accounts: array<int, array>, total: string},
     *   equity: array{accounts: array<int, array>, total: string},
     *   cta: string, total_assets: string, total_liabilities_equity: string, balanced: bool
     * }
     */
    public function translatedBalanceSheet(Carbon $asOf, string $currency): array
    {
        $currency = strtoupper($currency);
        $fyStart  = $asOf->copy()->startOfYear();
        $closing  = $this->closingRate($currency, $asOf);
        $average  = $this->averageRate($currency, $fyStart, $asOf);

        $php = $this->balanceSheet->generate($asOf);

        // Assets & liabilities at closing rate.
        $assets = array_map(fn ($a) => $this->translateLine($a, $closing), $php['assets']['accounts']);
        $liabilities = array_map(fn ($a) => $this->translateLine($a, $closing), $php['liabilities']['accounts']);

        // Equity: net-income line at average rate; the rest at
        // closing (historical contribution rates not tracked — see docblock).
        $equity = array_map(function ($a) use ($closing, $average) {
            $rate = ($a['code'] ?? null) === $this->settings->requiredString('accounting.statements.current_period_net_income_code') ? $average : $closing;
            return $this->translateLine($a, $rate);
        }, $php['equity']['accounts']);

        $aTotal = $this->sumAmounts($assets);
        $lTotal = $this->sumAmounts($liabilities);
        $eTotal = $this->sumAmounts($equity);

        // CTA is the plug: assets = liabilities + equity + CTA.
        $cta = Money::sub($aTotal, Money::add($lTotal, $eTotal));

        // Surface CTA as an equity line so the pack reads as a real statement.
        $equity[] = [
            'code'   => $this->settings->requiredString('accounting.statements.translation_adjustment_code'),
            'name'   => 'Cumulative Translation Adjustment (CTA)',
            'amount' => $cta,
        ];
        $eTotalWithCta = Money::add($eTotal, $cta);
        $totalLE = Money::add($lTotal, $eTotalWithCta);

        return [
            'as_of'                    => $asOf->toDateString(),
            'currency'                 => $currency,
            'closing_rate'             => $closing,
            'average_rate'             => $average,
            'assets'                   => ['accounts' => $assets,      'total' => $aTotal],
            'liabilities'              => ['accounts' => $liabilities, 'total' => $lTotal],
            'equity'                   => ['accounts' => $equity,      'total' => $eTotalWithCta],
            'cta'                      => $cta,
            'total_assets'             => $aTotal,
            'total_liabilities_equity' => $totalLE,
            'balanced'                 => Money::cmp($aTotal, $totalLE) === 0,
        ];
    }

    /**
     * JPY-translated income statement — all P&L lines at the average rate.
     *
     * @return array{from: string, to: string, currency: string, average_rate: string, statement: array}
     */
    public function translatedIncomeStatement(Carbon $from, Carbon $to, string $currency): array
    {
        $currency = strtoupper($currency);
        $average = $this->averageRate($currency, $from, $to);
        $php = $this->incomeStatement->generate($from, $to);

        $translateGroup = fn (array $group) => [
            'accounts' => array_map(fn ($a) => $this->translateLine($a, $average), $group['accounts']),
            'total'    => $this->toReporting((string) $group['total'], $average),
        ];

        return [
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'currency'     => $currency,
            'average_rate' => $average,
            'statement'    => [
                'revenue'            => $translateGroup($php['revenue']),
                'cogs'               => $translateGroup($php['cogs']),
                'gross_profit'       => $this->toReporting((string) $php['gross_profit'], $average),
                'operating_expenses' => $translateGroup($php['operating_expenses']),
                'net_income'         => $this->toReporting((string) $php['net_income'], $average),
            ],
        ];
    }

    /** @param array{code?: string, name?: string, amount: string} $line */
    private function translateLine(array $line, string $rate): array
    {
        return [
            'code'          => $line['code'] ?? null,
            'name'          => $line['name'] ?? '',
            'amount_php'    => (string) $line['amount'],
            'amount'        => $this->toReporting((string) $line['amount'], $rate),
            'rate_applied'  => $rate,
        ];
    }

    /** @param array<int, array{amount: string}> $lines */
    private function sumAmounts(array $lines): string
    {
        $sum = '0.00';
        foreach ($lines as $l) {
            $sum = Money::add($sum, (string) $l['amount']);
        }
        return $sum;
    }
}
